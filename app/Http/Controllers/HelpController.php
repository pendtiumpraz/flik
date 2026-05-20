<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\HelpArticle;
use App\Models\HelpArticleFeedback;
use App\Models\HelpCategory;
use App\Services\Help\HelpSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public Help Center reading surface.
 *
 *   - index         → landing page with categories + popular articles
 *   - category      → article list within a category
 *   - show          → single article with related rail + feedback widget
 *   - search        → results page (HTML)
 *   - searchSuggest → JSON autocomplete (debounced typeahead)
 *   - feedback      → POST helpful / not-helpful, throttled per IP
 *
 * View increments are debounced via the cache (1 row / session / hour) so
 * a refresh spree can't inflate the counter — same pattern as BlogController.
 */
class HelpController extends Controller
{
    private const PER_PAGE              = 24;
    private const POPULAR_LIMIT         = 8;
    private const VIEW_LOCK_TTL_SECONDS = 3600;
    private const PER_CATEGORY_PREVIEW  = 4;

    public function __construct(
        protected HelpSearch $search,
    ) {}

    public function index(): View
    {
        $categories = HelpCategory::query()
            ->ordered()
            ->where('articles_count', '>', 0)
            ->get();

        // Eager-load up to N preview articles per visible category so the
        // landing page doesn't fan out into N+1 queries.
        $categoryIds = $categories->pluck('id')->all();
        $previewArticles = collect();

        if (! empty($categoryIds)) {
            $previewArticles = HelpArticle::query()
                ->published()
                ->whereIn('category_id', $categoryIds)
                ->orderBy('sort_order')
                ->orderByDesc('helpful_count')
                ->orderByDesc('updated_at')
                ->select('id', 'slug', 'title', 'excerpt', 'category_id', 'helpful_count')
                ->get()
                ->groupBy('category_id')
                ->map(fn ($group) => $group->take(self::PER_CATEGORY_PREVIEW));
        }

        $popular = HelpArticle::query()
            ->published()
            ->orderByDesc('views_count')
            ->orderByDesc('helpful_count')
            ->limit(self::POPULAR_LIMIT)
            ->with('category:id,slug,name,icon')
            ->get();

        return view('help.index', [
            'categories'      => $categories,
            'previewArticles' => $previewArticles,
            'popular'         => $popular,
        ]);
    }

    public function category(HelpCategory $category): View
    {
        $articles = HelpArticle::query()
            ->published()
            ->where('category_id', $category->id)
            ->orderBy('sort_order')
            ->orderByDesc('updated_at')
            ->paginate(self::PER_PAGE);

        return view('help.category', [
            'category' => $category,
            'articles' => $articles,
        ]);
    }

    public function show(Request $request, HelpArticle $article): View
    {
        // Drafts: only visible to users who can manage help articles
        // (preview mode). Everyone else gets a clean 404 so unpublished
        // slugs don't leak.
        if ($article->status !== HelpArticle::STATUS_PUBLISHED) {
            $canPreview = $request->user() && Gate::allows('help.manage');
            abort_unless($canPreview, Response::HTTP_NOT_FOUND);
        }

        $article->load(['category:id,slug,name,icon']);

        // Debounced view counter — same shape as BlogController.
        $fingerprint = sprintf('help:view:%d:%s', $article->id, $request->session()->getId());
        if (! Cache::has($fingerprint)) {
            $article->recordView();
            Cache::put($fingerprint, 1, self::VIEW_LOCK_TTL_SECONDS);
        }

        $related = $this->search->relatedTo($article, 4);

        // FAQPage schema if the article looks like Q/A; otherwise Article.
        $jsonLd = $this->buildJsonLd($article);

        return view('help.show', [
            'article' => $article,
            'related' => $related,
            'jsonLd'  => $jsonLd,
        ]);
    }

    public function search(Request $request): View
    {
        $q = trim((string) $request->input('q', ''));
        $categoryId = (int) $request->input('category_id', 0);

        $results = collect();
        if ($q !== '') {
            $results = $this->search->search($q, $categoryId > 0 ? $categoryId : null, 50);
        }

        return view('help.search', [
            'q'          => $q,
            'results'    => $results,
            'categories' => HelpCategory::ordered()->get(),
            'currentCategoryId' => $categoryId > 0 ? $categoryId : null,
        ]);
    }

