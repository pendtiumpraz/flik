<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Events\AdminNotificationCreated;
use App\Models\AdminNotification;
use App\Models\AdminNotificationRead;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AdminNotifier
 * --------------------------------------------------------------------------
 * Public API for ALL admin-facing realtime alerts. Peer agents (security,
 * billing, content ops) MUST go through `notify()` rather than creating
 * AdminNotification rows directly — this keeps audience normalisation,
 * broadcast fan-out, and audit-trail wiring in one place.
 *
 * Designed as an instance class (not statics) so feature tests can swap a
 * fake binding into the container without monkey-patching.
 */
class AdminNotifier
{
    /**
     * Cache TTL for {@see unreadCountFor()}. Short enough that bell-badge
     * staleness is never more than 30s, long enough that a 1-second polling
     * fallback (when Pusher is down) doesn't hammer the DB.
     */
    private const UNREAD_CACHE_TTL_SECONDS = 30;

    /**
     * Canonical role names recognised as "staff" by `all_admins` routing.
     * Mirrors {@see User::isStaff()} — duplicated here so a missing/legacy
     * `isStaff()` doesn't silently break audience resolution.
     *
     * @var array<int, string>
     */
    public const STAFF_ROLES = [
        'super_admin',
        'admin',
        'content_manager',
        'content_editor',
        'customer_support',
        'moderator',
        'finance',
    ];

    /**
     * Fan-out entry point.
     *
     * @param  string                       $category   Dotted namespace, e.g. "comment.new", "payment.success".
     * @param  string                       $title      One-line headline shown in the bell row.
     * @param  string                       $message    Longer descriptive body (plain text; sanitised at render).
     * @param  array<string,mixed>          $meta       Free-form structured payload.
     * @param  string                       $severity   One of `info` | `warning` | `critical`. Invalid → `info` + log.
     * @param  string|array<int,string>     $audience   `all_admins` | role-name string | role-name array | `super_admin_only`.
     * @param  string|null                  $actionUrl  Optional deep-link rendered as the "View" button.
     */
    public function notify(
        string $category,
        string $title,
        string $message,
        array $meta = [],
        string $severity = AdminNotification::SEVERITY_DEFAULT,
        string|array $audience = AdminNotification::AUDIENCE_ALL,
        ?string $actionUrl = null,
    ): AdminNotification {
        $severity = $this->normaliseSeverity($severity);
        $audienceCanonical = $this->normaliseAudience($audience);

        /** @var AdminNotification $notification */
        $notification = AdminNotification::create([
            'category'   => $category,
            'title'      => $title,
            'message'    => $message,
            'meta'       => $meta ?: null,
            'severity'   => $severity,
            'audience'   => $audienceCanonical,
            'action_url' => $actionUrl,
        ]);

        // Realtime fan-out. Wrapped because a misconfigured broadcaster
        // must NOT crash the originating request — the DB row is the
        // source of truth, websockets are the icing.
        try {
            event(new AdminNotificationCreated($notification));
        } catch (\Throwable $e) {
            Log::warning('admin_notification.broadcast_failed', [
                'notification_id' => $notification->id,
                'category'        => $category,
                'error'           => $e->getMessage(),
            ]);
        }

        // Invalidate every potentially-affected user's unread-count cache.
        // Cheap blunt instrument: clear the whole tagged bucket. Switch to
        // per-user invalidation if cache-tag support is unavailable on the
        // chosen driver (file/database don't support tags — we degrade).
        $this->invalidateUnreadCacheForAudience($audienceCanonical);

        return $notification;
    }

    /**
     * Idempotent — calling twice is a no-op after the first stamp.
     */
    public function markAsRead(AdminNotification $notification, User $user): void
    {
        $notification->markReadFor($user);
        $this->forgetUnreadCount($user);
    }

    /**
     * Bulk-stamp every notification this user is allowed to see + has not
     * yet read. Returns the number of newly-marked rows.
     */
    public function markAllReadFor(User $user): int
    {
        $unreadIds = AdminNotification::query()
            ->forUser($user)
            ->unreadFor($user)
            ->pluck('id')
            ->all();

        if ($unreadIds === []) {
            return 0;
        }

        $now = now();
        $rows = array_map(
            fn (int $nid) => [
                'admin_notification_id' => $nid,
                'user_id'               => $user->id,
                'read_at'               => $now,
            ],
            $unreadIds,
        );

        // `insertOrIgnore` complements the unique index — if a parallel
        // request slipped a row in between our SELECT and INSERT, the
        // unique constraint absorbs the collision instead of throwing.
        DB::table('admin_notification_reads')->insertOrIgnore($rows);

        $this->forgetUnreadCount($user);

        return count($unreadIds);
    }

