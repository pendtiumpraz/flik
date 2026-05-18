<?php

namespace App\Models;

use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Auth\VerifyEmailNotification;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, Notifiable;

    /**
     * Mass-assignable attributes for end-user controlled writes (registration,
     * profile update, OAuth bootstrap).
     *
     * SECURITY: Privilege fields (`is_admin`, `role`), verification stamps
     * (`email_verified_at`), 2FA secrets, password mutation timestamps,
     * remember tokens, and OAuth provider IDs are deliberately EXCLUDED so
     * a crafted form payload cannot escalate privileges or bypass 2FA.
     * Internal callers (seeders, AdminController::toggleAdmin,
     * SessionsController, TwoFactorController, LoginThrottle) write those
     * fields via explicit `forceFill(...)` / `setAttribute(...)`.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name', 'email', 'username', 'password',
    ];

    /**
     * Override Laravel's default email-verification notification so we send the
     * FLiK-branded HTML template instead of the framework's plain mailable.
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification());
    }

    /**
     * Override Laravel's default password-reset notification so we send the
     * FLiK-branded HTML template instead of the framework's plain mailable.
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    protected $hidden = [
        'password', 'remember_token',
        'two_factor_secret', 'two_factor_recovery_codes',
        // PII — hide from default model serialization (toArray / toJson).
        // Controllers that genuinely need to expose these (e.g. profile
        // page) must `makeVisible(...)` explicitly.
        'phone', 'address', 'national_id_hash', 'birth_date',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password_changed_at' => 'datetime',
        'is_admin' => 'boolean',
        // 2FA — encrypted-at-rest via Laravel's encrypter casts.
        'two_factor_secret' => 'encrypted',
        'two_factor_recovery_codes' => 'encrypted:array',
        'two_factor_confirmed_at' => 'datetime',

        // PII encryption (Laravel encrypter — AES-256-CBC keyed by APP_KEY).
        // NOTE: `email` and `name` deliberately stay plaintext — login lookup
        // (`where('email', ...)`) and admin search would break otherwise.
        // For lookup-by-PII use a separate searchable hash column
        // (see `national_id_hash` + `findByNationalId`).
        'phone'      => 'encrypted',
        'address'    => 'encrypted',
        'birth_date' => 'date', // plaintext on disk — used by age verification queries
    ];

    /**
     * Convenience flag — true once the user has completed the TOTP setup
     * and the login flow must show the challenge screen.
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null
            && !empty($this->two_factor_secret);
    }

    // ── Roles ─────────────────────────────────────────────────
    public const ROLE_SUPER_ADMIN = 'super_admin';
    public const ROLE_CONTENT_MANAGER = 'content_manager';
    public const ROLE_CUSTOMER_SUPPORT = 'customer_support';
    public const ROLE_FINANCE = 'finance';
    public const ROLE_USER = 'user';

    public const ROLES = [
        self::ROLE_SUPER_ADMIN => 'Super Admin',
        self::ROLE_CONTENT_MANAGER => 'Content Manager',
        self::ROLE_CUSTOMER_SUPPORT => 'Customer Support',
        self::ROLE_FINANCE => 'Finance',
        self::ROLE_USER => 'User',
    ];

    /**
     * Cache of `roles.permissions` for the lifetime of this model instance.
     * Populated lazily by `hasPermission()` / `permissions()` so we issue
     * at most ONE eager-load query per request, regardless of how many
     * gate checks fire downstream.
     *
     * Null means "not yet loaded"; an empty collection means "loaded, no
     * roles assigned" — the difference matters for cache invalidation.
     *
     * @var \Illuminate\Support\Collection<int, \App\Models\Permission>|null
     */
    private ?Collection $cachedPermissions = null;

    /**
     * Pivot relation to roles. Each assignment carries the actor (admin
     * who granted it) and a precise `assigned_at` stamp so we can build
     * an immutable audit trail even when roles get re-synced.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            \App\Models\Role::class,
            'role_user',
            'user_id',
            'role_id',
        )
            ->withPivot('assigned_by_user_id', 'assigned_at')
            ->withTimestamps();
    }

    /**
     * Does the user have ANY of the given role names?
     *
     * Accepts a single string OR an array of strings. We check BOTH the
     * pivot-based roles table AND the legacy `role` column so the helper
     * keeps working before/during/after the role backfill migration.
     *
     * @param  string|array<int, string>  $names
     */
    public function hasRole(string|array $names): bool
    {
        $needles = is_array($names) ? $names : [$names];

        // Legacy single-column role — keep working for users whose pivot
        // rows have not been backfilled yet.
        if ($this->role !== null && in_array($this->role, $needles, true)) {
            return true;
        }

        // Pivot-based roles. Defensive: if the table or model class is
        // missing (fresh install before peer migrations land), skip the
        // query rather than blow up the auth flow.
        if (!class_exists(\App\Models\Role::class) || !Schema::hasTable('role_user')) {
            return false;
        }

        return $this->roles()
            ->whereIn('name', $needles)
            ->exists();
    }

    /**
     * Does the user have ALL of the given role names? Useful for views
     * that require, e.g., both "finance" AND "auditor".
     *
     * @param  array<int, string>  $names
     */
    public function hasAllRoles(array $names): bool
    {
        if ($names === []) {
            return true;
        }

        if (!class_exists(\App\Models\Role::class) || !Schema::hasTable('role_user')) {
            // Legacy single-column can only satisfy one role at a time.
            return count($names) === 1 && $this->role === $names[0];
        }

        $assigned = $this->roles()->whereIn('name', $names)->pluck('name')->all();

        // Compare as sets — order does not matter, duplicates are squashed
        // by the underlying SELECT DISTINCT semantics of the pivot.
        return count(array_intersect($names, $assigned)) === count(array_unique($names));
    }

    /**
     * Assign a role to the user. Idempotent: re-assigning the same role
     * refreshes the `assigned_at` stamp + actor without creating a
     * duplicate pivot row.
     *
     * @param  \App\Models\Role|string  $role
     */
    public function assignRole($role, ?int $assignedBy = null): self
    {
        if (!class_exists(\App\Models\Role::class)) {
            return $this;
        }

        $roleModel = $role instanceof \App\Models\Role
            ? $role
            : \App\Models\Role::query()->where('name', (string) $role)->first();

        if ($roleModel === null) {
            return $this;
        }

        $this->roles()->syncWithoutDetaching([
            $roleModel->id => [
                'assigned_by_user_id' => $assignedBy,
                'assigned_at' => now(),
            ],
        ]);

        $this->forgetPermissionCache();

        return $this;
    }

    /**
     * Remove a single role from the user. No-op if not assigned.
     *
     * @param  \App\Models\Role|string  $role
     */
    public function removeRole($role): self
    {
        if (!class_exists(\App\Models\Role::class)) {
            return $this;
        }

        $roleModel = $role instanceof \App\Models\Role
            ? $role
            : \App\Models\Role::query()->where('name', (string) $role)->first();

        if ($roleModel === null) {
            return $this;
        }

        $this->roles()->detach($roleModel->id);
        $this->forgetPermissionCache();

        return $this;
    }

    /**
     * Replace ALL of this user's roles with the given set, atomically.
     * Unknown role names are silently skipped (so the caller does not
     * have to pre-validate against a constantly-growing taxonomy).
     *
     * @param  array<int, string>  $roleNames
     */
    public function syncRoles(array $roleNames): self
    {
        if (!class_exists(\App\Models\Role::class)) {
            return $this;
        }

        $ids = \App\Models\Role::query()
            ->whereIn('name', $roleNames)
            ->pluck('id')
            ->all();

        // Build the pivot payload so each assignment gets a fresh stamp.
        $payload = [];
        foreach ($ids as $id) {
            $payload[$id] = [
                'assigned_by_user_id' => null,
                'assigned_at' => now(),
            ];
        }

        $this->roles()->sync($payload);
        $this->forgetPermissionCache();

        return $this;
    }

    /**
     * Does the user have the given permission?
     *
     * Resolution order:
     *   1. Super-admin (legacy column OR `super_admin` role) → always true.
     *   2. Any of the user's roles has a matching permission name.
     *
     * Eager-loads `roles.permissions` on first call and caches in an
     * instance property so subsequent gate checks within the same
     * request cost zero queries.
     */
    public function hasPermission(string $name): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $this->loadPermissionCache()
            ->contains(fn ($perm) => $perm->name === $name);
    }

    /**
     * Flat, deduplicated collection of every permission granted to this
     * user across all of their roles. Backed by the same cache as
     * `hasPermission()`.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\Permission>
     */
    public function permissions(): Collection
    {
        return $this->loadPermissionCache();
    }

    /**
     * Lazily resolve + memoize this user's effective permissions.
     */
    private function loadPermissionCache(): Collection
    {
        if ($this->cachedPermissions !== null) {
            return $this->cachedPermissions;
        }

        // Fresh install / missing peer migrations → empty cache instead of crash.
        if (!class_exists(\App\Models\Role::class)
            || !class_exists(\App\Models\Permission::class)
            || !Schema::hasTable('role_user')
            || !Schema::hasTable('permission_role')) {
            return $this->cachedPermissions = collect();
        }

        // Single eager-load with the pivot — N+1 safe.
        if (!$this->relationLoaded('roles')) {
            $this->load('roles.permissions');
        } elseif ($this->roles->isNotEmpty() && !$this->roles->first()->relationLoaded('permissions')) {
            $this->roles->loadMissing('permissions');
        }

        $this->cachedPermissions = $this->roles
            ->flatMap(fn ($role) => $role->permissions ?? collect())
            ->unique('name')
            ->values();

        return $this->cachedPermissions;
    }

    /**
     * Drop the in-memory permission cache so the next gate check
     * re-queries. Called automatically after assign/remove/sync.
     */
    public function forgetPermissionCache(): self
    {
        $this->cachedPermissions = null;
        $this->unsetRelation('roles');

        return $this;
    }

    /**
     * Super-admin check honours BOTH the legacy `role === 'super_admin'`
     * column AND the modern pivot role of the same name AND the older
     * boolean `is_admin` so existing routes never lose access during a
     * migration.
     */
    public function isSuperAdmin(): bool
    {
        if ($this->role === self::ROLE_SUPER_ADMIN) {
            return true;
        }

        // Read the RAW `is_admin` column — NOT `$this->is_admin` — so we
        // don't trigger the accessor (which would itself query the pivot
        // table and cause us to do the same work twice).
        if ((bool) ($this->attributes['is_admin'] ?? false) === true) {
            return true;
        }

        // Pivot lookup — gated on Schema so fresh installs do not crash.
        if (class_exists(\App\Models\Role::class) && Schema::hasTable('role_user')) {
            // Use the already-loaded relation when present to keep the
            // super-admin fast path O(0) queries inside the request.
            if ($this->relationLoaded('roles')) {
                return $this->roles->contains(fn ($r) => $r->name === self::ROLE_SUPER_ADMIN);
            }

            return $this->roles()->where('name', self::ROLE_SUPER_ADMIN)->exists();
        }

        return false;
    }

    public function isStaff(): bool
    {
        $staffRoles = [
            self::ROLE_SUPER_ADMIN,
            self::ROLE_CONTENT_MANAGER,
            self::ROLE_CUSTOMER_SUPPORT,
            self::ROLE_FINANCE,
        ];

        // Legacy single-column path.
        if (in_array($this->role, $staffRoles, true)) {
            return true;
        }

        // Pivot path — same defensive guards as the role helpers.
        return $this->hasRole($staffRoles);
    }

    /**
     * Legacy boolean — true when the user has admin OR super_admin role
     * (pivot or legacy column) OR the older `is_admin` flag was set.
     *
     * Kept so existing `@can('admin')` directives, `can:admin` route
     * middleware, and `$user->is_admin` checks in older controllers all
     * continue to resolve correctly through the new role system.
     */
    public function getIsAdminAttribute($value): bool
    {
        // Honour the raw DB column first so legacy installs that flipped
        // the boolean directly keep working without touching roles.
        if ((bool) $value === true) {
            return true;
        }

        if ($this->role === self::ROLE_SUPER_ADMIN || $this->role === 'admin') {
            return true;
        }

        // Pivot lookup — same fresh-install guard pattern.
        if (class_exists(\App\Models\Role::class) && Schema::hasTable('role_user')) {
            if ($this->relationLoaded('roles')) {
                return $this->roles->contains(fn ($r) => in_array($r->name, ['admin', self::ROLE_SUPER_ADMIN], true));
            }

            return $this->roles()->whereIn('name', ['admin', self::ROLE_SUPER_ADMIN])->exists();
        }

        return false;
    }

    public function getRoleLabelAttribute(): string
    {
        return self::ROLES[$this->role] ?? 'User';
    }

    public function adminDashboardUrl(): string
    {
        return match ($this->role) {
            self::ROLE_SUPER_ADMIN => '/admin',
            self::ROLE_CONTENT_MANAGER => '/admin/movies',
            self::ROLE_CUSTOMER_SUPPORT => '/admin/users',
            self::ROLE_FINANCE => '/admin/plans',
            default => '/movies',
        };
    }

    // ── Mutators ──────────────────────────────────────────────
    public function setPasswordAttribute($password)
    {
        $this->attributes['password'] = bcrypt($password);
        // Stamp every password write — covers registration, profile change,
        // password reset, and admin-driven resets. The migration adds the
        // column nullable so historical rows simply read NULL.
        $this->attributes['password_changed_at'] = now();
    }

    /**
     * National ID (KTP/NIK) — never persisted in plaintext OR via Laravel's
     * encrypted cast (which is reversible from APP_KEY). We store ONLY a
     * peppered SHA-256 hash so the value is one-way and lookup is exact.
     *
     * Canonicalization: strip whitespace + lowercase. This must match what
     * `findByNationalId()` does or the lookup will silently miss.
     */
    public function setNationalIdAttribute(string $value): void
    {
        $hash = self::hashNationalId($value);
        $this->attributes['national_id_hash'] = $hash;
        // Defensive: also unset any accidental `national_id` key so it
        // never reaches the DB (the column does not exist).
        unset($this->attributes['national_id']);
    }

    /**
     * Lookup by exact national ID. Returns null when no match.
     *
     * NB: This is the ONLY supported way to query by national ID — the
     * raw value is never on disk, so `where('national_id', ...)` is
     * impossible by design.
     */
    public static function findByNationalId(string $value): ?self
    {
        $hash = self::hashNationalId($value);

        return static::query()
            ->where('national_id_hash', $hash)
            ->first();
    }

    /**
     * Canonicalize + hash a national ID with the app pepper.
     *
     * Pepper sources (in order of preference):
     *   1. `PII_PEPPER` env (rotation-friendly: change pepper, re-hash all rows)
     *   2. APP_KEY (default fallback so the app works out of the box)
     *
     * Using a pepper means a stolen DB cannot be brute-forced against a
     * dictionary of NIKs without ALSO stealing the .env file.
     */
    public static function hashNationalId(string $value): string
    {
        $canonical = strtolower(preg_replace('/\s+/', '', $value) ?? $value);

        $pepper = (string) (env('PII_PEPPER') ?: Config::get('app.key', ''));

        return hash('sha256', $canonical . '|' . $pepper);
    }

    // ── Relations ─────────────────────────────────────────────
    public function watchlist()
    {
        return $this->hasMany(Watchlist::class);
    }

    public function watchlistMovies()
    {
        return $this->belongsToMany(Movie::class, 'watchlists')->withTimestamps();
    }

    public function watchHistories()
    {
        return $this->hasMany(WatchHistory::class);
    }

    public function ratings()
    {
        return $this->hasMany(Rating::class);
    }

    public function comments()
    {
        return $this->hasMany(Comment::class);
    }

    public function subscription()
    {
        return $this->hasOne(Subscription::class)->active()->latest();
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function coins()
    {
        return $this->hasMany(Coin::class);
    }

    public function level()
    {
        return $this->hasOne(UserLevel::class);
    }

    public function achievements()
    {
        return $this->belongsToMany(Achievement::class, 'user_achievements')
            ->withPivot('unlocked_at')
            ->withTimestamps();
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class)->latest();
    }

    // ── Helpers ───────────────────────────────────────────────
    public function getCoinBalanceAttribute(): int
    {
        return Coin::balanceFor($this->id);
    }

    public function hasInWatchlist(int $movieId): bool
    {
        return $this->watchlist()->where('movie_id', $movieId)->exists();
    }

    public function hasRated(int $movieId): bool
    {
        return $this->ratings()->where('movie_id', $movieId)->exists();
    }

    public function getRatingFor(int $movieId): ?Rating
    {
        return $this->ratings()->where('movie_id', $movieId)->first();
    }

    public function getOrCreateLevel(): UserLevel
    {
        return $this->level ?? UserLevel::create([
            'user_id' => $this->id,
            'level' => 1,
            'xp' => 0,
            'total_coins' => 0,
        ]);
    }

    public function unreadNotificationCount(): int
    {
        return $this->notifications()->unread()->count();
    }

    public function hasActiveSubscription(): bool
    {
        return $this->subscriptions()->active()->exists();
    }

    public function currentPlan(): ?SubscriptionPlan
    {
        $sub = $this->subscriptions()->active()->with('plan')->first();
        return $sub?->plan;
    }
}
