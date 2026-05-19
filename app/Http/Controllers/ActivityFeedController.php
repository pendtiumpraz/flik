<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\Follow;
use App\Models\Rating;
use App\Models\User;
use App\Models\Watchlist;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * /feed — the "what your follow graph is doing" stream.
 *
 * Pulled together at request time (no materialised feed table) because the
 * volume is small enough to be cheap and the freshness expectation is high.
 * Three query streams (ratings, comments, watchlist adds) are merged in
 * memory, then capped at 50 items sorted by `created_at` descending.
 */
class ActivityFeedController extends Controller
{
    /**
     * Window in days for "recent" activity. Anything older never appears
     * in the feed regardless of follow-graph topology.
     */
    private const WINDOW_DAYS = 7;

    /**
     * Total cap on items returned. The view doesn't paginate (this is a
     * curated firehose, not a timeline) — past the cap the user goes to
     * the relevant target's profile.
     */
    private const FEED_LIMIT = 50;

    public function index(): View
    {
        /** @var User $viewer */
        $viewer = auth()->user();

        // Defensive: empty feed when the social schema hasn't been migrated
        // yet (peer installs / fresh test DBs).
        if (! Schema::hasTable('follows')) {
            return view('feed.index', ['viewer' => $viewer, 'items' => collect()]);
        }

        $followedIds = $viewer->following()->pluck('users.id');

        if ($followedIds->isEmpty()) {
            return view('feed.index', ['viewer' => $viewer, 'items' => collect()]);
        }

        $since = Carbon::now()->subDays(self::WINDOW_DAYS);

        $ratings = Rating::query()
            ->whereIn('user_id', $followedIds)
            ->where('created_at', '>=', $since)
            ->with(['user:id,name,username,avatar_path', 'movie:id,title,slug,poster_path'])
            ->latest()
            ->limit(self::FEED_LIMIT)
            ->get()
            ->map(fn (Rating $r) => [
                'type' => 'rating',
                'created_at' => $r->created_at,
                'user' => $r->user,
                'movie' => $r->movie,
                'meta' => ['score' => $r->score, 'review' => $r->review],
            ]);

        $comments = Comment::query()
            ->whereIn('user_id', $followedIds)
            ->where('created_at', '>=', $since)
            ->with(['user:id,name,username,avatar_path', 'movie:id,title,slug,poster_path'])
            ->latest()
            ->limit(self::FEED_LIMIT)
            ->get()
            ->map(fn (Comment $c) => [
                'type' => 'comment',
                'created_at' => $c->created_at,
                'user' => $c->user,
                'movie' => $c->movie,
                'meta' => ['body' => $c->body],
            ]);

        $watchlist = Watchlist::query()
            ->whereIn('user_id', $followedIds)
            ->where('created_at', '>=', $since)
            ->with(['user:id,name,username,avatar_path', 'movie:id,title,slug,poster_path'])
            ->latest()
            ->limit(self::FEED_LIMIT)
            ->get()
            ->map(fn (Watchlist $w) => [
                'type' => 'watchlist',
                'created_at' => $w->created_at,
                'user' => $w->user,
                'movie' => $w->movie,
                'meta' => [],
            ]);

        $items = $ratings
            ->concat($comments)
            ->concat($watchlist)
            ->sortByDesc('created_at')
            ->values()
            ->take(self::FEED_LIMIT);

        return view('feed.index', [
            'viewer' => $viewer,
            'items' => $items,
        ]);
    }
}
