<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserLevel extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'level', 'xp', 'total_coins', 'watch_streak', 'last_streak_date'];

    protected $casts = [
        'last_streak_date' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getXpForNextLevelAttribute(): int
    {
        return $this->level * 100; // Level 1 = 100 XP, Level 2 = 200 XP, etc.
    }

    public function getXpProgressPercentAttribute(): int
    {
        $needed = $this->xp_for_next_level;
        if ($needed == 0) return 100;
        return min(100, (int) round(($this->xp / $needed) * 100));
    }

    public function addXp(int $amount): void
    {
        $this->xp += $amount;
        while ($this->xp >= $this->xp_for_next_level) {
            $this->xp -= $this->xp_for_next_level;
            $this->level++;
        }
        $this->save();
    }
}
