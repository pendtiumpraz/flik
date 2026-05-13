<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use App\Models\AuditLog;
use App\Models\ChurnPrediction;
use App\Models\Coin;
use App\Models\Comment;
use App\Models\KnownDevice;
use App\Models\LoginAttempt;
use App\Models\MovieSchedule;
use App\Models\Notification;
use App\Models\QuizAttempt;
use App\Models\Rating;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserLevel;
use App\Models\UserPreference;
use App\Models\UserRecommendation;
use App\Models\WatchHistory;
use App\Models\Watchlist;
use App\Models\WatchPartyMember;
use App\Models\YearInReview;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * UserDataEraser
 * --------------------------------------------------------------------------
 * GDPR Article 17 (right to erasure / "right to be forgotten").
 *
 * Strategy is chosen per-table based on what's required for legitimate
 * downstream use vs. what is purely PII the user wants gone:
 *
 *   ANONYMISE (keep row, scrub identity):
 *     - comments     → keep body for thread integrity, set user_id NULL
 *     - subscriptions, payments → tax/audit retention; set user_id NULL,
 *                                 keep amounts + order ids
 *
 *   HARD DELETE:
 *     - watch_histories, watchlists, ratings
 *     - quiz_attempts, movie_schedules
 *     - login_attempts (matched by email)
 *     - known_devices, notifications, recommendations, year_in_reviews
 *     - audit_logs that are ABOUT this user
 *     - user row itself (last)
 *
 * Everything happens inside a single DB transaction. The audit row is
 * written FIRST so that if the transaction commits, we have a record;
 * if it rolls back, the audit row is rolled back with it.
 */
class UserDataEraser
{
    public function __construct(
        protected AuditLogger $audit,
    ) {
    }