    /**
     * JSON autocomplete endpoint — answers <40ms because it reads only
     * indexed columns from `help_articles`.
     */
    public function searchSuggest(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));
        $suggestions = $this->search->suggest($q, 5);

        return response()->json([
            'q'           => $q,
            'suggestions' => $suggestions,
        ]);
    }

    /**
     * Record helpful / not-helpful feedback. Auth users vote once per
     * article (unique index enforces it); anonymous votes are deduped
     * via SHA-256(IP).
     *
     * Throttled at the route layer (per-IP).
     */
    public function feedback(Request $request, HelpArticle $article): RedirectResponse|JsonResponse
    {
        if ($article->status !== HelpArticle::STATUS_PUBLISHED) {
            return $this->feedbackResponse($request, false, 'Artikel tidak tersedia.', null);
        }

        $data = $request->validate([
            'is_helpful' => ['required', 'boolean'],
            'comment'    => ['nullable', 'string', 'max:1000'],
        ]);

        $userId = optional($request->user())->id;
        $ipHash = $userId === null
            ? hash('sha256', (string) $request->ip() . config('app.key'))
            : null;

        // Idempotency:
        //   - auth: unique on (article, user) catches duplicates at the DB.
        //   - anon: explicit lookup against (article, ip_hash).
        $existing = HelpArticleFeedback::query()
            ->where('help_article_id', $article->id)
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->when($userId === null, fn ($q) => $q->whereNull('user_id')->where('ip_hash', $ipHash))
            ->first();

        if ($existing) {
            return $this->feedbackResponse(
                $request,
                false,
                'Terima kasih, suara Anda sudah tercatat sebelumnya.',
                (bool) $existing->is_helpful,
            );
        }

        DB::transaction(function () use ($article, $data, $userId, $ipHash): void {
            HelpArticleFeedback::create([
                'help_article_id' => $article->id,
                'user_id'         => $userId,
                'is_helpful'      => (bool) $data['is_helpful'],
                'comment'         => $data['comment'] ?? null,
                'ip_hash'         => $ipHash,
                'created_at'      => now(),
            ]);

            if ($data['is_helpful']) {
                $article->recordHelpful();
            } else {
                $article->recordNotHelpful();
            }
        });

        return $this->feedbackResponse(
            $request,
            true,
            'Terima kasih atas masukan Anda!',
            (bool) $data['is_helpful'],
        );
    }

    // ── Internals ─────────────────────────────────────────────────────

    /**
     * Pick the appropriate JSON-LD payload for the article.
     *
     * Q/A pattern detection is intentionally lightweight: if the body
     * contains 2+ `## ...?` headers, we emit FAQPage; otherwise Article.
     * False-positives are harmless (FAQ schema with non-Q&A content
     * downgrades, doesn't penalise), so the heuristic stays simple.
     *
     * @return array<string, mixed>
     */
    private function buildJsonLd(HelpArticle $article): array
    {
        $body = (string) $article->body;
        $qaPairs = $this->extractQaPairs($body);

        if (count($qaPairs) >= 2) {
            return [
                '@context'   => 'https://schema.org',
                '@type'      => 'FAQPage',
                'mainEntity' => array_map(fn ($pair) => [
                    '@type'          => 'Question',
                    'name'           => $pair['q'],
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text'  => $pair['a'],
                    ],
                ], $qaPairs),
            ];
        }

        return [
            '@context'      => 'https://schema.org',
            '@type'         => 'Article',
            'headline'      => $article->title,
            'description'   => (string) ($article->excerpt ?? ''),
            'datePublished' => optional($article->created_at)->toIso8601String(),
            'dateModified'  => optional($article->updated_at)->toIso8601String(),
            'author'        => [
                '@type' => 'Organization',
                'name'  => config('app.name', 'FLiK'),
            ],
            'publisher'     => [
                '@type' => 'Organization',
                'name'  => config('app.name', 'FLiK'),
            ],
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id'   => route('help.show', $article->slug),
            ],
        ];
    }

    /**
     * Extract `## Some question?` followed by the paragraph(s) below it
     * until the next H2. Trims to 1500 chars per answer (rich snippet
     * cap on Google's side).
     *
     * @return array<int, array{q:string, a:string}>
     */
    private function extractQaPairs(string $body): array
    {
        if (! preg_match_all('/^##\s+(.+?\?)\s*$([\s\S]*?)(?=^##\s|\z)/m', $body, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $pairs = [];
        foreach ($matches as $m) {
            $q = trim($m[1]);
            $a = trim(strip_tags(HelpArticle::renderMarkdown(trim($m[2]))));
            if ($q !== '' && $a !== '') {
                $pairs[] = [
                    'q' => $q,
                    'a' => mb_substr($a, 0, 1500),
                ];
            }
        }

        return $pairs;
    }

    /**
     * Respond appropriately to XHR (JSON) vs full-page form post (redirect).
     */
    private function feedbackResponse(Request $request, bool $ok, string $message, ?bool $isHelpful): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'ok'         => $ok,
                'message'    => $message,
                'is_helpful' => $isHelpful,
            ]);
        }

        return back()->with($ok ? 'success' : 'info', $message);
    }
}
