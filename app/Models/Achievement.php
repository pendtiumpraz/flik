<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Achievement extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'slug', 'description', 'icon', 'coin_reward', 'xp_reward',
        'condition_type', 'condition_value', 'tier', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_achievements')->withPivot('unlocked_at')->withTimestamps();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function getTierColorAttribute(): string
    {
        return match($this->tier) {
            'platinum' => '#E5E4E2',
            'gold' => '#C5A55A',
            'silver' => '#C0C0C0',
            default => '#CD7F32',
        };
    }
}
