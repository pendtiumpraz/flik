<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cast member (actor OR director — disambiguated heuristically from cast_movie pivot).
 *
 * Schema columns (after the 2026_05_10_020014_extend_casts_with_bio migration):
 *   - id, name, profile_path
 *   - bio (text, nullable)                — AI-enriched biography (~200 words)
 *   - wikipedia_url (string, nullable)    — source-of-truth link
 *   - birth_date (date, nullable)
 *   - nationality (string, nullable)
 *   - bio_generated_at (timestamp, null)  — idempotency stamp for CastBiographyEnricher
 *   - tmdb_id (int, nullable)
 *
 * Director vs Actor: the `cast_movie` pivot only has (character, order).
 * Convention used across the app:
 *   - role = 'director' when ANY pivot row has character LIKE '%director%' /
 *     '%sutradara%' OR character IS NULL.
 *   - role = 'actor' otherwise (has a real character name).
 *
 * The heuristic is best-effort — if seed data ever evolves to use a dedicated
 * `role` column on the pivot we should switch to that.
 */
class Cast extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'profile_path',
        'tmdb_id',
        'bio',
        'wikipedia_url',
        'birth_date',
        'nationality',
    ];

    protected $casts = [
        'tmdb_id'          => 'integer',
        'birth_date'       => 'date',
        'bio_generated_at' => 'datetime',
    ];

    /**
     * Keep ID-based route binding. Slug is appended as a SEO suffix only.
     */
    public function getRouteKeyName(): string
    {
        return 'id';
    }

    // ━━━ Relations ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * All movies tied to this cast member (acted in OR directed).
     */
    public function movies(): BelongsToMany
    {
        return $this->belongsToMany(Movie::class, 'cast_movie')
            ->withPivot('character', 'order');
    }

    /**
     * Movies this person directed (heuristic — see class docblock).
     */
    public function directedMovies(): BelongsToMany
    {
        return $this->belongsToMany(Movie::class, 'cast_movie')
            ->withPivot('character', 'order')
            ->where(function (Builder $q) {
                $q->whereNull('cast_movie.character')
                  ->orWhere('cast_movie.character', 'LIKE', '%irector%')
                  ->orWhere('cast_movie.character', 'LIKE', '%utradara%');
            });
    }

    /**
     * Movies this person acted in — pivot rows that have a real character name
     * which is NOT one of the director sentinels.
     */
    public function actedInMovies(): BelongsToMany
    {
        return $this->belongsToMany(Movie::class, 'cast_movie')
            ->withPivot('character', 'order')
            ->whereNotNull('cast_movie.character')
            ->where('cast_movie.character', 'NOT LIKE', '%irector%')
            ->where('cast_movie.character', 'NOT LIKE', '%utradara%');
    }

    // ━━━ Scopes ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Restrict to cast members that are linked to at least one movie.
     * Used by the public listing so we don't show ghost entries.
     */
    public function scopeWithMovies(Builder $query): Builder
    {
        return $query->whereExists(function ($sub) {
            $sub->select(DB::raw(1))
                ->from('cast_movie')
                ->whereColumn('cast_movie.cast_id', 'casts.id');
        });
    }

    /**
     * Filter by inferred role ('actor' | 'director' | anything else = no-op).
     */
    public function scopeOfRole(Builder $query, string $role): Builder
    {
        $role = strtolower(trim($role));

        if ($role === 'director') {
            return $query->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('cast_movie')
                    ->whereColumn('cast_movie.cast_id', 'casts.id')
                    ->where(function ($w) {
                        $w->whereNull('cast_movie.character')
                          ->orWhere('cast_movie.character', 'LIKE', '%irector%')
                          ->orWhere('cast_movie.character', 'LIKE', '%utradara%');
                    });
            });
        }

        if ($role === 'actor') {
            return $query->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('cast_movie')
                    ->whereColumn('cast_movie.cast_id', 'casts.id')
                    ->whereNotNull('cast_movie.character')
                    ->where('cast_movie.character', 'NOT LIKE', '%irector%')
                    ->where('cast_movie.character', 'NOT LIKE', '%utradara%');
            });
        }

        return $query;
    }

    // ━━━ Accessors ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Inferred role label — 'director' if ANY pivot row matches the director
     * heuristic, else 'actor'. Reads pivot data from a loaded movies relation
     * when available; falls back to a single EXISTS lookup otherwise.
     */
    public function getRoleAttribute(): string
    {
        if ($this->relationLoaded('movies')) {
            foreach ($this->movies as $m) {
                if ($this->isDirectorPivot($m->pivot->character ?? null)) {
                    return 'director';
                }
            }
            return 'actor';
        }

        $directs = DB::table('cast_movie')
            ->where('cast_id', $this->id)
            ->where(function ($q) {
                $q->whereNull('character')
                  ->orWhere('character', 'LIKE', '%irector%')
                  ->orWhere('character', 'LIKE', '%utradara%');
            })
            ->exists();

        return $directs ? 'director' : 'actor';
    }

    /**
     * Resolved profile image URL. Supports absolute URLs (TMDB / CDN), the
     * TMDB person-path convention (/abc.jpg), and legacy public-disk paths.
     * Falls back to the site placeholder when missing.
     */
    public function getProfileImageAttribute(): string
    {
        $path = (string) ($this->profile_path ?? '');

        if ($path === '') {
            return '/images/no-poster.png';
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        if (str_starts_with($path, '/') && preg_match('~^/[A-Za-z0-9_.-]+\.(jpg|jpeg|png|webp)$~i', $path)) {
            return 'https://image.tmdb.org/t/p/w500' . $path;
        }

        return asset('storage/' . ltrim($path, '/'));
    }

    /**
     * SEO-friendly slug derived from the name. Used as the suffix segment in
     * /cast/{id}/{slug} URLs. PublicCastController::show() compares against
     * this value and 301-redirects mismatches to the canonical URL.
     */
    public function getSlugAttribute(): string
    {
        return Str::slug((string) ($this->name ?? '')) ?: (string) $this->id;
    }

    /**
     * Convenience age accessor — null when birth_date is missing.
     */
    public function getAgeAttribute(): ?int
    {
        if (! $this->birth_date) {
            return null;
        }
        try {
            return $this->birth_date->age;
        } catch (\Throwable) {
            return null;
        }
    }

    // ━━━ Internal helpers ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Returns true when the given pivot character string indicates a
     * director credit (or is null, which our seed convention treats as one).
     */
    protected function isDirectorPivot(?string $character): bool
    {
        if ($character === null) {
            return true;
        }
        $lower = strtolower($character);
        return str_contains($lower, 'director') || str_contains($lower, 'sutradara');
    }
}
