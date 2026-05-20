<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * BlogCategory — editorial taxonomy (News / Reviews / Lists / ...).
 *
 * Slugs are unique and used as the public route key for /blog/category/{slug}.
 * `color` is a hex string (e.g. "#C5A55A") used to tint badges in the UI.
 */
class BlogCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug',
        'name',
        'color',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Auto-fill the slug from `name` when admins forget to set one.
     */
    protected static function booted(): void
    {
        static::saving(function (BlogCategory $cat): void {
            if (empty($cat->slug) && ! empty($cat->name)) {
                $base = Str::slug($cat->name);
                $slug = $base ?: 'category';
                $i = 1;
                while (static::query()
                    ->where('slug', $slug)
                    ->where('id', '!=', $cat->id ?? 0)
                    ->exists()
                ) {
                    $slug = $base . '-' . (++$i);
                }
                $cat->slug = $slug;
            }
        });
    }

    public function posts(): HasMany
    {
        return $this->hasMany(BlogPost::class, 'category_id');
    }

    /**
     * Ordered ASC by sort_order then name — sidebar/dropdown convention.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
