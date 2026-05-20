<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;

class Movie extends Model
{
    use HasFactory;

    /**
     * Mass-assignable attributes for catalog CRUD (admin movie form).
     *
     * SECURITY: distribution + AI-pipeline columns are intentionally
     * EXCLUDED so a forged admin form (or future controller bug) cannot
     * flip encoding/DRM/geo state. Specifically NOT in $fillable:
     *   encoding_status, encoding_renditions, master_file_path,
     *   master_file_disk, drm_strategy, drm_config, hls_manifest_path,
     *   dash_manifest_path, cdn_disk, geo_allow, intro_*_seconds,
     *   outro_*_seconds, recap_end_seconds, duration_seconds, ai_tags,
     *   ai_synopsis, ai_short_summary, seo_meta, ai_*_generated_at.
     * Those land via forceFill() inside MovieUploadController, the
     * TranscodingPipeline, the DRM services, and AI Tasks/*.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'title',
        'slug',
        'original_title',
        'overview',
        'tagline',
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
        // ── Series-vs-movie discriminator (default 'movie' at the DB layer) ──
        // total_seasons / total_episodes are derived counters maintained by
        // SeasonController / EpisodeController on create/destroy.
        'content_type',
        'total_seasons',
        'total_episodes',
        'runtime_minutes',
        // ── Provenance / cross-reference (TMDB import wizard) ────────────
        // tmdb_id is the dedupe key for find-or-create-by-tmdb_id; imdb_id
        // is a bonus identifier (TMDB returns it in the same payload).
        // imported_from / imported_at stamp the row's origin so audit/debug
        // doesn't need a join into audit_logs.
        'tmdb_id',
        'imdb_id',
        'imported_from',
        'imported_at',
    ];

    protected static function booted()
    {
        static::saving(function (Movie $movie) {
            if (empty($movie->slug) && ! empty($movie->title)) {
                $base = \Illuminate\Support\Str::slug($movie->title);
                $slug = $base;
                $i = 1;
                while (static::where('slug', $slug)->where('id', '!=', $movie->id ?? 0)->exists()) {
                    $slug = $base.'-'.(++$i);
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
        'total_seasons' => 'integer',
        'total_episodes' => 'integer',
        'runtime_minutes' => 'integer',
        'tmdb_id' => 'integer',
        'imported_at' => 'datetime',
    ];

    /**
     * Get the full video URL (supports local storage + future S3).
     */
    public function getVideoFullUrlAttribute(): ?string
    {
        if (! $this->video_path) {
            return $this->video_url;
        }

        if ($this->video_disk === 's3') {
            return \Storage::disk('s3')->url($this->video_path);
        }

        return asset('storage/'.$this->video_path);
    }

