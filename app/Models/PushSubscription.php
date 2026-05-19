<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PushSubscription
 * --------------------------------------------------------------------------
 * Web Push subscription registry row (one per browser/device/user). See
 * the matching migration docblock for lifecycle details.
 *
 * @property int                                              $id
 * @property int|null                                         $user_id
 * @property string                                           $endpoint
 * @property string                                           $p256dh
 * @property string                                           $auth_key
 * @property string|null                                      $user_agent
 * @property string|null                                      $device_type
 * @property \Illuminate\Support\Carbon|null                  $last_used_at
 * @property int                                              $failure_count
 * @property \Illuminate\Support\Carbon|null                  $created_at
 * @property \Illuminate\Support\Carbon|null                  $updated_at
 * @property-read \App\Models\User|null                       $user
 */
class PushSubscription extends Model
{
    use HasFactory;

    /**
     * Service-layer writes only — the subscribe controller upserts via the
     * model; admin UI never edits these directly.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'endpoint',
        'endpoint_hash',
        'p256dh',
        'auth_key',
        'user_agent',
        'device_type',
        'last_used_at',
        'failure_count',
    ];

    protected $casts = [
        'last_used_at'  => 'datetime',
        'failure_count' => 'integer',
    ];

    /** Anything ≥ this many consecutive failures gets pruned by the broadcaster. */
    public const MAX_FAILURE_COUNT = 5;

    /**
     * Recompute `endpoint_hash` whenever `endpoint` is dirty. The hash backs
     * the unique index that serves as our upsert key (the endpoint URL itself
     * is too long to index directly under MySQL utf8mb4 row-size limits).
     */
    protected static function booted(): void
    {
        static::saving(function (self $sub) {
            if ($sub->endpoint !== null && ($sub->isDirty('endpoint') || $sub->endpoint_hash === null)) {
                $sub->endpoint_hash = sha1((string) $sub->endpoint);
            }
        });
    }

    // ── Relations ────────────────────────────────────────────────

    /** @return BelongsTo<User, self> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Scopes ───────────────────────────────────────────────────

    /**
     * Resolve the subscription set matching the {@see PushMessage::$audience}
     * encoding. Unknown audience strings fall back to "all" so a typo can
     * never silently address zero people.
     */
    public function scopeForAudience(Builder $query, string $audience): Builder
    {
        if ($audience === '' || $audience === 'all') {
            return $query;
        }

        // role:rolename
        if (str_starts_with($audience, 'role:')) {
            $roleName = substr($audience, 5);
            if ($roleName === '') {
                return $query;
            }

            return $query->whereHas('user.roles', function (Builder $q) use ($roleName) {
                $q->where('name', $roleName);
            });
        }

        // user:<id>
        if (str_starts_with($audience, 'user:')) {
            $userId = (int) substr($audience, 5);

            return $query->where('user_id', $userId);
        }

        // segment:<name> — extension hook. For now we just match on the
        // canonical segments we know about; unknown segments degrade to "all".
        if (str_starts_with($audience, 'segment:')) {
            $segment = substr($audience, 8);

            return match ($segment) {
                'authenticated' => $query->whereNotNull('user_id'),
                'anonymous'     => $query->whereNull('user_id'),
                default         => $query,
            };
        }

        return $query;
    }

    /**
     * Subscriptions that have NOT exceeded the failure budget. Used by the
     * broadcaster so we don't keep hammering known-dead endpoints.
     */
    public function scopeHealthy(Builder $query): Builder
    {
        return $query->where('failure_count', '<', self::MAX_FAILURE_COUNT);
    }

    // ── Helpers ──────────────────────────────────────────────────

    public function markDelivered(): void
    {
        $this->forceFill([
            'last_used_at'  => now(),
            'failure_count' => 0,
        ])->save();
    }

    public function markFailed(): void
    {
        $this->forceFill([
            'failure_count' => $this->failure_count + 1,
        ])->save();
    }
}
