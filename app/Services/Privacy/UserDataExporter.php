<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use App\Models\AiUsageLog;
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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * UserDataExporter
 * --------------------------------------------------------------------------
 * GDPR Article 15 (right to access) + Article 20 (right to data portability).
 *
 * Aggregates every user-owned record in the platform into a single JSON
 * document and persists it to the private disk under
 *   storage/app/private/exports/user_{id}_{timestamp}.json
 *
 * The returned signed URL points at the controller's `download` route,
 * NOT at the file directly — the disk is not web-reachable, so a signed
 * download endpoint is the only way out. The signature expires after 24h.
 *
 * System-internal columns (encrypted secrets, raw IPs scrubbed from old
 * rows for older users, hash artefacts) are stripped before serialisation.
 */
class UserDataExporter
{
    /**
     * Subdirectory on the `private` disk where exports land. Kept in one
     * constant so {@see CleanupOldExports} can sweep the same path.
     */
    public const EXPORT_DIR = 'exports';

    /**
     * Run a full export for $user and return the public download URL.
     *
     * The file is written via Storage::put() (atomic on local disks) so
     * concurrent exports from the same user can't half-write into each
     * other — each call gets a unique timestamp suffix.
     */
    public function export(User $user): string
    {
        $payload = $this->buildPayload($user);

        $filename = $this->generateFilename($user);
        $path = self::EXPORT_DIR.'/'.$filename;

        Storage::disk('private')->put(
            $path,
            json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
            ),
        );

