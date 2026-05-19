<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pre-aggregated trending cache. Truncated + re-inserted per window
 * by App\Services\Trending\TrendingAggregator — DO NOT update rows
 * piecemeal; the aggregator owns the table.
 *
 * @property int $id
 * @property int $movie_id
 * @property string $window '1h'|'24h'|'7d'|'30d'
 * @property int $view_count
 * @property int $unique_viewer_count
 * @property float $score
 * @property int $rank
 * @property \Illuminate\Support\Carbon|null $computed_at
 */
class TrendingMovie extends Model
{
    use HasFactory;

    /** @var array<int, string> */
    protected $fillable = [
        'movie_id',
        'window',
        'view_count',
        'unique_viewer_count',
        'score',
        'rank',
        'computed_at',
    ];

    protected $casts = [
        'view_count' => 'integer',
        'unique_viewer_count' => 'integer',
        'score' => 'float',
        'rank' => 'integer',
        'computed_at' => 'datetime',
    ];

    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }

    /**
     * Canonical query for the UI: rows for a window, sorted by rank
     * (which is itself score-descending after aggregation).
     */
    public function scopeForWindow(Builder $query, string $window): Builder
    {
        return $query->where('window', $window)->orderBy('rank');
    }
}
