<?php

namespace App\Services\Ai\Tasks;

use App\Models\ChurnPrediction;
use App\Models\Rating;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WatchHistory;
use App\Models\Watchlist;
use App\Services\Ai\AiClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * ChurnPredictor — heuristic-first churn risk scorer.
 *
 * The predictor is split into two phases:
 *
 *   1. Signals (pure heuristic, no AI)
 *      A small bag of features pulled straight off the relational model:
 *      days_since_last_watch, week-over-week watch decline, subscription
 *      lifecycle status, recent vs lifetime rating activity, and an
 *      "unused watchlist" ratio. Each signal contributes 0.0–1.0 to a
 *      weighted sum that becomes the user's risk_score.
 *
 *   2. AI suggestion (only for high+critical)
 *      For users that the heuristic flagged, we ask the configured AI
 *      provider for a single Indonesian-language win-back action
 *      ("Kirim email diskon 20% untuk film favoritnya"). The AI is
 *      strictly advisory — if it fails or no provider is configured we
 *      fall back to a deterministic template so the predictor never
 *      crashes.
 *
 * Persistence is updateOrCreate by user_id — one current snapshot per
 * user. History (if we ever need it) belongs in a separate cron-fed
 * table; not our problem here.
 *
 * Scope of "predict all": only users with a subscription record (paid or
 * formerly paid). Free-only users have nothing to churn from.
 */
class ChurnPredictor
{
    /**
     * Signal weights — must sum to 1.0 so the final score stays in [0, 1].
     */
    protected const WEIGHT_DAYS_SINCE_WATCH      = 0.30;
    protected const WEIGHT_FREQUENCY_DECLINE     = 0.25;
    protected const WEIGHT_SUBSCRIPTION_STATUS   = 0.20;
    protected const WEIGHT_RATING_DROP           = 0.10;
    protected const WEIGHT_UNUSED_WATCHLIST      = 0.15;

    /**
     * After this many days without a watch, the "days since last watch"
     * signal saturates at 1.0.
     */
    protected const DAYS_SINCE_WATCH_SATURATION = 30;

    /**
     * AI suggestion only triggers for users at or above this score.
     */
    protected const AI_SUGGESTION_THRESHOLD = 0.6; // i.e. high + critical

    public function __construct(
        protected ?AiClient $ai = null,
    ) {}

    // ──────────────────────────────────────────────────────────────────
    //  Public API
    // ──────────────────────────────────────────────────────────────────

    /**
     * Score a single user, persist the snapshot, return it.
     */
    public function predictForUser(User $user): ChurnPrediction
    {
        $signals = $this->computeSignals($user);
        $score   = $this->scoreFromSignals($signals);
        $level   = ChurnPrediction::levelFromScore($score);

        $suggestedAction = null;
        if ($score >= self::AI_SUGGESTION_THRESHOLD) {
            $suggestedAction = $this->suggestAction($user, $signals, $score)
                ?: $this->fallbackAction($signals);
        }

        // ChurnPrediction uses $guarded = ['*'] (mass-assignment audit,
        // 2026-05-13). Build the row through forceFill so the system-trusted
        // ChurnPredictor write isn't blocked by the guard.
        /** @var ChurnPrediction $prediction */
        $prediction = ChurnPrediction::firstOrNew(['user_id' => $user->id]);
        $prediction->forceFill([
            'user_id'          => $user->id,
            'risk_score'       => round($score, 3),
            'risk_level'       => $level,
            'signals'          => $signals,
            'suggested_action' => $suggestedAction,
            'computed_at'      => now(),
        ])->save();

        return $prediction;
    }

