<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only event log of streak-day records — one row per (user, date).
 *
 * Written by StreakService::recordWatch. Backs the streak calendar /
 * "fire days" timeline on the profile page so we never have to recompute
 * from raw watch_histories.
 */
class StreakHistoryEntry extends Model
{
    use HasFactory;

    protected $table = 'streak_history';

    protected $fillable = [
        'user_id',
        'date',
        'streak_after_day',
        'reward_xp',
        'reward_coins',
        'milestone',
    ];

    protected $casts = [
        'date'             => 'date',
        'streak_after_day' => 'integer',
        'reward_xp'        => 'integer',
        'reward_coins'     => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
