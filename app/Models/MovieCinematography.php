<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cinematography / colour analysis for a single Movie.
 *
 * One row per movie (movie_id is unique). Populated by
 * App\Services\Ai\Tasks\CinematographyAnalyzer.
 *
 * @property int                 $id
 * @property int                 $movie_id
 * @property array|null          $color_palette          Array of {hex, weight}
 * @property string|null         $lighting_style
 * @property string|null         $composition_style
 * @property array|null          $mood_descriptors       Array of short strings
 * @property string|null         $narrative_summary      ~150 word Indonesian
 * @property array|null          $sample_keyframes_paths Storage-relative paths
 * @property \Carbon\Carbon|null $generated_at
 */
class MovieCinematography extends Model
{
    use HasFactory;

    protected $table = 'movie_cinematography';

    protected $fillable = [
        'movie_id',
        'color_palette',
        'lighting_style',
        'composition_style',
        'mood_descriptors',
        'narrative_summary',
        'sample_keyframes_paths',
        'generated_at',
    ];

    protected $casts = [
        'movie_id'               => 'integer',
        'color_palette'          => 'array',
        'mood_descriptors'       => 'array',
        'sample_keyframes_paths' => 'array',
        'generated_at'           => 'datetime',
    ];

    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }

    /**
     * Whether this record actually carries usable analysis (vs. a placeholder
     * row written when the source video was unavailable).
     */
    public function hasAnalysis(): bool
    {
        return !empty($this->color_palette)
            || !empty($this->narrative_summary)
            || !empty($this->mood_descriptors);
    }
}
