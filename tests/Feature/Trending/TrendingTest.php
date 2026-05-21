<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| TrendingTest (FIX #10)
|--------------------------------------------------------------------------
|
| Verifies the TrendingAggregator end-to-end:
|   - Seeds movie_views rows in the 1h window
|   - Calls compute('1h')
|   - Asserts trending_movies rows are written with non-zero scores and
|     correctly ranked
|
| Also exercises the Carbon-3 recency-boost regression (commit c7c10b1)
| — a film with all views concentrated in the last few seconds MUST
| outscore one with the same view count from the window's edge.
|
| Skips when DB is unavailable or the trending tables aren't migrated.
|
*/

use App\Models\Movie;
use App\Models\MovieView;
use App\Models\TrendingMovie;
use App\Models\User;
use App\Services\Trending\TrendingAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    try {
        \DB::connection()->getPdo();
    } catch (\Throwable $e) {
        $this->markTestSkipped('Database not available: '.$e->getMessage());
    }

    if (! Schema::hasTable('movie_views') || ! Schema::hasTable('trending_movies')) {
        $this->markTestSkipped('Trending tables not migrated.');
    }
});

// ── Aggregator computes a non-empty cache ──────────────────────────────

test('trending aggregator computes scores and writes to cache', function () {
    $movieA = Movie::create(['title' => 'Hot Movie A', 'overview' => '', 'is_popular' => false]);
    $movieB = Movie::create(['title' => 'Hot Movie B', 'overview' => '', 'is_popular' => false]);

    $viewer = User::factory()->create();

    // Movie A → 3 views, all in the last minute (huge recency boost)
    foreach (range(1, 3) as $i) {
        MovieView::forceCreate([
            'movie_id'  => $movieA->id,
            'user_id'   => $viewer->id,
            'viewed_at' => now()->subSeconds(30 + $i),
        ]);
    }

    // Movie B → 3 views, near the 1h window edge (recency boost ~ 0)
    foreach (range(1, 3) as $i) {
        MovieView::forceCreate([
            'movie_id'  => $movieB->id,
            'user_id'   => $viewer->id,
            'viewed_at' => now()->subMinutes(58 + ($i / 10)),
        ]);
    }

    /** @var TrendingAggregator $agg */
    $agg = app(TrendingAggregator::class);
    $agg->compute('1h');

    // Cache table now holds two ranked rows for the 1h window.
    $rows = TrendingMovie::where('window', '1h')->orderBy('rank')->get();
    expect($rows)->toHaveCount(2);

    // Both movies should appear with positive scores.
    expect($rows->pluck('score')->every(fn ($s) => $s > 0))->toBeTrue();

    // Movie A (recent activity) must outscore Movie B (window edge).
    // This guards the Carbon-3 recency-boost regression fix in
    // TrendingAggregator (commit c7c10b1).
    $rankedById = $rows->keyBy('movie_id');
    expect((float) $rankedById[$movieA->id]->score)
        ->toBeGreaterThan((float) $rankedById[$movieB->id]->score);

    // Movie A should be rank 1.
    expect((int) $rankedById[$movieA->id]->rank)->toBe(1);
});
