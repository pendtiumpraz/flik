<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Service-to-service API key.
 *
 * Never holds the plaintext token — only sha256(plaintext) in `key_hash`.
 * Issuance happens through {@see \App\Services\Security\ApiKeyService::generate()}
 * which is the ONLY place that returns the plaintext (once, to the admin).
 *
 * @property int                                              $id
 * @property string                                           $name
 * @property string                                           $key_hash
 * @property string                                           $key_prefix
 * @property array<int,string>|null                           $abilities
 * @property \Illuminate\Support\Carbon|null                  $last_used_at
 * @property string|null                                      $last_used_ip
 * @property \Illuminate\Support\Carbon|null                  $expires_at
 * @property \Illuminate\Support\Carbon|null                  $revoked_at
 * @property int|null                                         $created_by_user_id
 * @property \Illuminate\Support\Carbon|null                  $created_at
 * @property \Illuminate\Support\Carbon|null                  $updated_at
 * @property-read \App\Models\User|null                       $creator
 */
class ApiKey extends Model
{
    use HasFactory;

    /** Length of the visible prefix portion of the plaintext key (incl. "flk_"). */
    public const PREFIX_LENGTH = 8;

    /** Plaintext key prefix — every issued key starts with "flk_". */
    public const KEY_PREFIX = 'flk_';

    protected $fillable = [
        'name',
        'key_hash',
        'key_prefix',
        'abilities',
        'last_used_at',
        'last_used_ip',
        'expires_at',
        'revoked_at',
        'created_by_user_id',
    ];

    protected $casts = [
        'abilities'    => 'array',
        'last_used_at' => 'datetime',
        'expires_at'   => 'datetime',
        'revoked_at'   => 'datetime',
    ];

    /** Hide the hash from any accidental array/JSON serialisation. */
    protected $hidden = [
        'key_hash',
    ];

    // ── Relations ─────────────────────────────────────────────
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    // ── Scopes ────────────────────────────────────────────────

    /**
     * Keys that are not revoked and not expired (i.e. usable right now).
     *
     * Driven by composite index `(revoked_at, expires_at)` from the migration.
     */
    public function scopeActive(Builder $query): Builder
    {
        $now = Carbon::now();

        return $query
            ->whereNull('revoked_at')
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            });
    }

    // ── Helpers ───────────────────────────────────────────────

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isActive(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    /**
     * Whether this key has a given ability.
     * "*" in the abilities list is treated as a wildcard.
     */
    public function can(string $ability): bool
    {
        $abilities = $this->abilities ?? [];

        return in_array('*', $abilities, true) || in_array($ability, $abilities, true);
    }

    /**
     * Stamp the last-used metadata. Called by the auth middleware on every
     * successful verify() — we save() directly rather than mass-update so
     * `updated_at` ticks too (gives admins a single "freshness" column).
     */
    public function recordUse(Request $request): void
    {
        $this->forceFill([
            'last_used_at' => Carbon::now(),
            'last_used_ip' => $request->ip(),
        ])->save();
    }
}
