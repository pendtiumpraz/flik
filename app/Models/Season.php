<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single season of a series-flavoured Movie.
 *
 * The parent Movie carries genre/cast/banner metadata; a Season only
 * adds per-season presentation data (its own poster + air_date) and
 * acts as the parent of its Episodes. The `episodes()` relation is
 * pre-ordered by episode_number so callers don't have to remember.
 */
class Season extends Model
{
    use HasFactory;

    /**
     * Mass-assignable attributes — `episode_count` is intentionally
     * NOT here because it's a derived counter kept in sync by the
     * EpisodeController (increment on store, decrement on destroy).
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'movie_id',
        'season_number',
        'title',
        'overview',
        'poster_path',
        'air_date',
    ];

    protected $casts = [
        'season_number' => 'integer',
        'episode_count' => 'integer',
        'air_date' => 'date',
    ];

    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }

    /**
     * Episodes for this season, ordered by episode_number ASC.
     */
    public function episodes(): HasMany
    {
        return $this->hasMany(Episode::class)->orderBy('episode_number');
    }

    /**
     * Find the episode that immediately follows `$current` in this season.
     * Returns null when `$current` is the season finale (caller should
     * then look at the next season — Movie::nextEpisodeAfter handles that).
     */
    public function nextEpisode(?Episode $current): ?Episode
    {
        if ($current === null) {
            return $this->episodes()->first();
        }

        return $this->episodes()
            ->where('episode_number', '>', $current->episode_number)
            ->first();
    }

    /**
     * Display label — "Season 2: The Trial" or just "Season 2".
     */
    public function getDisplayTitleAttribute(): string
    {
        $base = 'Season ' . $this->season_number;

        return $this->title ? "{$base}: {$this->title}" : $base;
    }
}
