<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovieTrailerSuggestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'movie_id',
        'start_seconds',
        'end_seconds',
        'duration_seconds',
        'score',
        'reason',
        'audio_intensity',
        'is_selected',
    ];

    protected $casts = [
        'start_seconds'    => 'float',
        'end_seconds'      => 'float',
        'duration_seconds' => 'float',
        'score'            => 'decimal:2',
        'audio_intensity'  => 'decimal:2',
        'is_selected'      => 'boolean',
    ];

    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }

    /**
     * Format start as HH:MM:SS for display.
     */
    public function getStartTimecodeAttribute(): string
    {
        return $this->formatSeconds((float) $this->start_seconds);
    }

    public function getEndTimecodeAttribute(): string
    {
        return $this->formatSeconds((float) $this->end_seconds);
    }

    protected function formatSeconds(float $seconds): string
    {
        $h = (int) floor($seconds / 3600);
        $m = (int) floor(fmod($seconds, 3600) / 60);
        $s = (int) floor(fmod($seconds, 60));

        return sprintf('%02d:%02d:%02d', $h, $m, $s);
    }
}