    /**
     * Iterate every user that has at least one subscription record (current
     * or historical) and re-score them. Returns the count actually predicted.
     */
    public function predictAll(): int
    {
        $count = 0;

        User::query()
            ->whereHas('subscriptions') // any plan, any status — captures churned users too
            ->chunkById(200, function ($users) use (&$count) {
                foreach ($users as $user) {
                    try {
                        $this->predictForUser($user);
                        $count++;
                    } catch (\Throwable $e) {
                        Log::warning('ChurnPredictor: prediction failed for user', [
                            'user_id' => $user->id,
                            'error'   => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $count;
    }

    // ──────────────────────────────────────────────────────────────────
    //  Signals (heuristic, no AI)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Compute the raw signal payload + per-signal sub-scores.
     *
     * Each `*_score` field is normalized to [0, 1] where 1.0 means
     * maximally bad (most churn-leaning).
     *
     * @return array<string, mixed>
     */
    public function computeSignals(User $user): array
    {
        $now = Carbon::now();

        // ── 1. Days since last watch ──────────────────────────────────
        $lastWatch = WatchHistory::where('user_id', $user->id)
            ->max('last_watched_at');

        if ($lastWatch === null) {
            // Never watched anything — treat as fully cold.
            $daysSinceLastWatch = null;
            $daysSinceScore     = 1.0;
        } else {
            $daysSinceLastWatch = (int) Carbon::parse($lastWatch)->diffInDays($now);
            $daysSinceScore     = min(1.0, $daysSinceLastWatch / self::DAYS_SINCE_WATCH_SATURATION);
        }

        // ── 2. Week-over-week watch frequency decline ────────────────
        $thisWeek = WatchHistory::where('user_id', $user->id)
            ->where('last_watched_at', '>=', $now->copy()->subDays(7))
            ->count();

        $lastWeek = WatchHistory::where('user_id', $user->id)
            ->whereBetween('last_watched_at', [
                $now->copy()->subDays(14),
                $now->copy()->subDays(7),
            ])
            ->count();

        $declineScore = $this->declineScore($thisWeek, $lastWeek);

        // ── 3. Subscription lifecycle ────────────────────────────────
        [$subStatusLabel, $subStatusScore, $expiresInDays] = $this->subscriptionSignal($user, $now);

        // ── 4. Rating activity drop (recent vs all-time) ─────────────
        $ratingsAllTime = Rating::where('user_id', $user->id)->count();
        $ratingsRecent  = Rating::where('user_id', $user->id)
            ->where('created_at', '>=', $now->copy()->subDays(30))
            ->count();
        $ratingDropScore = $this->ratingDropScore($ratingsRecent, $ratingsAllTime);

        // ── 5. Unused watchlist ratio ────────────────────────────────
        $watchlistTotal = Watchlist::where('user_id', $user->id)->count();
        $unusedCount    = $this->unusedWatchlistCount($user->id);
        $unusedRatio    = $watchlistTotal > 0 ? $unusedCount / $watchlistTotal : 0.0;
        $unusedScore    = min(1.0, $unusedRatio); // already 0..1

        return [
            'days_since_last_watch'        => $daysSinceLastWatch,
            'days_since_last_watch_score'  => round($daysSinceScore, 3),

            'watch_count_this_week'        => $thisWeek,
            'watch_count_last_week'        => $lastWeek,
            'watch_frequency_decline_score'=> round($declineScore, 3),

            'subscription_status'          => $subStatusLabel,
            'subscription_expires_in_days' => $expiresInDays,
            'subscription_status_score'    => round($subStatusScore, 3),

            'ratings_all_time'             => $ratingsAllTime,
            'ratings_last_30_days'         => $ratingsRecent,
            'rating_drop_score'            => round($ratingDropScore, 3),

            'watchlist_total'              => $watchlistTotal,
            'watchlist_unused_count'       => $unusedCount,
            'watchlist_unused_ratio'       => round($unusedRatio, 3),
            'unused_watchlist_score'       => round($unusedScore, 3),
        ];
    }

    /**
     * Combine sub-scores into a single risk_score in [0, 1].
     *
     * @param  array<string, mixed>  $signals
     */
    public function scoreFromSignals(array $signals): float
    {
        $score = self::WEIGHT_DAYS_SINCE_WATCH    * (float) ($signals['days_since_last_watch_score']   ?? 0)
               + self::WEIGHT_FREQUENCY_DECLINE   * (float) ($signals['watch_frequency_decline_score'] ?? 0)
               + self::WEIGHT_SUBSCRIPTION_STATUS * (float) ($signals['subscription_status_score']     ?? 0)
               + self::WEIGHT_RATING_DROP         * (float) ($signals['rating_drop_score']             ?? 0)
               + self::WEIGHT_UNUSED_WATCHLIST    * (float) ($signals['unused_watchlist_score']        ?? 0);

        return max(0.0, min(1.0, $score));
    }

    // ──────────────────────────────────────────────────────────────────
    //  Sub-signal helpers
    // ──────────────────────────────────────────────────────────────────

    /**
     * Watch-frequency decline week-over-week.
     *
     *   - last week 0 watches   → no signal (return 0.5 if also 0 this week — neutral cold,
     *                             they were already inactive and may have been "won back" or not)
     *   - this week >= last week → 0.0 (no decline)
     *   - else                   → 1 - (this/last)
     */
    protected function declineScore(int $thisWeek, int $lastWeek): float
    {
        if ($lastWeek === 0) {
            // Both weeks zero — they're inactive, but the "days since last watch"
            // signal will already capture that. Don't double-count here.
            return $thisWeek === 0 ? 0.5 : 0.0;
        }

        if ($thisWeek >= $lastWeek) {
            return 0.0;
        }

        return max(0.0, min(1.0, 1.0 - ($thisWeek / $lastWeek)));
    }

    /**
     * Inspect the user's subscription lifecycle and emit a label + score + expiry hint.
     *
     * Statuses we emit:
     *   - "active"           — currently active, more than 7 days remaining → 0.0
     *   - "active_expiring"  — currently active, ends_at within 7 days       → 0.6
     *   - "cancelled"        — cancelled but still within paid window        → 0.7
     *   - "expired"          — past ends_at, no active subscription          → 0.9
     *   - "never_subscribed" — has subscription record but all expired/free  → 0.5
     *   - "unknown"          — no subscription rows at all                   → 0.4
     *
     * @return array{0:string, 1:float, 2:?int}  [label, score, expiresInDays|null]
     */
    protected function subscriptionSignal(User $user, Carbon $now): array
    {
        // Most recent subscription row (any status) — gives us full lifecycle insight.
        $latest = Subscription::where('user_id', $user->id)
            ->latest('id')
            ->first();

        if (!$latest) {
            return ['unknown', 0.4, null];
        }

        $expiresInDays = null;
        if ($latest->ends_at) {
            // diffInDays is unsigned; manually sign it so "already expired" is negative.
            $expiresInDays = (int) round($now->diffInDays(Carbon::parse($latest->ends_at), false));
        }

        // Active and in good standing.
        if ($latest->status === 'active' && (!$latest->ends_at || Carbon::parse($latest->ends_at)->isFuture())) {
            if ($expiresInDays !== null && $expiresInDays >= 0 && $expiresInDays <= 7) {
                return ['active_expiring', 0.6, $expiresInDays];
            }
            return ['active', 0.0, $expiresInDays];
        }

        if ($latest->status === 'cancelled') {
            // Cancelled but still in the paid window — they've already pulled
            // the trigger. High signal.
            if ($latest->ends_at && Carbon::parse($latest->ends_at)->isFuture()) {
                return ['cancelled', 0.7, $expiresInDays];
            }
            return ['expired', 0.9, $expiresInDays];
        }

        if ($latest->status === 'expired' || ($latest->ends_at && Carbon::parse($latest->ends_at)->isPast())) {
            return ['expired', 0.9, $expiresInDays];
        }

        // Anything else (paused, pending, ...) — neutral mid signal.
        return [(string) $latest->status, 0.5, $expiresInDays];
    }

    /**
     * Sharp rating-activity drop = engagement falling.
     *
     *   - If they never rated anything, no signal (0.0).
     *   - If they rated < 5 times all-time, the sample is too small (0.2 — slight signal).
     *   - Else: 1 - (recent-30 / monthly average over their lifetime).
     *     Capped to [0, 1].
     */
    protected function ratingDropScore(int $recent, int $allTime): float
    {
        if ($allTime === 0) return 0.0;
        if ($allTime < 5)   return 0.2;

        // Monthly average — use account age in months as denominator, min 1.
        // We don't have created_at handy here, so approximate: assume one
        // rating represents "monthly cadence" if there's no recent burst.
        // Simpler: compare recent-30 to expected (allTime / max(1, all-time months)).
        // Without account_age available cheaply, use a pragmatic ratio:
        //   expected = max(1, allTime / 12)   (assume ~year history bucket)
        $expected = max(1.0, $allTime / 12.0);

        if ($recent >= $expected) {
            return 0.0;
        }

        return max(0.0, min(1.0, 1.0 - ($recent / $expected)));
    }

    /**
     * Count watchlist entries that the user added but never started watching.
     * "Started" = a WatchHistory row exists for that movie_id.
     */
    protected function unusedWatchlistCount(int $userId): int
    {
        return Watchlist::where('user_id', $userId)
            ->whereNotIn('movie_id', function ($q) use ($userId) {
                $q->select('movie_id')
                  ->from('watch_histories')
                  ->where('user_id', $userId);
            })
            ->count();
    }

    // ──────────────────────────────────────────────────────────────────
    //  AI suggestion (high + critical only)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Ask the configured AI provider for a personalized win-back action.
     * Returns null on any failure — caller falls back to a template.
     *
     * @param  array<string, mixed>  $signals
     */
    protected function suggestAction(User $user, array $signals, float $score): ?string
    {
        if (!$this->ai) {
            return null;
        }

        $systemPrompt = "You're a CRM specialist for Indonesian streaming platform FLiK. "
            . "Given a user's churn risk signals, propose ONE concrete, personalized win-back action. "
            . "Output exactly ONE sentence in Indonesian — no preamble, no quotes, no markdown. "
            . "Maximum 25 words. Be specific (mention discount %, free month, genre, etc.) when the signals support it.";

        $payload = [
            'name'       => $user->name,
            'risk_score' => round($score, 3),
            'signals'    => $this->signalsForPrompt($signals),
        ];

        $userPrompt = "User churn signals:\n"
            . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            . "\n\nSuggest one win-back action in Indonesian. One sentence. Max 25 words.";

        try {
            $response = $this->ai->chat([
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userPrompt],
            ], [
                'max_tokens'  => 120,
                'temperature' => 0.7,
            ]);

            $content = trim((string) ($response['content'] ?? ''));
            return $this->cleanSuggestion($content) ?: null;
        } catch (\Throwable $e) {
            Log::warning('ChurnPredictor: AI suggestion failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Sub-set of signals worth sending to the AI prompt — drop the raw
     * sub-scores (the AI doesn't need our internal weights).
     *
     * @param  array<string, mixed>  $signals
     * @return array<string, mixed>
     */
    protected function signalsForPrompt(array $signals): array
    {
        return [
            'days_since_last_watch'        => $signals['days_since_last_watch'] ?? null,
            'watch_count_this_week'        => $signals['watch_count_this_week'] ?? 0,
            'watch_count_last_week'        => $signals['watch_count_last_week'] ?? 0,
            'subscription_status'          => $signals['subscription_status'] ?? 'unknown',
            'subscription_expires_in_days' => $signals['subscription_expires_in_days'] ?? null,
            'ratings_last_30_days'         => $signals['ratings_last_30_days'] ?? 0,
            'ratings_all_time'             => $signals['ratings_all_time'] ?? 0,
            'watchlist_unused_count'       => $signals['watchlist_unused_count'] ?? 0,
            'watchlist_total'              => $signals['watchlist_total'] ?? 0,
        ];
    }

    /**
     * Strip markdown / quotes / leading labels and clamp to single line.
     */
    protected function cleanSuggestion(string $raw): string
    {
        if ($raw === '') return '';

        $raw = preg_replace('/^```[\w]*\s*|\s*```$/im', '', $raw) ?? $raw;
        $raw = preg_replace('/^(action|aksi|saran)\s*:\s*/i', '', trim($raw)) ?? $raw;
        $raw = trim($raw, " \t\n\r\0\x0B\"'`“”‘’");

        // Single line only — strip any subsequent text the model added.
        $first = strtok($raw, "\r\n");
        $line  = is_string($first) ? trim($first) : '';

        // Soft cap at ~200 chars in case the model ignored the word limit.
        if (mb_strlen($line) > 200) {
            $line = rtrim(mb_substr($line, 0, 199)) . '…';
        }

        return $line;
    }

    /**
     * Deterministic fallback when AI is unavailable. Picks a template based
     * on the dominant signal so the action is at least signal-aware.
     *
     * @param  array<string, mixed>  $signals
     */
    protected function fallbackAction(array $signals): string
    {
        $subStatus = (string) ($signals['subscription_status'] ?? 'unknown');
        $daysSince = $signals['days_since_last_watch'] ?? null;

        return match (true) {
            $subStatus === 'expired' || $subStatus === 'cancelled' =>
                'Kirim email penawaran reaktivasi dengan diskon 30% untuk berlangganan kembali.',

            $subStatus === 'active_expiring' =>
                'Kirim pengingat perpanjangan langganan dengan bonus satu bulan gratis.',

            is_int($daysSince) && $daysSince >= 21 =>
                'Kirim email win-back dengan rekomendasi film favorit dan kupon diskon 20%.',

            is_int($daysSince) && $daysSince >= 7 =>
                'Kirim notifikasi push dengan trailer film terbaru sesuai genre kesukaannya.',

            default =>
                'Kirim digest mingguan personalisasi untuk meningkatkan engagement.',
        };
    }
}
