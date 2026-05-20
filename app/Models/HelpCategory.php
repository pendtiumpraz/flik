<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Help Center: category bucket for help articles.
 *
 * The `articles_count` column is denormalised — kept in sync by the
 * HelpArticle model's saved/deleted hooks so the landing page can render
 * category cards without an N+1 COUNT(*) per card.
 */
class HelpCategory extends Model
{
    /**
     * @var array<int, string>
     */
    protected $guarded = ['id'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'sort_order'     => 'integer',
        'articles_count' => 'integer',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    // ── Boot: slug autogen ────────────────────────────────────────────

    protected static function booted(): void
    {
        static::saving(function (HelpCategory $cat): void {
            if (empty($cat->slug) && ! empty($cat->name)) {
                $base = Str::slug($cat->name);
                $slug = $base !== '' ? $base : 'kategori';
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

    // ── Relations ─────────────────────────────────────────────────────

    /**
     * Published articles ordered by sort_order, fresh first.
     */
    public function articles(): HasMany
    {
        return $this->hasMany(HelpArticle::class, 'category_id')
            ->orderBy('sort_order')
            ->orderByDesc('updated_at');
    }

    /**
     * All articles (any status) — used by the admin index.
     */
    public function allArticles(): HasMany
    {
        return $this->hasMany(HelpArticle::class, 'category_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /**
     * Recount the cached `articles_count` for this category. Cheap
     * single-query update — called from HelpArticle::saved/deleted.
     */
    public function recountArticles(): void
    {
        $count = HelpArticle::query()
            ->where('category_id', $this->id)
            ->whereNull('deleted_at')
            ->where('status', HelpArticle::STATUS_PUBLISHED)
            ->count();

        // Use raw query to avoid touching `updated_at` (counter, not edit).
        static::query()->whereKey($this->id)->update(['articles_count' => $count]);
    }
}
