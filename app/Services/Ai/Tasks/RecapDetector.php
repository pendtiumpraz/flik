<?php

declare(strict_types=1);

namespace App\Services\Ai\Tasks;

use App\Models\Movie;
use App\Models\MovieSubtitle;
use App\Services\Ai\Subtitle\WebVttHelper;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Detect the end-of-recap timestamp for a TV-series episode.
 *
 * Recap is the "previously on …" segment that appears at the start of an
 * episode and replays prior beats. We only look for it when the title clearly
 * looks like an episode (contains the word "Episode" or matches "S\d+E\d+").
 *
 * Heuristic:
 *   1. Confirm the movie smells like an episode (title pattern check).
 *   2. Scan the FIRST 60 seconds of subtitle cues for a "previously on …"
 *      style marker (also handles localised variants — "sebelumnya di …",
 *      "sebelumnya dalam …", "anteriormente en …", etc.).
 *   3. If found, recap_end = start time of the FIRST cue AFTER the marker
 *      whose start is ≥ marker_start + 5s. That gives the player a clean
 *      "skip recap" target that lands right where the episode-proper begins.
 *
 * If any step fails we return null and leave `recap_end_seconds` untouched —
 * a movie or non-recap episode shouldn't grow a stray skip-recap button.
 */
class RecapDetector
{
    /**
     * Maximum seconds of run-time we'll search for a recap marker. Recaps
     * realistically end well within a minute; searching further risks false
     * positives on dialog like "Previously, I told her …" inside the show.
     */
    protected const SEARCH_WINDOW_SECONDS = 60.0;

    /**
     * Minimum gap between the marker cue and the cue we treat as
     * "story resumes here". Keeps us from snapping to a continuation
     * line of the marker itself.
     */
    protected const POST_MARKER_GAP_SECONDS = 5.0;

    /**
     * Localised "previously on …" patterns (case-insensitive, regex).
     * Anchored to start of cue text after stripping leading whitespace
     * to avoid matching mid-sentence "previously" usages.
     */
    protected const RECAP_PATTERNS = [
        '/^previously\s+on\b/iu',
        '/^previously\b/iu',
        '/^last\s+time\s+on\b/iu',
        '/^sebelumnya\s+(di|dalam|pada)\b/iu',
        '/^sebelumnya\b/iu',
        '/^anteriormente\s+(en|em)\b/iu',
        '/^précédemment\b/iu',
        '/^précédemment\s+dans\b/iu',
        '/^was\s+bisher\s+geschah\b/iu',
        '/^в\s+предыдущей\s+серии\b/iu',
    ];

    /**
     * Title patterns that indicate this is a TV episode rather than a film.
     */
    protected const EPISODE_TITLE_PATTERNS = [
        '/\bEpisode\b/i',
        '/\bS\d+E\d+\b/i',
        '/\bSeason\s+\d+/i',
    ];

    public function __construct(
        protected WebVttHelper $vtt,
    ) {}

    /**
     * Run detection. Persists `recap_end_seconds` on the Movie when found.
     *
     * Returns the recap-end value in seconds, or null if no recap was
     * detected (or the movie isn't an episode).
     */
    public function detect(Movie $movie): ?float
    {
        if (! $this->looksLikeEpisode($movie)) {
            return null;
        }

        $cues = $this->loadCues($movie);
        if ($cues === null || $cues === []) {
            return null;
        }

        $markerStart = null;
        foreach ($cues as $cue) {
            $startSec = $this->vtt->timestampToSeconds($cue['start']);
            if ($startSec > self::SEARCH_WINDOW_SECONDS) {
                break;
            }
            if ($this->matchesRecapPattern($cue['text'])) {
                $markerStart = $startSec;
                break;
            }
        }

        if ($markerStart === null) {
            return null;
        }

        // Find the next cue at least POST_MARKER_GAP_SECONDS later — that's
        // where the episode-proper begins (and where "Skip Recap" should jump).
        $threshold = $markerStart + self::POST_MARKER_GAP_SECONDS;
        $recapEnd = null;
        foreach ($cues as $cue) {
            $startSec = $this->vtt->timestampToSeconds($cue['start']);
            if ($startSec >= $threshold) {
                $recapEnd = $startSec;
                break;
            }
        }

        // No follow-up cue at all → fall back to a conservative 30s past marker.
        if ($recapEnd === null) {
            $recapEnd = $markerStart + 30.0;
        }

        $movie->forceFill(['recap_end_seconds' => $recapEnd])->save();

        Log::info('RecapDetector: recap detected', [
            'movie_id' => $movie->id,
            'marker_start' => $markerStart,
            'recap_end' => $recapEnd,
        ]);

        return $recapEnd;
    }

    /**
     * Title-based gate so we don't waste cycles on theatrical features.
     */
    protected function looksLikeEpisode(Movie $movie): bool
    {
        $title = (string) ($movie->title ?? '');
        if ($title === '') {
            return false;
        }

        foreach (self::EPISODE_TITLE_PATTERNS as $pattern) {
            if (preg_match($pattern, $title) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Match a cue's first non-empty line against any localised recap marker.
     */
    protected function matchesRecapPattern(string $text): bool
    {
        $clean = trim($text);
        if ($clean === '') {
            return false;
        }

        // Test the first line — recap markers are top-of-cue, not embedded.
        $firstLine = trim(strtok($clean, "\n") ?: $clean);

        foreach (self::RECAP_PATTERNS as $pattern) {
            if (preg_match($pattern, $firstLine) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pull the default subtitle and parse it. Mirrors IntroOutroDetector's
     * helper — kept local to keep each task self-contained.
     *
     * @return array<int, array{index:int|string, start:string, end:string, text:string}>|null
     */
    protected function loadCues(Movie $movie): ?array
    {
        /** @var MovieSubtitle|null $subtitle */
        $subtitle = MovieSubtitle::query()
            ->where('movie_id', $movie->id)
            ->where('status', 'ready')
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();

        if ($subtitle === null) {
            return null;
        }

        try {
            $raw = Storage::disk($subtitle->disk)->get($subtitle->webvtt_path);
        } catch (\Throwable $e) {
            Log::warning('RecapDetector: failed to read subtitle', [
                'movie_id' => $movie->id,
                'subtitle_id' => $subtitle->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return $this->vtt->parse($raw);
    }
}
