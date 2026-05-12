<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Ai\Recommendations\RecommendationEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Nightly batch: recompute recommendations for every user
 * who has been active in the last 30 days.
 *
 * Dispatched via `php artisan flik:recommendations:recompute` and
 * intended to run on a queue worker listening to `ai-batch`.
 */
class BatchRecomputeRecommendations implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Limit total users per run as a safety net. */
    public const MAX_USERS_PER_RUN = 5000;

    /** Active = activity in last N days. */
    public const ACTIVITY_WINDOW_DAYS = 30;

    /** How many items per user. */
    public int $perUserCount;

    /** Optional override — only recompute for these user IDs. */
    public ?array $onlyUserIds;

    public int $timeout = 3600;   // 1h per chunk (engine is fast but AI calls can stall)
    public int $tries = 1;        // job is idempotent per-user; don't auto-retry the batch

    public function __construct(int $perUserCount = 20, ?array $onlyUserIds = null)
    {
        $this->perUserCount = $perUserCount;
        $this->onlyUserIds = $onlyUserIds;
        $this->onQueue('ai-batch');
    }

    public function handle(RecommendationEngine $engine): void
    {
        $started = microtime(true);
        $processed = 0;
        $failed = 0;

        $this->activeUsersQuery()->chunkById(200, function ($users) use ($engine, &$processed, &$failed) {
            foreach ($users as $user) {
                try {
                    $engine->computeFor($user, $this->perUserCount);
                    $processed++;
                } catch (\Throwable $e) {
                    $failed++;
                    Log::warning('BatchRecomputeRecommendations: user failed', [
                        'user_id' => $user->id,
                        'error'   => $e->getMessage(),
                    ]);
                }
            }
        });

        Log::info('BatchRecomputeRecommendations done', [
            'processed' => $processed,
            'failed'    => $failed,
            'seconds'   => round(microtime(true) - $started, 2),
        ]);
    }

    /**
     * Build the query for users we should recompute for.
     */
    protected function activeUsersQuery()
    {
        if (!empty($this->onlyUserIds)) {
            return User::whereIn('id', $this->onlyUserIds);
        }

        $since = now()->subDays(self::ACTIVITY_WINDOW_DAYS);

        // Active = any signal in window
        $activeIds = collect()
            ->merge(DB::table('watch_histories')->where('last_watched_at', '>=', $since)->pluck('user_id'))
            ->merge(DB::table('ratings')->where('updated_at', '>=', $since)->pluck('user_id'))
            ->merge(DB::table('watchlists')->where('updated_at', '>=', $since)->pluck('user_id'))
            ->unique()
            ->values()
            ->all();

        if (empty($activeIds)) {
            // Fallback: include recently-registered users so cold-start recs get populated
            return User::orderByDesc('created_at')->limit(self::MAX_USERS_PER_RUN);
        }

        return User::whereIn('id', $activeIds)->limit(self::MAX_USERS_PER_RUN);
    }
}
