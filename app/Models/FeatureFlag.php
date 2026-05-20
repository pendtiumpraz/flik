<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;

/**
 * Feature flag definition + evaluation.
 *
 * Strategies (matching the {@see strategy_config} column shape):
 *   - off         → always false (regardless of is_enabled)
 *   - on          → always true (when is_enabled)
 *   - role        → user has ANY role listed in `strategy_config.roles`
 *   - percentage  → deterministic bucket from
 *                   `strategy_config.percentage` (0-100). Same user always
 *                   gets the same answer so the rollout cohort is stable
 *                   across requests.
 *   - users       → user id is in `strategy_config.user_ids`
 *   - authed      → user is logged in
 *   - guests      → user is null (anonymous visitor)
 *
 * Evaluation is cached per (key, user_id|guest-session-id) for 5 minutes
 * so a flag in a hot path doesn't add a DB round-trip per request. The
 * cache key changes the moment the underlying flag is saved (see boot()).
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property bool $is_enabled
 * @property string $strategy
 * @property array<string,mixed>|null $strategy_config
 * @property \Illuminate\Support\Carbon|null $rollout_started_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class FeatureFlag extends Model
{
    use HasFactory;

    /**
     * Guarded = [] is safe here because the only writers are admin
     * controllers + seeders (no end-user POST hits this model). The
     * admin controller validates each field before assigning.
     */
    protected $guarded = [];

    protected $casts = [
        'is_enabled' => 'boolean',
        'strategy_config' => 'array',
        'rollout_started_at' => 'datetime',
    ];

    /** All strategies the evaluator understands. */
    public const STRATEGIES = ['off', 'on', 'role', 'percentage', 'users', 'authed', 'guests'];

    private const CACHE_TTL_SECONDS = 300; // 5 minutes

    /**
     * Bust the per-flag evaluation cache whenever a flag is written or
     * deleted. We can't enumerate every (key, user_id) cache hit cheaply,
     * so we burn an integer "version" tag and prefix subsequent reads
     * with it — old entries become unreachable and age out naturally.
     */
    protected static function booted(): void
    {
        $invalidate = function (self $flag): void {
            Cache::forget('feature_flag.row.' . $flag->key);
            // Bump the version sentinel — every cache key for this flag
            // is read through versionFor($key), so a bump invalidates
            // every previously-cached evaluation in O(1).
            Cache::increment('feature_flag.version.' . $flag->key);
        };

        static::saved($invalidate);
        static::deleted($invalidate);
    }

    /**
     * Evaluate whether a flag is on for the given (or current) user.
     *
     * Errors degrade to `false` — a flag that throws should not bring
     * a page down. Errors are logged once per request via Log::warning.
     */
    public function evaluate(?User $user = null): bool
    {
        // Master switch beats every strategy.
        if (! $this->is_enabled) {
            return false;
        }

        // Per-evaluation cache key. Guests bucket under their session id
        // so the percentage strategy stays stable across their visit.
        $userKey = $user?->id ?? 'guest:' . $this->guestBucketId();
        $version = (int) (Cache::get('feature_flag.version.' . $this->key, 0));
        $cacheKey = sprintf('feature_flag.eval.%s.v%d.%s', $this->key, $version, $userKey);

        return (bool) Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($user): bool {
            try {
                return $this->resolveStrategy($user);
            } catch (\Throwable $e) {
                Log::warning('FeatureFlag evaluation failed', [
                    'key' => $this->key,
                    'strategy' => $this->strategy,
                    'message' => $e->getMessage(),
                ]);

                return false;
            }
        });
    }

    /**
     * Inner evaluator — pure function of the flag config + user. No I/O
     * other than reading the user's roles relation.
     */
    private function resolveStrategy(?User $user): bool
    {
        $config = $this->strategy_config ?? [];

        return match ($this->strategy) {
            'on' => true,
            'off' => false,
            'authed' => $user !== null,
            'guests' => $user === null,
            'role' => $this->evaluateRoles($user, $config),
            'users' => $this->evaluateUserIds($user, $config),
            'percentage' => $this->evaluatePercentage($user, $config),
            default => false, // unknown strategy ⇒ safest is "off"
        };
    }

    /** @param  array<string,mixed>  $config */
    private function evaluateRoles(?User $user, array $config): bool
    {
        if ($user === null) {
            return false;
        }

        $roles = $config['roles'] ?? [];
        if (! is_array($roles) || $roles === []) {
            return false;
        }

        // User::hasRole accepts an array and degrades gracefully if the
        // roles pivot is missing (fresh install pre-RBAC).
        return $user->hasRole($roles);
    }

    /** @param  array<string,mixed>  $config */
    private function evaluateUserIds(?User $user, array $config): bool
    {
        if ($user === null) {
            return false;
        }

        $ids = $config['user_ids'] ?? [];
        if (! is_array($ids)) {
            return false;
        }

        // Tolerate string ids in the JSON blob (admin form posts strings).
        $ids = array_map(static fn ($id): int => (int) $id, $ids);

        return in_array((int) $user->id, $ids, true);
    }

    /**
     * Deterministic percentage rollout.
     *
     * For a given (flag key, user identifier) pair the hash always
     * returns the same bucket — so a user inside a 25% rollout stays
     * inside it on every request without us needing to persist
     * assignment tables.
     *
     * Hashing on `key . ':' . id` rather than just `id` means two
     * separate 25% flags don't end up rolling out to the SAME 25%
     * of users, which would defeat the point of having multiple flags.
     *
     * @param  array<string,mixed>  $config
     */
    private function evaluatePercentage(?User $user, array $config): bool
    {
        $percentage = (int) ($config['percentage'] ?? 0);
        if ($percentage <= 0) {
            return false;
        }
        if ($percentage >= 100) {
            return true;
        }

        $identifier = $user?->id !== null
            ? 'u:' . (int) $user->id
            : 'g:' . $this->guestBucketId();

        // crc32 → 0..(2^32 - 1). Modulo 100 gives a uniform bucket.
        // The cast to int avoids the platform-dependent unsigned issue
        // on 32-bit PHP (always 64-bit on supported PHP 8.2+ anyway).
        $bucket = (int) (sprintf('%u', crc32($this->key . ':' . $identifier)) % 100);

        return $bucket < $percentage;
    }

    /**
     * A stable id for an anonymous visitor so the percentage strategy
     * stays consistent across their requests. We piggyback on the
     * session id when available; otherwise fall back to a per-request
     * sentinel (which means cold cache → 50/50 per request, acceptable
     * for the rare no-session case like CLI calls).
     */
    private function guestBucketId(): string
    {
        try {
            if (Session::isStarted()) {
                return Session::getId();
            }
        } catch (\Throwable) {
            // No session bound (CLI / queue worker) — fall through.
        }

        return 'no-session';
    }

    // ── Static convenience helpers ────────────────────────────────

    /**
     * Find a flag by key, returning null when missing. Caches the row
     * for 5 minutes so repeated lookups don't hit the DB; cache is
     * invalidated by the saved/deleted observers above.
     */
    public static function findByKey(string $key): ?self
    {
        // Defensive — return null when the table hasn't been migrated
        // yet (fresh install, CI without DB) so callers degrade to
        // their documented default rather than blowing up.
        if (! Schema::hasTable('feature_flags')) {
            return null;
        }

        $cacheKey = 'feature_flag.row.' . $key;

        $row = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($key) {
            return static::query()->where('key', $key)->first();
        });

        // Cache::remember serialises Eloquent models, but a re-attached
        // model has no fresh DB connection bound. That's fine for read-
        // only evaluation but we still hand back an instance.
        return $row instanceof self ? $row : null;
    }
}
