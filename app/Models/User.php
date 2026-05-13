<?php

namespace App\Models;

use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Auth\VerifyEmailNotification;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Config;

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

    public function hasRole(string|array $role): bool
    {
        if (is_array($role)) {
            return in_array($this->role, $role, true);
        }
        return $this->role === $role;
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN || $this->is_admin;
    }

    public function isStaff(): bool
    {
        return in_array($this->role, [
            self::ROLE_SUPER_ADMIN,
            self::ROLE_CONTENT_MANAGER,
            self::ROLE_CUSTOMER_SUPPORT,
            self::ROLE_FINANCE,
        ], true);
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
