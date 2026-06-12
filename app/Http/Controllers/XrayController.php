<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use App\Models\MovieSceneActor;
use App\Services\Ai\Tasks\SceneActorExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * X-Ray (O14) — Netflix-style "who's on screen right now?" overlay backend.
 *
 * The frontend (resources/js/player/xray-overlay.js) polls this endpoint every
 * few seconds with the current playback time and renders clickable hotspots
 * for any cast members visible in that window.
 *
 * Data sources, in priority order:
 *   1. Persisted MovieSceneActor rows (written by SceneActorExtractor via
 *      the weekly `flik:ai:scene-actors` cron, or on-demand via the admin
 *      command).
 *   2. On-the-fly heuristic fallback — generated in-memory from the cast
 *      pivot when the movie has cast but no persisted rows yet. Cached
 *      per-movie for 1 hour so we don't recompute on every poll tick.
 *   3. Empty payload when neither is available (movie has no cast either).
 */
class XrayController extends Controller
{
    /**
     * In-memory fallback cache TTL (seconds). Long enough to amortise the
     * cost over a viewing session, short enough that adding cast data
     * surfaces within an hour without a cache flush.
     */
    public const FALLBACK_CACHE_TTL_SECONDS = 3600;

    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * GET /api/xray/{movie}?t={seconds}
     *
     * Returns actors visible at the given playback second, with bio excerpts
     * and on-screen hotspot coordinates (if known).
     */
    public function forMovie(Movie $movie, Request $request, SceneActorExtractor $extractor): JsonResponse
    {
        $second = (float) $request->query('t', '0');
        if ($second < 0) {
            $second = 0.0;
        }

        $presences = MovieSceneActor::query()
            ->with('cast')
            ->where('movie_id', $movie->id)
            ->activeAt($second)
            ->orderByDesc('confidence')
            ->get();

        // FALLBACK: when the movie has zero persisted rows AND has cast,
        // generate a heuristic overlay in memory so the player isn't stuck
        // polling for nothing. Cache per-movie so we only compute once an
        // hour even with the 5s poll cadence.
        if ($presences->isEmpty()) {
            $needsFallback = ! MovieSceneActor::where('movie_id', $movie->id)->exists();

            if ($needsFallback) {
                $generated = $this->loadFallbackPresences($movie, $extractor);

                $presences = $generated->filter(
                    fn (MovieSceneActor $row) => (float) $row->start_seconds <= $second
                        && (float) $row->end_seconds >= $second
                )->values();

                // Eager-load cast onto the in-memory instances so the
                // mapping below still works without persistence.
                $presences->each(function (MovieSceneActor $row): void {
                    if ($row->relationLoaded('cast')) {
                        return;
                    }
                    $row->setRelation('cast', \App\Models\Cast::find($row->cast_id));
                });
            }
        }

        // Map presence rows → flat actor payload. We pull the `character` from
        // the cast_movie pivot so the overlay can show "Tony Stark" not just "RDJ".
        $characterByCastId = $movie->castMembers()
            ->whereIn('casts.id', $presences->pluck('cast_id')->all())
            ->get()
            ->mapWithKeys(fn ($cast) => [$cast->id => $cast->pivot->character ?? null])
            ->all();

        $actors = $presences
            ->filter(fn ($p) => $p->cast !== null)
            ->map(function (MovieSceneActor $p) use ($characterByCastId) {
                $cast = $p->cast;

                return [
                    'id' => $cast->id,
                    'name' => $cast->name,
                    'character' => $characterByCastId[$cast->id] ?? null,
                    'bio_excerpt' => $this->excerpt($cast->bio),
                    'photo_url' => $this->photoUrl($cast->profile_path),
                    'screen_x' => $p->screen_x !== null ? (float) $p->screen_x : null,
                    'screen_y' => $p->screen_y !== null ? (float) $p->screen_y : null,
                ];
            })
            ->values();

        return response()->json([
            'actors' => $actors,
        ]);
    }

    /**
     * Trim the bio down to a tooltip-sized excerpt (~180 chars on word boundary).
     */
    protected function excerpt(?string $bio): ?string
    {
        if ($bio === null) {
            return null;
        }

        $bio = trim($bio);
        if ($bio === '') {
            return null;
        }

        if (mb_strlen($bio) <= 180) {
            return $bio;
        }

        $cut = mb_substr($bio, 0, 180);
        $lastSpace = mb_strrpos($cut, ' ');

        return ($lastSpace !== false ? mb_substr($cut, 0, $lastSpace) : $cut).'…';
    }

    /**
     * Resolve photo URL — accepts either a full http(s) URL or a storage path.
     */
    protected function photoUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return \App\Support\MediaDisk::url($path);
    }

    /**
     * Build (or fetch from cache) the heuristic-only presence list for a
     * movie that has cast but no persisted MovieSceneActor rows.
     *
     * Cached as an array of [cast_id, start_seconds, end_seconds] tuples
     * so the serialised form is small + driver-agnostic.
     *
     * @return \Illuminate\Support\Collection<int, MovieSceneActor>
     */
    protected function loadFallbackPresences(Movie $movie, SceneActorExtractor $extractor): \Illuminate\Support\Collection
    {
        $cacheKey = 'xray:fallback:'.$movie->id;

        $tuples = Cache::remember(
            $cacheKey,
            self::FALLBACK_CACHE_TTL_SECONDS,
            function () use ($movie, $extractor): array {
                $generated = $extractor->generateHeuristicInMemory($movie);

                return $generated->map(fn (MovieSceneActor $row): array => [
                    'cast_id' => (int) $row->cast_id,
                    'start_seconds' => (float) $row->start_seconds,
                    'end_seconds' => (float) $row->end_seconds,
                    'confidence' => (float) $row->confidence,
                ])->all();
            }
        );

        // Re-hydrate to unsaved Eloquent instances so the same code path
        // works on both the persisted and the fallback branch.
        return collect($tuples)->map(function (array $t) use ($movie): MovieSceneActor {
            $row = new MovieSceneActor;
            $row->movie_id = $movie->id;
            $row->cast_id = $t['cast_id'];
            $row->start_seconds = (string) $t['start_seconds'];
            $row->end_seconds = (string) $t['end_seconds'];
            $row->confidence = (string) $t['confidence'];

            return $row;
        });
    }
}
