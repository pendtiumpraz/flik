<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Per-user daily-watch streak record.
 *
 * One row per user (enforced by the unique FK in the migration). Written by
 * App\Services\Gamification\StreakService. Read by the home-page widget,
 * profile, and /leaderboards/streaks.
 */
class WatchStreak extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'current_streak',
        'longest_streak',
        'last_watch_date',
        'freeze_credits',
        'updated_at',
    ];

    protected $casts = [
        'current_streak'  => 'integer',
        'longest_streak'  => 'integer',
        'last_watch_date' => 'date',
        'freeze_credits'  => 'integer',
        'updated_at'      => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(StreakHistoryEntry::class, 'user_id', 'user_id');
    }

    /**
     * Has this user already counted a watch today? Used by the home widget
     * to switch between "Don't break the streak!" and "Streak safe" messaging.
     * `last_watch_date` is normalised to Jakarta date by StreakService.
     */
    public function hasWatchedToday(\Carbon\CarbonInterface $today): bool
    {
        return $this->last_watch_date !== null
            && $this->last_watch_date->isSameDay($today);
    }
}
