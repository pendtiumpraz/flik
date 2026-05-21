<?php

declare(strict_types=1);

namespace App\Services\Ai\Tasks;

use App\Models\Cast;
use App\Models\Movie;
use App\Models\MovieSceneActor;
use App\Services\Ai\AiClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SceneActorExtractor — X-Ray (J1) hotspot data writer.
 *
 * Solves audit-06 finding F-1: `movie_scene_actors` had zero writers, so
 * the player polled `/api/xray/{movie}?t=…` every 5 s for the whole runtime
 * of every film and always got back `{"actors":[]}`.
 *
 * Strategy is heuristic-first so it produces *plausible* X-Ray rows from
 * cast pivot data alone — no face detection needed:
 *
 *   1. Divide movie duration into SEGMENT_COUNT (8–12) equal time windows.
 *      Duration source priority: `duration_seconds` → runtime_minutes×60 →
 *      DEFAULT_DURATION_SECONDS fallback.
 *   2. Walk the top-billed cast (cast_movie ordered by `order` ASC, then id
 *      to stay deterministic), assigning 1–3 actors to each segment in
 *      a round-robin so headliners reappear across the runtime and minor
 *      players surface briefly. This mimics how real screen-time looks.
 *   3. Optional AI enhancement: when an active AI provider is configured
 *      AND the movie has a synopsis + small cast, ask the model to refine
 *      the assignment (e.g. "Actor A appears in segments 1–4, Actor B
 *      cameos in segment 8"). Failures fall back silently to the
 *      heuristic — no exception ever escapes the extractor.
 *
 * Idempotency: existing `movie_scene_actors` rows for the target movie are
 * deleted FIRST inside a transaction, then the fresh batch is inserted.
 * Re-running this on the same movie produces a single coherent dataset.
 *
 * @see \App\Models\MovieSceneActor
 * @see \App\Http\Controllers\XrayController
 */
class SceneActorExtractor
{
    /** Minimum number of time segments per movie. */
    public const MIN_SEGMENTS = 8;

    /** Maximum number of time segments per movie. */
    public const MAX_SEGMENTS = 12;

    /** Max cast members per segment (top-billed bias). */
    public const ACTORS_PER_SEGMENT_MAX = 3;

    /** Fallback movie duration when neither column is populated (90 min). */
    public const DEFAULT_DURATION_SECONDS = 5400;

    /**
     * Heuristic-only confidence score. 0.50 communicates "plausible, not
     * face-detected" — the reader (XrayController) can colour-code or hide
     * low-confidence hotspots later.
     */
    public const HEURISTIC_CONFIDENCE = 0.50;

    /**
     * Confidence used when AI refinement succeeded (still under 1.00 since
     * the underlying signal is synopsis text, not actual scene analysis).
     */
    public const AI_REFINED_CONFIDENCE = 0.65;

    public function __construct(
        protected AiClient $ai,
    ) {
    }

    /**
     * Build + persist the scene-actor map for the given movie.
     *
     * @return Collection<int, MovieSceneActor> Freshly persisted rows (may be empty if movie has no cast).
     */
    public function extract(Movie $movie): Collection
    {
        // Eager-load top-billed cast with pivot data so the heuristic has
        // ordered (character, order) tuples to walk.
        $movie->loadMissing('castMembers');
        $cast = $movie->castMembers;

        if ($cast->isEmpty()) {
            Log::info('SceneActorExtractor: movie has no cast — skipping', [
                'movie_id' => $movie->id,
            ]);

            // Clear any stale rows for the movie so we don't leave orphan
            // hotspots after a cast wipe.
            MovieSceneActor::where('movie_id', $movie->id)->delete();

            return collect();
        }

        $duration = $this->resolveDurationSeconds($movie);
        $segments = $this->buildSegments($duration, $cast->count());

        $assignments = $this->assignCastToSegments($cast, $segments);

        // Try to refine via AI when conditions are favourable. Any failure
        // collapses back to the heuristic without raising.
        $confidence = self::HEURISTIC_CONFIDENCE;
        $refined = $this->maybeRefineWithAi($movie, $cast, $segments);
        if ($refined !== null) {
            $assignments = $refined;
            $confidence = self::AI_REFINED_CONFIDENCE;
        }

        $now = now();
        $rows = [];

        foreach ($assignments as $segIndex => $castIds) {
            [$startSec, $endSec] = $segments[$segIndex];

            foreach ($castIds as $castId) {
                $rows[] = [
                    'movie_id'      => $movie->id,
                    'cast_id'       => $castId,
                    'start_seconds' => $startSec,
                    'end_seconds'   => $endSec,
                    'screen_x'      => null,
                    'screen_y'      => null,
                    'confidence'    => $confidence,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ];
            }
        }

        if ($rows === []) {
            return collect();
        }

        DB::transaction(function () use ($movie, $rows): void {
            MovieSceneActor::where('movie_id', $movie->id)->delete();
            // Chunk insert so very long movies with very large casts don't
            // hit the SQL placeholder ceiling.
            foreach (array_chunk($rows, 200) as $chunk) {
                MovieSceneActor::insert($chunk);
            }
        });

        return MovieSceneActor::where('movie_id', $movie->id)
            ->orderBy('start_seconds')
            ->get();
    }

    /**
     * Generate hotspots for a movie in-memory WITHOUT writing to the DB.
     * Used by XrayController's on-the-fly fallback when a movie has cast
     * but no annotations yet — keeps the response payload identical to a
     * persisted lookup so the JS doesn't need to know the difference.
     *
     * @return Collection<int, MovieSceneActor> Unsaved Eloquent instances.
     */
    public function generateHeuristicInMemory(Movie $movie): Collection
    {
        $movie->loadMissing('castMembers');
        $cast = $movie->castMembers;

        if ($cast->isEmpty()) {
            return collect();
        }

        $duration = $this->resolveDurationSeconds($movie);
        $segments = $this->buildSegments($duration, $cast->count());
        $assignments = $this->assignCastToSegments($cast, $segments);

        $out = collect();
        foreach ($assignments as $segIndex => $castIds) {
            [$startSec, $endSec] = $segments[$segIndex];

            foreach ($castIds as $castId) {
                $row = new MovieSceneActor();
                $row->movie_id      = $movie->id;
                $row->cast_id       = $castId;
                $row->start_seconds = (string) $startSec;
                $row->end_seconds   = (string) $endSec;
                $row->confidence    = (string) self::HEURISTIC_CONFIDENCE;
                $out->push($row);
            }
        }

        return $out;
    }

    /**
     * Resolve playable duration. Returns at least 1 so divisions don't
     * blow up on garbage data.
     */
    protected function resolveDurationSeconds(Movie $movie): int
    {
        $raw = $movie->getAttribute('duration_seconds');
        if (is_numeric($raw) && (int) $raw > 0) {
            return (int) $raw;
        }

        $minutes = $movie->getAttribute('runtime_minutes');
        if (is_numeric($minutes) && (int) $minutes > 0) {
            return (int) $minutes * 60;
        }

        return self::DEFAULT_DURATION_SECONDS;
    }

    /**
     * Build $count time windows over the duration. Returns a list of
     * [startSec, endSec] tuples.
     *
     * @return list<array{0:int,1:int}>
     */
    protected function buildSegments(int $durationSeconds, int $castSize): array
    {
        // Aim for ~1 segment per top-billed actor, capped to the policy range.
        $count = max(self::MIN_SEGMENTS, min(self::MAX_SEGMENTS, $castSize));

        $segmentLen = (int) max(1, floor($durationSeconds / $count));

        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $start = $i * $segmentLen;
            // Last segment swallows any rounding remainder so we don't
            // leave a sliver of unrepresented runtime at the end.
            $end = ($i === $count - 1) ? $durationSeconds : ($start + $segmentLen);
            $out[] = [$start, $end];
        }

        return $out;
    }

    /**
     * Round-robin top-billed cast across segments. Headliners (lower
     * `order` value) reappear across the movie; supporting cast surface
     * briefly. Each segment gets 1–ACTORS_PER_SEGMENT_MAX actors.
     *
     * @param  Collection<int, Cast>  $cast    Ordered by pivot.order ASC.
     * @param  list<array{0:int,1:int}>  $segments
     * @return array<int, list<int>>           segIndex => list of cast IDs
     */
    protected function assignCastToSegments(Collection $cast, array $segments): array
    {
        $segCount = count($segments);
        $castIds = $cast->pluck('id')->all();
        $totalCast = count($castIds);

        if ($segCount === 0 || $totalCast === 0) {
            return [];
        }

        // Top-billed bias: take more actors per segment when the catalog
        // is rich, fewer when only 1–2 actors are listed.
        $perSegment = (int) max(1, min(self::ACTORS_PER_SEGMENT_MAX, ceil($totalCast / max(1, $segCount / 2))));

        $assignments = [];

        // Sliding cursor so each segment picks the next $perSegment cast
        // IDs from the ordered list, wrapping around at the end.
        $cursor = 0;
        for ($seg = 0; $seg < $segCount; $seg++) {
            $bucket = [];
            for ($n = 0; $n < $perSegment; $n++) {
                $bucket[] = $castIds[$cursor % $totalCast];
                $cursor++;
            }
            // Dedup within the segment (small casts wrap inside one window).
            $assignments[$seg] = array_values(array_unique($bucket));
        }

        return $assignments;
    }

    /**
     * Optional AI refinement: ask the model to redistribute the cast across
     * the existing segment skeleton based on synopsis cues. Returns null on
     * any failure (no provider, parse error, missing data) — caller MUST
     * fall through to the heuristic assignment in that case.
     *
     * @param  Collection<int, Cast>  $cast
     * @param  list<array{0:int,1:int}>  $segments
     * @return array<int, list<int>>|null
     */
    protected function maybeRefineWithAi(Movie $movie, Collection $cast, array $segments): ?array
    {
        // Skip when there's nothing useful to anchor against.
        $synopsis = trim((string) ($movie->ai_synopsis ?? $movie->overview ?? ''));
        if ($synopsis === '' || $cast->count() < 2 || count($segments) < 2) {
            return null;
        }

        // Build the menu the model picks from.
        $castMenu = [];
        foreach ($cast as $member) {
            $character = $member->pivot->character ?? null;
            $castMenu[] = [
                'id'        => $member->id,
                'name'      => $member->name,
                'character' => $character,
                'order'     => $member->pivot->order ?? null,
            ];
        }

        $segmentMenu = [];
        foreach ($segments as $i => [$start, $end]) {
            $segmentMenu[] = [
                'segment' => $i,
                'start'   => $start,
                'end'     => $end,
            ];
        }

        $system = 'You are a film cast-screen-time estimator. Given a synopsis, a cast list, and a list of time '
            . 'segments, distribute actors across segments based on plausibility (lead actors appear in many '
            . 'segments, supporting players in a few). Output WAJIB strict JSON tanpa markdown fence. Schema: '
            . '{"assignments":{"<segment_index>":[<cast_id>,<cast_id>,...]}}. Return assignments for EVERY '
            . 'segment index given. Each segment must have 1-3 cast IDs (use IDs only, not names). Never invent '
            . 'cast IDs not present in the cast list.';

        $user = sprintf(
            "SYNOPSIS:\n%s\n\nCAST:\n%s\n\nSEGMENTS:\n%s\n\nTask: Return JSON mapping every segment index to 1-3 cast_ids.",
            mb_substr($synopsis, 0, 1200),
            json_encode($castMenu, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($segmentMenu, JSON_UNESCAPED_SLASHES),
        );

        try {
            $response = $this->ai->chat(
                [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user',   'content' => $user],
                ],
                [
                    'max_tokens'  => 800,
                    'temperature' => 0.2,
                ],
                'xray.extract',
                $movie,
            );
        } catch (\Throwable $e) {
            Log::info('SceneActorExtractor: AI refinement skipped', [
                'movie_id' => $movie->id,
                'error'    => $e->getMessage(),
            ]);
            return null;
        }

        $parsed = $this->parseAssignmentJson($response['content'] ?? '', $cast->pluck('id')->all(), count($segments));
        if ($parsed === null || $parsed === []) {
            return null;
        }

        return $parsed;
    }

    /**
     * Parse the AI JSON response into a sanitised {segment => [cast_ids]} map.
     * Returns null if the payload is malformed; returns an empty array if
     * the payload is valid but contained zero usable rows.
     *
     * @param  list<int>  $validCastIds
     * @return array<int, list<int>>|null
     */
    protected function parseAssignmentJson(string $raw, array $validCastIds, int $segmentCount): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        // Strip code fences if the model ignored the no-markdown rule.
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw) ?? $raw;
        $raw = preg_replace('/\s*```$/', '', $raw) ?? $raw;

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['assignments']) || !is_array($decoded['assignments'])) {
            return null;
        }

        $validIdSet = array_flip(array_map('intval', $validCastIds));
        $out = [];

        foreach ($decoded['assignments'] as $segKey => $ids) {
            $seg = (int) $segKey;
            if ($seg < 0 || $seg >= $segmentCount) {
                continue;
            }
            if (!is_array($ids)) {
                continue;
            }

            $clean = [];
            foreach ($ids as $id) {
                if (!is_numeric($id)) continue;
                $intId = (int) $id;
                if (!isset($validIdSet[$intId])) continue;
                $clean[] = $intId;
                if (count($clean) >= self::ACTORS_PER_SEGMENT_MAX) {
                    break;
                }
            }

            if ($clean !== []) {
                $out[$seg] = array_values(array_unique($clean));
            }
        }

        return $out;
    }
}
