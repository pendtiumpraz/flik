<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Auto-generated 3-minute "best of" reel for a single movie.
 *
 * Rendered by App\Services\Ai\Tasks\HighlightReelGenerator using FFmpeg
 * select-filter concatenation of the top-scoring scene windows. The resulting
 * .mp4 is stored on the configured disk (usually `public`) under
 * `highlights/{slug}.mp4`.
 *
 * @property int                   $id
 * @property int                   $movie_id
 * @property string                $reel_path
 * @property string                $reel_disk
 * @property int                   $target_duration_seconds
 * @property int                   $scene_count
 * @property array                 $scenes_json
 * @property int|null              $file_size_bytes
 * @property string                $status            pending|processing|ready|failed
 * @property string|null           $error_message
 * @property \Carbon\Carbon|null   $generated_at
 */
class MovieHighlightReel extends Model
{
    use HasFactory;

    protected $fillable = [
        'movie_id',
        'reel_path',
        'reel_disk',
        'target_duration_seconds',
        'scene_count',
        'scenes_json',
        'file_size_bytes',
        'status',
        'error_message',
        'generated_at',
    ];

    protected $casts = [
        'target_duration_seconds' => 'integer',
        'scene_count'             => 'integer',
        'scenes_json'             => 'array',
        'file_size_bytes'         => 'integer',
        'generated_at'            => 'datetime',
    ];

    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }

    /**
     * Public-accessible URL for the rendered reel .mp4.
     */
    public function getUrlAttribute(): ?string
    {
        if (empty($this->reel_path)) {
            return null;
        }

        try {
            return Storage::disk($this->reel_disk ?: 'public')->url($this->reel_path);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Sum of clip durations from the scenes breakdown (seconds).
     */
    public function getActualDurationSecondsAttribute(): float
    {
        $total = 0.0;
        foreach ((array) $this->scenes_json as $scene) {
            $start = (float) ($scene['start'] ?? 0);
            $end   = (float) ($scene['end'] ?? 0);
            if ($end > $start) {
                $total += $end - $start;
            }
        }

        return round($total, 2);
    }

    public function isReady(): bool
    {
        return $this->status === 'ready' && !empty($this->reel_path);
    }
}
