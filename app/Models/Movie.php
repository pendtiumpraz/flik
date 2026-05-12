<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Movie extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'original_title',
        'overview',
        'poster_path',
        'backdrop_path',
        'slider_path',
        'release_date',
        'vote_average',
        'vote_count',
        'popularity',
        'is_popular',
        'is_trending',
        'video_url',
        'video_path',
        'video_disk',
        'youtube_key',
    ];

    protected static function booted()
    {
        static::saving(function (Movie $movie) {
            if (empty($movie->slug) && !empty($movie->title)) {
                $base = \Illuminate\Support\Str::slug($movie->title);
                $slug = $base;
                $i = 1;
                while (static::where('slug', $slug)->where('id', '!=', $movie->id ?? 0)->exists()) {
                    $slug = $base . '-' . (++$i);
                }
                $movie->slug = $slug;
            }
        });
    }

    public function getRouteKeyName()
    {
        return 'slug';
    }

    protected $casts = [
        'release_date' => 'date',
        'vote_average' => 'decimal:1',
        'is_popular' => 'boolean',
        'is_trending' => 'boolean',
        'ai_tags' => 'array',
        'ai_metadata' => 'array',
        'ai_tagged_at' => 'datetime',
        'ai_synopsis_generated_at' => 'datetime',
        'ai_short_summary_generated_at' => 'datetime',
        'seo_generated_at' => 'datetime',
    ];

    /**
     * Get the full video URL (supports local storage + future S3).
     */
    public function getVideoFullUrlAttribute(): ?string
    {
        if (!$this->video_path) return $this->video_url;

        if ($this->video_disk === 's3') {
            return \Storage::disk('s3')->url($this->video_path);
        }

        return asset('storage/' . $this->video_path);
    }

    public function hasVideo(): bool
    {
        return !empty($this->video_path) || !empty($this->video_url) || !empty($this->youtube_key);
    }

    /**
     * Get the genres for the movie.
     */
    public function genres()
    {
        return $this->belongsToMany(Genre::class, 'genre_movie');
    }

    /**
     * Get the cast members for the movie.
     */
    public function castMembers()
    {
        return $this->belongsToMany(Cast::class, 'cast_movie')
            ->withPivot('character', 'order')
            ->orderBy('cast_movie.order');
    }

    /**
     * Scope for popular movies.
     */
    public function scopePopular($query)
    {
        return $query->where('is_popular', true)->orderByDesc('popularity');
    }

    /**
     * Scope for trending movies.
     */
    public function scopeTrending($query)
    {
        return $query->where('is_trending', true)->orderByDesc('popularity');
    }

    /**
     * Scope for movies by genre slug.
     */
    public function scopeByGenre($query, $genreSlug)
    {
        return $query->whereHas('genres', function ($q) use ($genreSlug) {
            $q->where('slug', $genreSlug);
        });
    }

    /**
     * Get the poster URL.
     * Returns full URL if it starts with http, otherwise prepends storage path.
     */
    public function getPosterUrlAttribute()
    {
        if (!$this->poster_path) {
            return '/images/no-poster.png';
        }

        if (str_starts_with($this->poster_path, 'http')) {
            return $this->poster_path;
        }

        return asset('storage/' . $this->poster_path);
    }

    /**
     * Get the backdrop URL.
     */
    public function getBackdropUrlAttribute()
    {
        if (!$this->backdrop_path) {
            return $this->poster_url;
        }

        if (str_starts_with($this->backdrop_path, 'http')) {
            return $this->backdrop_path;
        }

        return asset('storage/' . $this->backdrop_path);
    }

    /**
     * Get the cinematic slider/hero URL.
     * Falls back to backdrop_url, then poster_url.
     * Recommended dimensions: 1920×800 (~2.4:1 cinematic widescreen).
     */
    public function getSliderUrlAttribute()
    {
        if (!$this->slider_path) {
            return $this->backdrop_url;
        }

        if (str_starts_with($this->slider_path, 'http')) {
            return $this->slider_path;
        }

        return asset('storage/' . $this->slider_path);
    }

    // ── Additional Relations ──────────────────────────────────

    public function ratings()
    {
        return $this->hasMany(Rating::class);
    }

    public function comments()
    {
        return $this->hasMany(Comment::class);
    }

    public function watchlistUsers()
    {
        return $this->belongsToMany(User::class, 'watchlists')->withTimestamps();
    }

    public function watchHistories()
    {
        return $this->hasMany(WatchHistory::class);
    }

    public function assets()
    {
        return $this->hasMany(MovieAsset::class);
    }

    public function trivia()
    {
        return $this->hasMany(MovieTrivia::class);
    }

    public function quotes()
    {
        return $this->hasMany(MovieQuote::class);
    }

    public function aiReviews()
    {
        return $this->hasMany(MovieAiReview::class);
    }

    public function subtitles()
    {
        return $this->hasMany(MovieSubtitle::class);
    }

    public function activeSubtitles()
    {
        return $this->hasMany(MovieSubtitle::class)
            ->where('is_active', true)
            ->where('status', 'ready')
            ->orderByDesc('is_default');
    }

    /**
     * Get currently active variant for given asset type, with time-bucketed rotation.
     *
     * Behavior:
     * - If movie has multiple active assets of $type → picks one based on time bucket
     *   (so all users see SAME variant within the same time window — CDN-cacheable)
     * - If single asset → uses it
     * - If no assets → returns NULL (caller falls back to legacy column)
     *
     * Time bucket = floor(now / rotation_hours) % count(active assets)
     */
    public function currentAssetUrl(string $type): ?string
    {
        $assets = $this->assets()
            ->ofType($type)
            ->active()
            ->orderBy('sort_order')
            ->get();

        if ($assets->isEmpty()) {
            return null;
        }

        if ($assets->count() === 1) {
            return $assets->first()->url;
        }

        // Determine rotation interval (use first asset's setting, fallback 1 hour)
        $rotationHours = max(1, $assets->first()->rotation_hours ?: 1);

        // Time-bucketed deterministic selection
        $bucket = (int) floor(now()->timestamp / ($rotationHours * 3600)) % $assets->count();

        return $assets->values()[$bucket]->url;
    }

    /**
     * Effective URL for hero slider — rotating asset OR legacy slider_path.
     */
    public function getEffectiveSliderUrlAttribute(): string
    {
        return $this->currentAssetUrl(MovieAsset::TYPE_SLIDER) ?? $this->slider_url;
    }

    /**
     * Effective URL for poster (card) — rotating asset OR legacy poster_path.
     */
    public function getEffectivePosterUrlAttribute(): string
    {
        return $this->currentAssetUrl(MovieAsset::TYPE_POSTER) ?? $this->poster_url;
    }

    /**
     * Effective URL for backdrop — rotating asset OR legacy backdrop_path.
     */
    public function getEffectiveBackdropUrlAttribute(): string
    {
        return $this->currentAssetUrl(MovieAsset::TYPE_BACKDROP) ?? $this->backdrop_url;
    }

    // ── Accessors ─────────────────────────────────────────────

    public function getAverageRatingAttribute(): float
    {
        return round($this->ratings()->avg('score') ?? 0, 1);
    }

    public function getRatingsCountAttribute(): int
    {
        return $this->ratings()->count();
    }

    // ── Swarm 25 relations (behind-the-scenes + highlight reels) ──────

    /**
     * Up to 6 narrative behind-the-scenes sections, ordered by sort_order.
     */
    public function behindScenes()
    {
        return $this->hasMany(MovieBehindScenes::class)->orderBy('sort_order');
    }

    /**
     * Auto-generated highlight reels (status: pending|processing|ready|failed).
     */
    public function highlightReels()
    {
        return $this->hasMany(MovieHighlightReel::class);
    }

    /**
     * Cinematography / colour analysis (one row per movie).
     * Populated by App\Services\Ai\Tasks\CinematographyAnalyzer.
     */
    public function cinematography()
    {
        return $this->hasOne(MovieCinematography::class);
    }
}
