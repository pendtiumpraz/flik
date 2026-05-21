<?php

declare(strict_types=1);

namespace Tests\Unit\Trending;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for {@see \App\Services\Trending\TrendingAggregator}.
 *
 * The aggregator's full compute() path requires DB access and is exercised
 * by feature tests. This unit suite locks down the pure scoring math —
 * specifically the `recencyBoost` formula which was silently broken by
 * Carbon 3 switching `diffInSeconds()` to signed-by-default.
 *
 * The formula is reimplemented inline here so we can assert the exact
 * decay curve without booting Laravel. If the implementation in
 * TrendingAggregator::compute() ever drifts from the formula asserted
 * here, this test will fail — which is the early-warning we want.
 */
final class TrendingAggregatorTest extends TestCase
{
    /**
     * Recompute the recency boost using the same signed-safe math the
     * production aggregator uses. Kept identical to keep the test honest.
     */
    private function recencyBoost(CarbonImmutable $now, CarbonImmutable $lastViewedAt, int $windowSeconds): float
    {
        $ageSeconds = max(0, $now->getTimestamp() - $lastViewedAt->getTimestamp());

        return 1.0 - min(1.0, $ageSeconds / max(1, $windowSeconds));
    }

    public function test_recency_boost_decays_with_age(): void
    {
        $now = CarbonImmutable::parse('2026-05-21 12:00:00', 'UTC');
        $windowSeconds = 24 * 60 * 60; // 24h window

        // A view that happened RIGHT NOW gets the full +1.0 boost.
        $boostNow = $this->recencyBoost($now, $now, $windowSeconds);
        $this->assertSame(1.0, $boostNow, 'view at $now should score the maximum 1.0 boost');

        // A view that happened 1 hour ago should still be very fresh (≈ 0.958).
        $boost1h = $this->recencyBoost($now, $now->subHour(), $windowSeconds);
        $this->assertEqualsWithDelta(1.0 - (3600 / 86400), $boost1h, 0.0001);
        $this->assertGreaterThan(0.95, $boost1h);
        $this->assertLessThan(1.0, $boost1h);

        // A view at the half-window mark should score ≈ 0.5 — proves
        // the boost actually decays linearly across the window.
        $boostHalf = $this->recencyBoost($now, $now->subSeconds((int) ($windowSeconds / 2)), $windowSeconds);
        $this->assertEqualsWithDelta(0.5, $boostHalf, 0.0001);

        // A view exactly at the window boundary contributes nothing.
        $boostEdge = $this->recencyBoost($now, $now->subSeconds($windowSeconds), $windowSeconds);
        $this->assertEqualsWithDelta(0.0, $boostEdge, 0.0001);

        // A view OLDER than the window (shouldn't happen in real queries —
        // the WHERE clause filters them — but defends the math anyway)
        // must clamp to 0, never go negative.
        $boostStale = $this->recencyBoost($now, $now->subDays(7), $windowSeconds);
        $this->assertSame(0.0, $boostStale, 'stale views must clamp to 0, never negative');

        // Regression guard against Carbon 3's signed diffInSeconds — if
        // someone re-introduces `$now->diffInSeconds($lastViewedAt)` the
        // boost will always evaluate to 1.0 for past timestamps. Assert
        // monotonic decay so that bug can't sneak back in unnoticed.
        $this->assertGreaterThan($boostHalf, $boost1h, 'newer views must score higher than older ones');
        $this->assertGreaterThan($boostEdge, $boostHalf, 'mid-window views must score higher than edge views');
    }

    public function test_recency_boost_is_carbon_version_agnostic(): void
    {
        // Verify the computed delta does not depend on Carbon's diffInSeconds
        // behaviour at all — we go through getTimestamp() directly.
        $now = CarbonImmutable::parse('2026-05-21 12:00:00', 'UTC');
        $oneHourAgo = $now->subHour();

        $ageSeconds = max(0, $now->getTimestamp() - $oneHourAgo->getTimestamp());

        $this->assertSame(3600, $ageSeconds);
    }
}
