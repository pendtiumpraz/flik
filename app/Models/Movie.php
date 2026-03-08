<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Movie extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'original_title',
        'overview',
        'poster_path',
        'backdrop_path',
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

    protected $casts = [
        'release_date' => 'date',
        'vote_average' => 'decimal:1',
        'is_popular' => 'boolean',
        'is_trending' => 'boolean',
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

    // ── Accessors ─────────────────────────────────────────────

    public function getAverageRatingAttribute(): float
    {
        return round($this->ratings()->avg('score') ?? 0, 1);
    }

    public function getRatingsCountAttribute(): int
    {
        return $this->ratings()->count();
    }
}
