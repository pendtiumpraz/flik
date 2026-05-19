<?php

namespace App\Http\Controllers;

use App\Models\Episode;
use App\Models\WatchHistory;
use Illuminate\View\View;

/**
 * Public per-episode player. Lives behind the `auth` middleware in
 * web.php because progress tracking + "next episode" only make sense
 * for an authenticated viewer.
 *
 * The companion JS (resources/views/episodes/watch.blade.php) is
 * expected to POST to /watch/progress with `movie_id` AND `episode_id`
 * so WatchHistoryController writes a per-episode row.
 */
class EpisodeWatchController extends Controller
{
    /**
     * Full-screen episode player view.
     *
     * @param  Episode  $episode  Route-model-bound by primary key.
     */
    public function show(Episode $episode): View
    {
        // Eager-load everything the view + auto-next overlay need so we
        // hit the DB once instead of N times during the render.
        $episode->load(['movie', 'season.episodes']);

        $next = $episode->movie?->nextEpisodeAfter($episode);

        $previous = $episode->previousInSeason();

        $resumeAt = 0;
        if (auth()->check()) {
            $history = WatchHistory::query()
                ->where('user_id', auth()->id())
                ->where('episode_id', $episode->id)
                ->first();
            // `current_time` is the column the WatchHistoryController
            // forceFills today; fall back to `progress_seconds` for the
            // older schema layout to stay robust during the migration
            // straddle.
            $resumeAt = (int) ($history?->current_time
                ?? $history?->progress_seconds
                ?? 0);
        }

        return view('episodes.watch', [
            'episode'  => $episode,
            'movie'    => $episode->movie,
            'season'   => $episode->season,
            'next'     => $next,
            'previous' => $previous,
            'resumeAt' => $resumeAt,
        ]);
    }
}