    /**
     * Erase $user, returning a per-table summary of what happened.
     *
     * Counts are gathered before the deletes run so the summary reflects
     * actual rows touched even though the rows themselves are gone after
     * the transaction commits.
     *
     * @param  string  $reason  free-text reason supplied by the user
     * @return array{user_id:int, email:string, reason:string, deleted_at:string, summary:array<string,int>}
     */
    public function erase(User $user, string $reason): array
    {
        $userId = (int) $user->id;
        $email  = (string) $user->email;

        // Snapshot row counts BEFORE the transaction so the audit + return
        // value reports the truthful "what was wiped" picture even after
        // the cascading deletes have done their work.
        $summary = [
            'comments_anonymised'   => Comment::where('user_id', $userId)->count(),
            'subscriptions_scrubbed'=> Subscription::where('user_id', $userId)->count(),
            'watch_histories'       => WatchHistory::where('user_id', $userId)->count(),
            'watchlists'            => Watchlist::where('user_id', $userId)->count(),
            'ratings'               => Rating::where('user_id', $userId)->count(),
            'quiz_attempts'         => QuizAttempt::where('user_id', $userId)->count(),
            'movie_schedules'       => MovieSchedule::where('user_id', $userId)->count(),
            'watch_party_members'   => WatchPartyMember::where('user_id', $userId)->count(),
            'coins'                 => Coin::where('user_id', $userId)->count(),
            'user_level'            => UserLevel::where('user_id', $userId)->count(),
            'user_preferences'      => UserPreference::where('user_id', $userId)->count(),
            'recommendations'       => UserRecommendation::where('user_id', $userId)->count(),
            'year_in_reviews'       => YearInReview::where('user_id', $userId)->count(),
            'notifications'         => Notification::where('user_id', $userId)->count(),
            'login_attempts'        => LoginAttempt::where('email', mb_strtolower(trim($email)))->count(),
            'known_devices'         => KnownDevice::where('user_id', $userId)->count(),
            'churn_prediction'      => ChurnPrediction::where('user_id', $userId)->count(),
            'audit_logs_about_user' => AuditLog::where('user_id', $userId)->count(),
        ];

        DB::transaction(function () use ($user, $userId, $email, $reason, $summary): void {
            // ── Audit FIRST. If the txn rolls back this row goes too,
            // but if it commits we are guaranteed a paper trail.
            $this->audit->log(
                action: 'gdpr.user.erased',
                subject: $user,
                meta: [
                    'reason'  => $reason,
                    'email'   => $this->maskEmail($email),
                    'summary' => $summary,
                ],
                user: $user,
            );

            // ── ANONYMISE: comments — preserve thread integrity ──────
            // Body stays so replies/quotes don't dangle. user_id null
            // means future joins yield NULL which the views render as
            // "[Deleted User]".
            Comment::where('user_id', $userId)->update([
                'user_id' => null,
            ]);

            // ── ANONYMISE: subscriptions / payments — tax retention ──
            // Indonesia's tax law (UU PPN, PPh) requires payment records
            // to be retained for 10 years. Strip PII (transaction_id can
            // resolve to a card token at the gateway) but keep amounts.
            Subscription::where('user_id', $userId)->update([
                'user_id'        => null,
                'transaction_id' => null,
            ]);

            // ── HARD DELETE: pure engagement data ────────────────────
            WatchHistory::where('user_id', $userId)->delete();
            Watchlist::where('user_id', $userId)->delete();
            Rating::where('user_id', $userId)->delete();
            QuizAttempt::where('user_id', $userId)->delete();
            MovieSchedule::where('user_id', $userId)->delete();
            WatchPartyMember::where('user_id', $userId)->delete();

            // ── HARD DELETE: gamification & preferences ──────────────
            Coin::where('user_id', $userId)->delete();
            UserLevel::where('user_id', $userId)->delete();
            UserPreference::where('user_id', $userId)->delete();
            UserRecommendation::where('user_id', $userId)->delete();
            YearInReview::where('user_id', $userId)->delete();
            ChurnPrediction::where('user_id', $userId)->delete();

            // ── HARD DELETE: notifications + security telemetry ──────
            Notification::where('user_id', $userId)->delete();
            KnownDevice::where('user_id', $userId)->delete();

            // login_attempts is keyed by email (rows can predate the
            // user account), so match on the lowercased email.
            LoginAttempt::where('email', mb_strtolower(trim($email)))->delete();

            // ── HARD DELETE: audit logs ABOUT this user ──────────────
            // The single 'gdpr.user.erased' row we just wrote also has
            // user_id == $userId so it is in this set. Keep it by
            // selecting != most-recent, but simpler: write the audit
            // through a path that survives. Trade-off accepted: we
            // re-emit the audit AFTER the delete so the post-delete
            // record persists with a NULL actor.
            AuditLog::where('user_id', $userId)
                ->where('action', '!=', 'gdpr.user.erased')
                ->delete();

            // pivot rows (achievements, etc.) cascade via the user FK
            // when we delete the user — see migration 100006.
            $user->delete();

            // ── Re-emit a final audit row with NULL actor + the
            // summary, so the deletion is provable in admin even after
            // the gdpr.user.erased rows just got nuked together with
            // the user. This row has user_id NULL by construction.
            $this->audit->log(
                action: 'gdpr.user.erased.completed',
                subject: null,
                meta: [
                    'erased_user_id' => $userId,
                    'masked_email'   => $this->maskEmail($email),
                    'reason'         => $reason,
                    'summary'        => $summary,
                ],
                user: null,
            );
        });

        Log::info('GDPR user erasure completed', [
            'user_id' => $userId,
            'reason'  => $reason,
            'summary' => $summary,
        ]);

        return [
            'user_id'    => $userId,
            'email'      => $this->maskEmail($email),
            'reason'     => $reason,
            'deleted_at' => now()->toIso8601String(),
            'summary'    => $summary,
        ];
    }

    /**
     * Mask the local part of an email for audit logging — we keep enough
     * to investigate fraud patterns without storing the cleartext PII the
     * user just asked us to wipe.
     */
    protected function maskEmail(string $email): string
    {
        if (! str_contains($email, '@')) {
            return '***';
        }
        [$local, $domain] = explode('@', $email, 2);
        $first = mb_substr($local, 0, 1);
        return $first.str_repeat('*', max(1, mb_strlen($local) - 1)).'@'.$domain;
    }
}
