<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single episode under a Season under a (series) Movie.
 *
 * Convention notes:
 * - `runtime_minutes` is the canonical duration column (matches the
 *   admin form); `duration_seconds` is a read-only accessor for
 *   player code that thinks in seconds (Shaka / Video.js progress).
 * - `still_path` follows the same Movie::resolveAssetUrl() conventions
 *   (absolute URL → returned verbatim, `private/...` → signed URL,
 *   anything else → public symlink). We don't add a signed-URL route
 *   here yet; admin-uploaded stills are expected on the public disk.
 */
class Episode extends Model
{
    use HasFactory;

    /**
     * Mass-assignable attributes for the admin episode form.
     *
     * `generated_summary` / `generated_summary_at` are owned by
     * App\Services\Ai\Tasks\EpisodeSummarizer (it forceFill()s those)
     * so they stay OUT of $fillable — a hand-edited admin form should
     * never silently replace an AI-curated blurb.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'season_id',
        'movie_id',
        'episode_number',
        'title',
        'overview',
        'still_path',
        'runtime_minutes',
        'air_date',
        'video_path',
        'video_disk',
        'hls_manifest_path',
        'intro_start_seconds',
        'intro_end_seconds',
        'outro_start_seconds',
    ];

    protected $casts = [
        'episode_number' => 'integer',
        'runtime_minutes' => 'integer',
        'air_date' => 'date',
        'intro_start_seconds' => 'integer',
        'intro_end_seconds' => 'integer',
        'outro_start_seconds' => 'integer',
        'generated_summary_at' => 'datetime',
    ];

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }

    public function subtitles()
    {
        return $this->hasMany(MovieSubtitle::class)->orderBy('language_code');
    }

    public function activeSubtitles()
    {
        return $this->hasMany(MovieSubtitle::class)
            ->where('is_active', true)
            ->where('status', 'ready')
            ->orderByDesc('is_default');
    }

    /**
     * Player-friendly duration (Video.js/Shaka think in seconds).
     */
    public function getDurationSecondsAttribute(): ?int
    {
        return $this->runtime_minutes !== null
            ? ((int) $this->runtime_minutes) * 60
            : null;
    }

    /**
     * Resolved still-frame URL — mirrors Movie::resolveAssetUrl()
     * shape so callers can `$ep->still_url` uniformly.
     */
    public function getStillUrlAttribute(): ?string
    {
        $path = $this->still_path;
        if (! $path) {
            return $this->movie?->backdrop_url;
        }
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }
        return asset('storage/' . $path);
    }

    /**
     * Next episode in the same season, or null on the season finale.
     * Use Movie::nextEpisodeAfter() to roll over into the next season.
     */
    public function nextInSeason(): ?Episode
    {
        return static::query()
            ->where('season_id', $this->season_id)
            ->where('episode_number', '>', $this->episode_number)
            ->orderBy('episode_number')
            ->first();
    }

    /**
     * Previous episode in the same season, or null on the premiere.
     */
    public function previousInSeason(): ?Episode
    {
        return static::query()
            ->where('season_id', $this->season_id)
            ->where('episode_number', '<', $this->episode_number)
            ->orderByDesc('episode_number')
            ->first();
    }

    /**
     * Released episodes only — air_date null OR already passed.
     * Nulls are treated as "released" so admin-seeded test data
     * (without dates) still shows up.
     */
    public function scopeReleased(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('air_date')
                ->orWhere('air_date', '<=', now()->toDateString());
        });
    }

    /**
     * Display label — "S2E5: The Trial".
     */
    public function getCodeAttribute(): string
    {
        $season = $this->season?->season_number ?? 0;
        return sprintf('S%dE%d', $season, $this->episode_number);
    }
}
