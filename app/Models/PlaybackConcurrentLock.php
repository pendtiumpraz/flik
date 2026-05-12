<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Concurrent-stream enforcement row.
 *
 * Backed by `playback_concurrent_locks` (migration 2026_05_10_020004).
 * One row per active playback session. Players heartbeat regularly to
 * extend `expires_at`; rows past `expires_at` are stale and may be GC'd.
 *
 * The number of active (non-expired) rows for a given user is the
 * canonical concurrent-stream count, enforced against the user's plan.
 *
 * @property int $id
 * @property int $user_id
 * @property string $session_token
 * @property int $movie_id
 * @property \Illuminate\Support\Carbon $heartbeat_at
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class PlaybackConcurrentLock extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'session_token',
        'movie_id',
        'heartbeat_at',
        'expires_at',
    ];

    protected $casts = [
        'heartbeat_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }

    /**
     * Scope: locks that have NOT expired (still considered active streams).
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }
}
