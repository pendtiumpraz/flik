<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Audit row for every POST /login (success or failure).
 *
 * Used by App\Services\Security\LoginThrottle to compute lockout state
 * and progressive delay. Persisted to the `login_attempts` table.
 *
 * Note: we explicitly disable Eloquent timestamps because the only time
 * column we care about is `attempted_at` (set by the DB default). This
 * keeps the schema lean and makes the (email, attempted_at) index the
 * authoritative ordering key.
 */
class LoginAttempt extends Model
{
    public $timestamps = false;

    /**
     * SECURITY: login_attempts is a server-trust-only audit table written
     * exclusively by App\Services\Security\LoginThrottle::record(). Guarding
     * everything stops a request body ever forging an attempt row that
     * could mask a brute-force lockout.
     *
     * @var array<int, string>
     */
    protected $guarded = ['*'];

    protected $casts = [
        'success'      => 'boolean',
        'attempted_at' => 'datetime',
    ];

    // ── Scopes ────────────────────────────────────────────────────────

    public function scopeForEmail(Builder $query, string $email): Builder
    {
        return $query->where('email', mb_strtolower(trim($email)));
    }

    public function scopeFromIp(Builder $query, string $ip): Builder
    {
        return $query->where('ip', $ip);
    }

    /**
     * Restrict to attempts within the last N minutes (relative to now()).
     */
    public function scopeRecentMinutes(Builder $query, int $minutes): Builder
    {
        return $query->where('attempted_at', '>=', now()->subMinutes(max(1, $minutes)));
    }
}
