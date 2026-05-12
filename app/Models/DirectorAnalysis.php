<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Persisted AI-generated auteur analysis for a single director.
 *
 * The `data` JSON follows the contract enforced by
 * {@see \App\Services\Ai\Tasks\DirectorAuteurAnalyzer}:
 *   - signature_style       (string)
 *   - recurring_themes      (string[])
 *   - frequent_collaborators(string[])
 *   - influence             (string)
 *   - essential_films       (array<{title,year,why_essential}>)
 *   - trivia                (string[])
 */
class DirectorAnalysis extends Model
{
    use HasFactory;

    protected $table = 'director_analyses';

    protected $fillable = [
        'director_name',
        'slug',
        'data',
        'source_urls',
        'generated_at',
    ];

    protected $casts = [
        'data'         => 'array',
        'source_urls'  => 'array',
        'generated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (DirectorAnalysis $row) {
            if (empty($row->slug) && !empty($row->director_name)) {
                $row->slug = static::uniqueSlug($row->director_name, $row->id);
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Build a unique slug for the given director name, excluding the row $ignoreId.
     */
    public static function uniqueSlug(string $directorName, ?int $ignoreId = null): string
    {
        $base = Str::slug($directorName) ?: 'director';
        $slug = $base;
        $i = 1;
        while (static::where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists()
        ) {
            $slug = $base . '-' . (++$i);
        }

        return $slug;
    }
}
