<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AdminNotification
 * --------------------------------------------------------------------------
 * Staff-facing realtime alert. Distinct from {@see Notification} (user-facing).
 *
 * Audience routing rules — single source of truth, all peer code (services,
 * controllers, the bell widget) MUST go through this model:
 *
 *  - `audience = 'all_admins'` → visible to any user with `isStaff() === true`.
 *  - `audience = 'role_a,role_b,role_c'` → comma-separated role-name list;
 *    visible to any user holding at least one of those roles.
 *  - `super_admin_only` is just the canonical comma-list `super_admin`.
 *
 * Read state lives on the {@see AdminNotificationRead} pivot — `isReadBy()`
 * and `markReadFor()` are the only public surface for it.
 */
class AdminNotification extends Model
{
    use HasFactory;

    /**
     * Service-layer writes only — controllers never new this up directly.
     * Mass assignment is safe because no DB column is privilege-sensitive.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Disable Eloquent's `updated_at` — rows are immutable once created.
     */
    public const UPDATED_AT = null;

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Valid severity values. The DB enum is the second line of defence;
     * this constant is what the service layer validates against and the
     * UI iterates over for the filter chips.
     *
     * @var array<int, string>
     */
    public const SEVERITIES = ['info', 'warning', 'critical'];

    public const SEVERITY_DEFAULT = 'info';

    /**
     * Special audience tokens. Peers MUST reference these constants
     * rather than the raw string so a future rename is single-touch.
     */
    public const AUDIENCE_ALL = 'all_admins';

    public const AUDIENCE_SUPER_ADMIN_ONLY = 'super_admin_only';

    // ── Relations ────────────────────────────────────────────────

    /**
     * @return HasMany<AdminNotificationRead>
     */
    public function reads(): HasMany
    {
        return $this->hasMany(AdminNotificationRead::class);
    }

    /**
     * @return BelongsToMany<User>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'admin_notification_reads',
            'admin_notification_id',
            'user_id',
        )->withPivot('read_at');
    }

    // ── Scopes ───────────────────────────────────────────────────

    /**
     * Filter to notifications whose audience the given user matches.
     *
     * We use SQL `LIKE` predicates rather than `whereJsonContains` because
     * the column is a comma-string (string(120)), not JSON. The LIKE
     * patterns anchor the role name between separators so `admin` does NOT
     * match `super_admin`.
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        // Resolve the user's effective role names. We pull from BOTH the
        // pivot AND the legacy `role` column so this works during the
        // mid-migration window when not every user has been backfilled.
        $roleNames = [];
        if ($user->role) {
            $roleNames[] = $user->role;
        }
        if (method_exists($user, 'roles')) {
            // `relationLoaded()` keeps us from triggering an extra query
            // when the caller has already eager-loaded roles.
            $loaded = $user->relationLoaded('roles')
                ? $user->getRelation('roles')
                : $user->roles()->get(['roles.id', 'roles.name']);
            foreach ($loaded as $r) {
                $roleNames[] = $r->name;
            }
        }
        $roleNames = array_values(array_unique(array_filter($roleNames)));

        return $query->where(function (Builder $q) use ($roleNames) {
            // Wildcard audience — every staffer sees it.
            $q->where('audience', self::AUDIENCE_ALL);

            // Per-role match. Three LIKE patterns cover the three positions
            // a role name can occupy in the comma-list:
            //   "role" (only role), "role,…" (head), "…,role,…" (middle),
            //   "…,role" (tail). We collapse head+middle+tail into the
            //   middle pattern by wrapping the column in commas at query time.
            foreach ($roleNames as $name) {
                $q->orWhereRaw(
                    "CONCAT(',', audience, ',') LIKE ?",
                    ['%,'.$name.',%']
                );
            }
        });
    }

    /**
     * Filter to notifications NOT yet read by the given user.
     */
    public function scopeUnreadFor(Builder $query, User $user): Builder
    {
        return $query->whereDoesntHave('reads', function (Builder $q) use ($user) {
            $q->where('user_id', $user->id);
        });
    }

    // ── Read-state helpers ───────────────────────────────────────

    public function isReadBy(User $user): bool
    {
        // Use the loaded relation when present to avoid an extra query
        // inside Blade loops (`@foreach ($notifications as $n)` paths).
        if ($this->relationLoaded('reads')) {
            return $this->getRelation('reads')
                ->contains(fn ($r) => (int) $r->user_id === (int) $user->id);
        }

        return $this->reads()->where('user_id', $user->id)->exists();
    }

    /**
     * Idempotent: re-marking refreshes the stamp without creating duplicates.
     * The unique index on (admin_notification_id, user_id) is the safety net.
     */
    public function markReadFor(User $user): void
    {
        AdminNotificationRead::updateOrCreate(
            [
                'admin_notification_id' => $this->id,
                'user_id' => $user->id,
            ],
            ['read_at' => now()],
        );
    }

    // ── Presentation helpers ─────────────────────────────────────

    /**
     * Hex colour for the severity badge. Centralised here so the bell
     * widget, list page, and any future digest email all agree on colour.
     */
    public function severityColor(): string
    {
        return match ($this->severity) {
            'critical' => '#ef4444', // red-500
            'warning'  => '#f59e0b', // amber-500
            default    => '#3b82f6', // blue-500 (info)
        };
    }

    /**
     * Role names this notification is addressed to, resolved from the
     * `audience` column. `all_admins` returns the special-token list.
     *
     * @return array<int, string>
     */
    public function audienceRoles(): array
    {
        if ($this->audience === self::AUDIENCE_ALL) {
            return [self::AUDIENCE_ALL];
        }

        return array_values(array_filter(array_map('trim', explode(',', (string) $this->audience))));
    }
}
