<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * KnownDevice
 * --------------------------------------------------------------------------
 * Per-user device fingerprint registry. Backs the "new device login" alert
 * flow (see App\Services\Security\LoginAlertService).
 *
 * @property int                              $id
 * @property int                              $user_id
 * @property string                           $device_fingerprint
 * @property string                           $ip
 * @property string|null                      $country
 * @property string|null                      $user_agent
 * @property \Illuminate\Support\Carbon|null  $first_seen_at
 * @property \Illuminate\Support\Carbon|null  $last_seen_at
 * @property bool                             $trusted
 */
class KnownDevice extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'device_fingerprint',
        'ip',
        'country',
        'user_agent',
        'first_seen_at',
        'last_seen_at',
        'trusted',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at'  => 'datetime',
        'trusted'       => 'boolean',
        // GDPR — IP addresses are personal data. Encrypt at rest so a
        // database leak does not directly expose the per-user IP history.
        // Column is TEXT (see migration 2026_05_10_040100). NOTE: this
        // means we cannot WHERE on `ip` — for "find devices by IP" we'd
        // need a peppered hash column (deferred until a real use case).
        'ip'            => 'encrypted',
    ];

    // ── Relations ─────────────────────────────────────────────
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Scopes ────────────────────────────────────────────────
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeTrusted(Builder $query): Builder
    {
        return $query->where('trusted', true);
    }

    public function scopeUntrusted(Builder $query): Builder
    {
        return $query->where('trusted', false);
    }

    // ── Helpers ───────────────────────────────────────────────
    /**
     * Best-effort short label for the device row, used in emails and the
     * trusted-device list. Falls back gracefully when UA parsing yields
     * nothing useful.
     */
    public function getDisplayNameAttribute(): string
    {
        $ua = (string) ($this->user_agent ?? '');

        if ($ua === '') {
            return 'Unknown device';
        }

        // Cheap UA sniff — keeps things dependency-free.
        $os = match (true) {
            str_contains($ua, 'Windows')      => 'Windows',
            str_contains($ua, 'Mac OS X')     => 'macOS',
            str_contains($ua, 'iPhone')       => 'iPhone',
            str_contains($ua, 'iPad')         => 'iPad',
            str_contains($ua, 'Android')      => 'Android',
            str_contains($ua, 'Linux')        => 'Linux',
            default                           => 'Unknown OS',
        };

        $browser = match (true) {
            str_contains($ua, 'Edg/')         => 'Edge',
            str_contains($ua, 'OPR/'),
            str_contains($ua, 'Opera')        => 'Opera',
            str_contains($ua, 'Chrome/')      => 'Chrome',
            str_contains($ua, 'Firefox/')     => 'Firefox',
            str_contains($ua, 'Safari/')      => 'Safari',
            default                           => 'Browser',
        };

        return "{$browser} on {$os}";
    }
}
