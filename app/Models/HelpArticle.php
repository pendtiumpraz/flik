<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * HelpArticle — a single knowledge-base entry.
 *
 * - `body` holds the markdown source (always authoritative).
 * - `body_html` is the rendered cache, refreshed by setBodyAttribute on
 *   every save so public views can echo raw HTML without re-rendering.
 * - The view counter, helpful/not-helpful counters are guarded so a forged
 *   admin form can't reset them via mass assignment.
 *
 * Search: see static search() — MATCH AGAINST on MySQL, LIKE elsewhere.
 */
class HelpArticle extends Model
{
    use SoftDeletes;

    /**
     * @var array<int, string>
     */
    protected $guarded = [
        'id',
        'views_count',
        'helpful_count',
        'not_helpful_count',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'tags'              => 'array',
        'last_reviewed_at'  => 'datetime',
        'views_count'       => 'integer',
        'helpful_count'     => 'integer',
        'not_helpful_count' => 'integer',
        'sort_order'        => 'integer',
    ];

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_PUBLISHED = 'published';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PUBLISHED,
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    // ── Boot ──────────────────────────────────────────────────────────

    protected static function booted(): void
    {
        // Slug autogen — mirrors BlogPost::booted so admin UX is identical.
        static::saving(function (HelpArticle $article): void {
            if (empty($article->slug) && ! empty($article->title)) {
                $base = Str::slug($article->title);
                $slug = $base !== '' ? $base : 'artikel';
                $i = 1;
                while (static::withTrashed()
                    ->where('slug', $slug)
                    ->where('id', '!=', $article->id ?? 0)
                    ->exists()
                ) {
                    $slug = $base . '-' . (++$i);
                }
                $article->slug = $slug;
            }
        });

        // Keep the parent category's articles_count fresh on every write.
        $recount = function (HelpArticle $article): void {
            $catIds = array_unique(array_filter([
                $article->category_id,
                $article->getOriginal('category_id'),
            ]));
            foreach ($catIds as $cid) {
                $cat = HelpCategory::query()->find($cid);
                $cat?->recountArticles();
            }
        };
        static::saved($recount);
        static::deleted($recount);
        static::restored($recount);
    }

    // ── Relations ─────────────────────────────────────────────────────

    public function category(): BelongsTo
    {
        return $this->belongsTo(HelpCategory::class, 'category_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(HelpArticleFeedback::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public function scopeForCategory(Builder $query, int $id): Builder
    {
        return $query->where('category_id', $id);
    }

    // ── Mutators / accessors ──────────────────────────────────────────

    /**
     * Renders the markdown source into the `body_html` cache on every save.
     * Errors fall back to the raw markdown so a malformed input never 500s
     * the public detail page.
     */
    public function setBodyAttribute(?string $value): void
    {
        $value = (string) ($value ?? '');
        $this->attributes['body'] = $value;
        $this->attributes['body_html'] = self::renderMarkdown($value);
    }

    /**
     * Total feedback votes (both directions).
     */
    public function getTotalFeedbackAttribute(): int
    {
        return (int) $this->helpful_count + (int) $this->not_helpful_count;
    }

    /**
     * Percentage of votes marked helpful. Returns null when there are no
     * votes so views can decide whether to render the indicator at all.
     */
    public function getHelpfulPercentageAttribute(): ?float
    {
        $total = $this->total_feedback;
        if ($total <= 0) {
            return null;
        }

        return round(((int) $this->helpful_count / $total) * 100, 1);
    }

    public function getUrlAttribute(): string
    {
        return route('help.show', $this->slug);
    }

    // ── Search ────────────────────────────────────────────────────────

    /**
     * Relevance-aware search across title + body.
     *
     * On MySQL/MariaDB the FULLTEXT index created in the migration is
     * used — relevance is the natural-language score returned by the
     * server. On every other driver (SQLite for tests, Postgres) we fall
     * back to a LIKE-based query. Either way the caller gets a Builder
     * already restricted to published articles.
     */
    public static function search(string $q): Builder
    {
        $q = trim($q);
        $query = static::query()->published();

        if ($q === '') {
            return $query;
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            // MATCH AGAINST: bind the term, no LIKE percent-escaping needed.
            // IN BOOLEAN MODE so we can lean on +/- operators if a future
            // version of the UI surfaces them; natural mode would be fine too.
            return $query
                ->whereRaw(
                    'MATCH(title, body) AGAINST (? IN NATURAL LANGUAGE MODE)',
                    [$q]
                )
                ->orderByRaw(
                    'MATCH(title, body) AGAINST (?) DESC',
                    [$q]
                );
        }

        // LIKE fallback — escape % and _ so user-typed wildcards don't
        // turn into runaway scans.
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        return $query
            ->where(function (Builder $w) use ($like) {
                $w->where('title', 'like', $like)
                  ->orWhere('body', 'like', $like);
            })
            ->orderByRaw('CASE WHEN title LIKE ? THEN 0 ELSE 1 END', [$like])
            ->orderByDesc('helpful_count');
    }

    // ── Counter helpers (mutate without dirtying updated_at) ─────────

    public function recordView(): void
    {
        static::query()->whereKey($this->id)->increment('views_count');
    }

    public function recordHelpful(): void
    {
        static::query()->whereKey($this->id)->increment('helpful_count');
    }

    public function recordNotHelpful(): void
    {
        static::query()->whereKey($this->id)->increment('not_helpful_count');
    }

    // ── Markdown rendering ────────────────────────────────────────────

    /**
     * Pure markdown→html, same sanitisation rules as BlogPost. Kept static
     * so AI-suggested previews and the mutator share one implementation.
     */
    public static function renderMarkdown(string $md): string
    {
        if ($md === '') {
            return '';
        }

        try {
            return Str::markdown($md, [
                'html_input'         => 'escape',
                'allow_unsafe_links' => false,
            ]);
        } catch (\Throwable $e) {
            Log::warning('HelpArticle::renderMarkdown failed', ['error' => $e->getMessage()]);

            return '<pre>' . e($md) . '</pre>';
        }
    }
}