    public function hasVideo(): bool
    {
        return ! empty($this->video_path) || ! empty($this->video_url) || ! empty($this->youtube_key);
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
     *
     * Resolution order:
     *   1. Empty path     → built-in placeholder.
     *   2. Absolute URL   → returned verbatim (CDN / external image).
     *   3. `private/...`  → 2-hour signed URL via the media.poster route
     *                       (file lives outside webroot).
     *   4. Anything else  → public symlink (`/storage/...`) — legacy path.
     */
    public function getPosterUrlAttribute()
    {
        return $this->resolveAssetUrl($this->poster_path, 'media.poster')
            ?? '/images/no-poster.png';
    }

    /**
     * Get the backdrop URL. See getPosterUrlAttribute() for resolution order;
     * falls back to poster_url when backdrop is empty.
     */
    public function getBackdropUrlAttribute()
    {
        return $this->resolveAssetUrl($this->backdrop_path, 'media.backdrop')
            ?? $this->poster_url;
    }

    /**
     * Get the cinematic slider/hero URL.
     * Falls back to backdrop_url, then poster_url.
     * Recommended dimensions: 1920×800 (~2.4:1 cinematic widescreen).
     */
    public function getSliderUrlAttribute()
    {
        return $this->resolveAssetUrl($this->slider_path, 'media.slider')
            ?? $this->backdrop_url;
    }

    /**
     * Shared resolver for poster/backdrop/slider URLs.
     *
     * The single point of truth that decides whether a stored path becomes
     * an absolute URL, a signed private-disk URL, or a legacy public-disk
     * URL. Centralising it here means any future storage backend (e.g. a
     * `cdn://` scheme prefix) is one edit, not three.
     *
     * @param  string|null  $path  Raw stored path (`poster_path` etc.).
     * @param  string  $signedRoute  Named route used for `private/` paths.
     */
    protected function resolveAssetUrl(?string $path, string $signedRoute): ?string
    {
        if (! $path) {
            return null;
        }

        // Absolute URL stored verbatim — TMDB images, Bunny CDN URLs, S3
        // public-bucket URLs, etc. NEVER signed (they're already public).
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        // `private/...` prefix is the convention for files on the private
        // disk (storage/app/private/...). Serve via signed-URL route so the
        // file is reachable without making the disk web-accessible.
        if (str_starts_with($path, 'private/')) {
            return URL::temporarySignedRoute(
                $signedRoute,
                now()->addHours(2),
                ['movie' => $this->id]
            );
        }

        // Legacy: anything else is on the public disk (`storage/app/public`
        // → symlinked into `public/storage`). Backwards-compatible with
        // every row that pre-dates the private-disk migration.
        return asset('storage/'.$path);
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

    /**
     * Project this movie's overview/synopsis into the given UI locale.
     *
     * Catalog content is authored in Indonesian (the source locale); when a
     * viewer's UI locale is anything else we hand the synopsis to
     * App\Services\Ai\Tasks\TextTranslator which caches the result in
     * `translation_cache`. Subsequent calls cost zero AI tokens.
     *
     * Resolution order:
     *   1. Empty overview → empty string (no AI cost, no cache row).
     *   2. Target locale == source locale → return verbatim.
     *   3. Else → translate (cached). Failures fall back to the original.
     *
     * Lazy on purpose — never resolve unless a view actually asks for it.
     *
     * Usage: `{{ $movie->synopsisForLocale(app()->getLocale()) }}`
     */
    public function synopsisForLocale(string $locale, string $sourceLocale = 'id'): string
    {
        $source = (string) ($this->overview ?? '');
        if ($source === '') {
            return '';
        }
        if ($locale === $sourceLocale) {
            return $source;
        }

        try {
            /** @var \App\Services\Ai\Tasks\TextTranslator $translator */
            $translator = app(\App\Services\Ai\Tasks\TextTranslator::class);
            return $translator->translate($source, $locale, $sourceLocale);
        } catch (\Throwable) {
            // Container resolution / missing provider — degrade silently.
            return $source;
        }
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

    // ── Series support (TV-show layer) ─────────────────────────────────
    //
    // A "Movie" row with content_type='series' acts as the parent for
    // Season → Episode children. The seasons() and episodes() relations
    // ALWAYS work (return empty collections for standalone movies) so
    // call sites don't need to branch on content_type before eager-loading.

    /**
     * Seasons belonging to this (series) movie, ordered by season_number.
     */
    public function seasons(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Season::class)->orderBy('season_number');
    }

    /**
     * All episodes across all seasons. Sorted by season_number then
     * episode_number so a single eager-load yields "watch order".
     */
    public function episodes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Episode::class)
            ->orderBy('episode_number');
    }

    /**
     * True if this row represents a TV series rather than a standalone film.
     * Defaults conservatively to false for legacy rows missing the column.
     */
    public function isSeries(): bool
    {
        return ($this->content_type ?? 'movie') === 'series';
    }

    /**
     * Convenience accessor for Blade: `$movie->is_series`.
     */
    public function getIsSeriesAttribute(): bool
    {
        return $this->isSeries();
    }

    /**
     * First episode the user has NOT completed (progress < 90%) — used by
     * the front-end "Continue Watching" CTA on a series detail page.
     *
     * Walks episodes in canonical order (Season 1 Ep 1 → Season N Ep M).
     * Returns the first unwatched episode, OR the first episode if the
     * user has zero history (fresh viewer).
     */
    public function firstUnwatchedEpisode(User $user): ?Episode
    {
        $episodes = $this->relationLoaded('episodes')
            ? $this->episodes
            : $this->episodes()->with('season')->get();

        if ($episodes->isEmpty()) {
            return null;
        }

        // Re-sort with season number so cross-season order is correct
        // (the relation only orders by episode_number, which would
        // interleave seasons if we trusted it alone).
        $ordered = $episodes->sortBy(fn ($ep) => [
            $ep->season?->season_number ?? 0,
            $ep->episode_number,
        ])->values();

        $completed = WatchHistory::query()
            ->where('user_id', $user->id)
            ->whereIn('episode_id', $ordered->pluck('id'))
            ->where('completed', true)
            ->pluck('episode_id')
            ->all();

        foreach ($ordered as $ep) {
            if (! in_array($ep->id, $completed, true)) {
                return $ep;
            }
        }

        return null; // fully binged
    }

    /**
     * Episode that plays after `$current` finishes — rolls over into the
     * next season if `$current` is the season finale. Returns null when
     * the series has no more episodes to show.
     */
    public function nextEpisodeAfter(Episode $current): ?Episode
    {
        $next = $current->nextInSeason();
        if ($next) {
            return $next;
        }

        $currentSeasonNumber = $current->season?->season_number;
        if ($currentSeasonNumber === null) {
            return null;
        }

        $nextSeason = $this->seasons()
            ->where('season_number', '>', $currentSeasonNumber)
            ->with('episodes')
            ->orderBy('season_number')
            ->first();

        return $nextSeason?->episodes->first();
    }
}
