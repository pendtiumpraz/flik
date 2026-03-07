<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WatchHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'movie_id', 'progress_seconds',
        'duration_seconds', 'completed', 'last_watched_at',
    ];

    protected $casts = [
        'completed' => 'boolean',
        'last_watched_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function movie()
    {
        return $this->belongsTo(Movie::class);
    }

    public function getProgressPercentAttribute(): int
    {
        if (!$this->duration_seconds || $this->duration_seconds == 0) return 0;
        return (int) round(($this->progress_seconds / $this->duration_seconds) * 100);
    }
}