    /**
     * Bell-badge count. Cached per-user for {@see UNREAD_CACHE_TTL_SECONDS}.
     * Cache key is keyed on user-id ONLY (not role list) — when a user's
     * roles change, `forgetUnreadCount()` MUST be called by the role
     * mutator path (peer ROLE swarm owns that hook).
     */
    public function unreadCountFor(User $user): int
    {
        return (int) Cache::remember(
            $this->unreadCacheKey($user),
            self::UNREAD_CACHE_TTL_SECONDS,
            fn () => AdminNotification::query()
                ->forUser($user)
                ->unreadFor($user)
                ->count(),
        );
    }

    /**
     * Recent feed for the bell dropdown / inbox page.
     *
     * @return Collection<int, AdminNotification>
     */
    public function recentForUser(User $user, int $limit = 50): Collection
    {
        return AdminNotification::query()
            ->forUser($user)
            ->with(['reads' => fn ($q) => $q->where('user_id', $user->id)])
            ->latest('created_at')
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Drop the cached unread count for a single user (e.g. after they
     * mark something read or after their roles change).
     */
    public function forgetUnreadCount(User $user): void
    {
        Cache::forget($this->unreadCacheKey($user));
    }

    // ── Internal helpers ────────────────────────────────────────

    /**
     * Validate + default severity. Invalid input is logged once so a
     * caller bug surfaces in /admin/logs without throwing.
     */
    private function normaliseSeverity(string $severity): string
    {
        if (in_array($severity, AdminNotification::SEVERITIES, true)) {
            return $severity;
        }

        Log::warning('admin_notification.invalid_severity', [
            'given'   => $severity,
            'fallback' => AdminNotification::SEVERITY_DEFAULT,
        ]);

        return AdminNotification::SEVERITY_DEFAULT;
    }

    /**
     * Canonicalise the audience into ONE OF:
     *   - the literal `all_admins` token
     *   - a comma-separated, sorted, deduplicated role-name list.
     *
     * Sorting + deduplication gives identical audience inputs identical
     * stored strings (important for the audience index + future analytics).
     *
     * @param  string|array<int,string>  $audience
     */
    private function normaliseAudience(string|array $audience): string
    {
        // Single special tokens.
        if ($audience === AdminNotification::AUDIENCE_ALL) {
            return AdminNotification::AUDIENCE_ALL;
        }
        if ($audience === AdminNotification::AUDIENCE_SUPER_ADMIN_ONLY) {
            return 'super_admin';
        }

        $names = is_array($audience) ? $audience : [$audience];
        $names = array_values(array_unique(array_filter(array_map(
            static fn ($n) => trim((string) $n),
            $names,
        ))));

        if ($names === []) {
            // Empty audience is almost always a bug — fall back to the
            // safest (broadest) routing instead of silently dropping.
            Log::warning('admin_notification.empty_audience_fallback_all_admins');

            return AdminNotification::AUDIENCE_ALL;
        }

        sort($names);

        return implode(',', $names);
    }

    private function unreadCacheKey(User $user): string
    {
        return 'admin_notif.unread.' . $user->id;
    }

    /**
     * Invalidate unread-count cache entries for every user who could see
     * notifications in the given audience. Pure-DB scan; runs O(staff)
     * which is small (typically <100 rows). The 30-second TTL bounds the
     * blast radius even if we miss anyone.
     */
    private function invalidateUnreadCacheForAudience(string $audienceCanonical): void
    {
        try {
            $query = User::query()->select('id');

            if ($audienceCanonical === AdminNotification::AUDIENCE_ALL) {
                // Every staff user (legacy column OR pivot role).
                $query->where(function ($q) {
                    $q->where('is_admin', true)
                        ->orWhereIn('role', self::STAFF_ROLES);
                    if (\Illuminate\Support\Facades\Schema::hasTable('role_user')) {
                        $q->orWhereHas('roles', function ($r) {
                            $r->whereIn('name', self::STAFF_ROLES);
                        });
                    }
                });
            } else {
                $roles = explode(',', $audienceCanonical);
                $query->where(function ($q) use ($roles) {
                    $q->whereIn('role', $roles);
                    if (\Illuminate\Support\Facades\Schema::hasTable('role_user')) {
                        $q->orWhereHas('roles', function ($r) use ($roles) {
                            $r->whereIn('name', $roles);
                        });
                    }
                });
            }

            $query->chunkById(200, function ($users) {
                foreach ($users as $u) {
                    Cache::forget('admin_notif.unread.' . $u->id);
                }
            });
        } catch (\Throwable $e) {
            // Cache invalidation is best-effort; never let it raise.
            Log::warning('admin_notification.cache_invalidate_failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
