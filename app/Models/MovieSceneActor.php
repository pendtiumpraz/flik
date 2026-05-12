<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * X-Ray scene presence: an actor visible in a time-window of a movie,
 * optionally with on-screen coordinates for clickable hotspots.
 *
 * @property int $id
 * @property int $movie_id
 * @property int $cast_id
 * @property string $start_seconds
 * @property string $end_seconds
 * @property string|null $screen_x
 * @property string|null $screen_y
 * @property string $confidence
 */
class MovieSceneActor extends Model
{
    use HasFactory;

    protected $fillable = [
        'movie_id',
        'cast_id',
        'start_seconds',
        'end_seconds',
        'screen_x',
        'screen_y',
        'confidence',
    ];

    protected $casts = [
        'start_seconds' => 'decimal:3',
        'end_seconds'   => 'decimal:3',
        'screen_x'      => 'decimal:2',
        'screen_y'      => 'decimal:2',
        'confidence'    => 'decimal:2',
    ];

    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }

    public function cast(): BelongsTo
    {
        return $this->belongsTo(Cast::class);
    }

    /**
     * Scope: presences active at the given playback second.
     */
    public function scopeActiveAt($query, float $second)
    {
        return $query
            ->where('start_seconds', '<=', $second)
            ->where('end_seconds', '>=', $second);
    }
}