        return $this->signedUrl($filename);
    }

    /**
     * Aggregate every user-owned record. Each section is keyed so the
     * downloaded JSON is human-readable without further tooling.
     *
     * @return array<string,mixed>
     */
    public function buildPayload(User $user): array
    {
        return [
            'meta' => [
                'export_version' => '1.0',
                'export_format'  => 'json',
                'generated_at'   => now()->toIso8601String(),
                'user_id'        => $user->id,
                'platform'       => config('app.name'),
                'gdpr_articles'  => ['Article 15 (right to access)', 'Article 20 (data portability)'],
                'notice'         => 'This export contains every record FLiK holds about your account. Treat it as confidential.',
            ],

            // ── Identity ──────────────────────────────────────────────
            'profile' => $this->scrubUser($user),

            // ── Preferences ───────────────────────────────────────────
            'preferences' => $this->oneRow(
                UserPreference::where('user_id', $user->id)->first(),
                ['id', 'user_id'],
            ),

            'level_and_xp' => $this->oneRow(
                UserLevel::where('user_id', $user->id)->first(),
                ['id', 'user_id'],
            ),

            // ── Engagement ────────────────────────────────────────────
            'watchlist' => Watchlist::with('movie:id,title,slug')
                ->where('user_id', $user->id)
                ->get()
                ->map(fn (Watchlist $w) => [
                    'movie_id'    => $w->movie_id,
                    'movie_title' => $w->movie?->title,
                    'movie_slug'  => $w->movie?->slug,
                    'added_at'    => $w->created_at?->toIso8601String(),
                ])
                ->all(),

            'watch_history' => WatchHistory::with('movie:id,title,slug')
                ->where('user_id', $user->id)
                ->get()
                ->map(fn (WatchHistory $h) => [
                    'movie_id'         => $h->movie_id,
                    'movie_title'      => $h->movie?->title,
                    'movie_slug'       => $h->movie?->slug,
                    'progress_seconds' => $h->progress_seconds,
                    'duration_seconds' => $h->duration_seconds,
                    'completed'        => (bool) $h->completed,
                    'last_watched_at'  => $h->last_watched_at?->toIso8601String(),
                ])
                ->all(),

            'ratings' => Rating::with('movie:id,title,slug')
                ->where('user_id', $user->id)
                ->get()
                ->map(fn (Rating $r) => [
                    'movie_id'    => $r->movie_id,
                    'movie_title' => $r->movie?->title,
                    'movie_slug'  => $r->movie?->slug,
                    'score'       => $r->score,
                    'review'      => $r->review,
                    'created_at'  => $r->created_at?->toIso8601String(),
                    'updated_at'  => $r->updated_at?->toIso8601String(),
                ])
                ->all(),

            'comments' => Comment::with('movie:id,title,slug')
                ->where('user_id', $user->id)
                ->get()
                ->map(fn (Comment $c) => [
                    'id'         => $c->id,
                    'movie_id'   => $c->movie_id,
                    'movie_title'=> $c->movie?->title,
                    'parent_id'  => $c->parent_id,
                    'body'       => $c->body,
                    'is_spoiler' => (bool) $c->is_spoiler,
                    'created_at' => $c->created_at?->toIso8601String(),
                ])
                ->all(),

            'schedules' => MovieSchedule::with('movie:id,title,slug')
                ->where('user_id', $user->id)
                ->get()
                ->map(fn (MovieSchedule $s) => [
                    'movie_id'         => $s->movie_id,
                    'movie_title'      => $s->movie?->title,
                    'scheduled_for'    => $s->scheduled_for?->toIso8601String(),
                    'notes'            => $s->notes,
                    'reminder_sent_at' => $s->reminder_sent_at?->toIso8601String(),
                    'watched_at'       => $s->watched_at?->toIso8601String(),
                ])
                ->all(),

            'quiz_attempts' => QuizAttempt::with('movie:id,title,slug')
                ->where('user_id', $user->id)
                ->get()
                ->map(fn (QuizAttempt $q) => [
                    'movie_id'        => $q->movie_id,
                    'movie_title'     => $q->movie?->title,
                    'score'           => $q->score,
                    'total_questions' => $q->total_questions,
                    'correct_count'   => $q->correct_count,
                    'time_seconds'    => $q->time_seconds,
                    'completed_at'    => $q->completed_at?->toIso8601String(),
                ])
                ->all(),

            'watch_parties' => WatchPartyMember::with(['watchParty:id,room_code,movie_id,host_id,started_at,ended_at'])
                ->where('user_id', $user->id)
                ->get()
                ->map(fn (WatchPartyMember $m) => [
                    'room_code'  => $m->watchParty?->room_code,
                    'movie_id'   => $m->watchParty?->movie_id,
                    'is_host'    => $m->watchParty?->host_id === $user->id,
                    'joined_at'  => $m->joined_at?->toIso8601String(),
                    'left_at'    => $m->left_at?->toIso8601String(),
                ])
                ->all(),

            // ── Commerce ──────────────────────────────────────────────
            'subscriptions' => Subscription::with('plan:id,name,price,duration_days')
                ->where('user_id', $user->id)
                ->get()
                ->map(fn (Subscription $s) => [
                    'plan'           => $s->plan?->only(['name', 'price', 'duration_days']),
                    'status'         => $s->status,
                    'starts_at'      => $s->starts_at?->toIso8601String(),
                    'ends_at'        => $s->ends_at?->toIso8601String(),
                    'cancelled_at'   => $s->cancelled_at?->toIso8601String(),
                    'paid_at'        => $s->paid_at?->toIso8601String(),
                    'amount'         => $s->amount,
                    'payment_method' => $s->payment_method,
                    'order_id'       => $s->order_id,
                    'transaction_id' => $s->transaction_id,
                ])
                ->all(),

            // ── Loyalty ───────────────────────────────────────────────
            'coins_ledger' => Coin::where('user_id', $user->id)
                ->orderBy('created_at')
                ->get(['amount', 'type', 'description', 'created_at'])
                ->map(fn (Coin $c) => [
                    'amount'      => $c->amount,
                    'type'        => $c->type,
                    'description' => $c->description,
                    'created_at'  => $c->created_at?->toIso8601String(),
                ])
                ->all(),

            'coin_balance' => Coin::balanceFor($user->id),

            'achievements' => $user->achievements()->get()->map(fn ($a) => [
                'name'        => $a->name,
                'slug'        => $a->slug,
                'description' => $a->description,
                'tier'        => $a->tier,
                'unlocked_at' => $a->pivot?->unlocked_at,
            ])->all(),

            // ── Notifications & recommendations ──────────────────────
            'notifications' => Notification::where('user_id', $user->id)
                ->get(['type', 'title', 'message', 'action_url', 'read_at', 'created_at'])
                ->toArray(),

            'recommendations' => UserRecommendation::with('movie:id,title,slug')
                ->where('user_id', $user->id)
                ->get()
                ->map(fn (UserRecommendation $r) => [
                    'movie_id'     => $r->movie_id,
                    'movie_title'  => $r->movie?->title,
                    'score'        => $r->score,
                    'reason'       => $r->reason,
                    'source'       => $r->source,
                    'generated_at' => $r->generated_at?->toIso8601String(),
                ])
                ->all(),

            'year_in_reviews' => YearInReview::where('user_id', $user->id)
                ->get(['year', 'stats', 'narrative', 'generated_at', 'shared_count'])
                ->toArray(),

            // ── Security ──────────────────────────────────────────────
            'login_attempts' => LoginAttempt::forEmail((string) $user->email)
                ->orderByDesc('attempted_at')
                ->limit(500)
                ->get(['ip', 'user_agent', 'success', 'attempted_at'])
                ->map(fn (LoginAttempt $l) => [
                    'ip'           => $this->scrubIp($l->ip),
                    'user_agent'   => $l->user_agent,
                    'success'      => (bool) $l->success,
                    'attempted_at' => $l->attempted_at?->toIso8601String(),
                ])
                ->all(),

            'known_devices' => KnownDevice::where('user_id', $user->id)
                ->get(['device_fingerprint', 'ip', 'country', 'user_agent', 'first_seen_at', 'last_seen_at', 'trusted'])
                ->map(fn (KnownDevice $d) => [
                    'device_fingerprint' => $d->device_fingerprint,
                    'ip'                 => $this->scrubIp($d->ip),
                    'country'            => $d->country,
                    'user_agent'         => $d->user_agent,
                    'first_seen_at'      => $d->first_seen_at?->toIso8601String(),
                    'last_seen_at'       => $d->last_seen_at?->toIso8601String(),
                    'trusted'            => (bool) $d->trusted,
                ])
                ->all(),

            // ── Audit & AI usage logs about THIS user ────────────────
            'audit_logs' => AuditLog::forUser($user->id)
                ->orderByDesc('created_at')
                ->limit(2000)
                ->get(['action', 'subject_type', 'subject_id', 'client_ip', 'user_agent', 'meta', 'created_at'])
                ->map(fn (AuditLog $a) => [
                    'action'       => $a->action,
                    'subject_type' => $a->subject_type,
                    'subject_id'   => $a->subject_id,
                    'client_ip'    => $this->scrubIp($a->client_ip),
                    'user_agent'   => $a->user_agent,
                    'meta'         => $a->meta,
                    'created_at'   => $a->created_at?->toIso8601String(),
                ])
                ->all(),

            // AI usage where THIS user is the morph subject of a per-user task
            // (e.g. recommendation batch, year-in-review). We don't ship
            // catalog-wide logs that happen to coincide with their activity.
            'ai_usage_about_me' => AiUsageLog::where('subject_type', User::class)
                ->where('subject_id', $user->id)
                ->orderByDesc('created_at')
                ->limit(1000)
                ->get(['task_type', 'input_tokens', 'output_tokens', 'cost_usd', 'success', 'created_at'])
                ->toArray(),

            // ── ML predictions about the user ────────────────────────
            'churn_prediction' => $this->oneRow(
                ChurnPrediction::where('user_id', $user->id)->first(),
                ['id', 'user_id'],
            ),
        ];
    }

    /**
     * Generate a stable signed URL pointing at the privacy download
     * controller. 24-hour expiry per the task spec.
     */
    public function signedUrl(string $filename): string
    {
        return URL::temporarySignedRoute(
            'privacy.export.download',
            now()->addHours(24),
            ['filename' => $filename],
        );
    }

    /**
     * Build the canonical filename. Includes user id + unix timestamp so
     * concurrent exports stay distinct and the cleanup command can sort
     * by age without touching mtime.
     */
    protected function generateFilename(User $user): string
    {
        return sprintf('user_%d_%d.json', $user->id, now()->getTimestamp());
    }

    /**
     * Strip every system-internal column from the user row before export.
     * Encrypted secrets (2FA seed, recovery codes), the bcrypt hash, and
     * the remember token are all useless to the user and dangerous to
     * leak if the export ever escapes their control.
     *
     * @return array<string,mixed>
     */
    protected function scrubUser(User $user): array
    {
        $arr = $user->only([
            'id', 'name', 'email', 'role', 'is_admin',
            'email_verified_at', 'password_changed_at',
            'created_at', 'updated_at',
        ]);

        // Stamp 2FA-enabled boolean rather than the secret itself.
        $arr['two_factor_enabled'] = $user->hasTwoFactorEnabled();

        return $arr;
    }

    /**
     * Pull a model row into an array minus the system columns we never
     * want in an export (PK, FK back to user). Returns null when the
     * source row doesn't exist (e.g. user never onboarded).
     *
     * @param  list<string>  $stripKeys
     * @return array<string,mixed>|null
     */
    protected function oneRow(?\Illuminate\Database\Eloquent\Model $model, array $stripKeys = []): ?array
    {
        if ($model === null) {
            return null;
        }

        $arr = $model->toArray();
        foreach ($stripKeys as $k) {
            unset($arr[$k]);
        }

        return $arr;
    }

    /**
     * Truncate the last octet/group of an IP address before exporting so
     * the user gets to see "where they logged in from" without us handing
     * them surveillance-grade telemetry on themselves. Returns null when
     * the input is null/empty.
     */
    protected function scrubIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        // IPv4 → mask last octet
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            $parts[3] = '0';
            return implode('.', $parts);
        }

        // IPv6 → keep first 4 groups
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts = explode(':', $ip);
            return implode(':', array_slice($parts, 0, 4)).'::';
        }

        return null;
    }
}
