<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HelpArticle;
use App\Models\HelpCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin CRUD + publish action for Help Center articles.
 *
 * Permission gate: every action wraps `can:help.manage` at the route
 * layer (see routes/web.php). Controller validates tag list shape and
 * status enum so a forged payload can't slip a non-canonical status in.
 */
class HelpArticleController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): View
    {
        $status     = $request->input('status');
        $search     = trim((string) $request->input('q', ''));
        $categoryId = (int) $request->input('category_id', 0);

        $query = HelpArticle::query()
            ->with(['author:id,name', 'category:id,name,slug,icon'])
            ->orderBy('sort_order')
            ->orderByDesc('id');

        if ($status && in_array($status, HelpArticle::STATUSES, true)) {
            $query->where('status', $status);
        }

        if ($categoryId > 0) {
            $query->where('category_id', $categoryId);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        $articles = $query->paginate(self::PER_PAGE)->withQueryString();

        $statusCounts = HelpArticle::query()
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        return view('admin.help.articles.index', [
            'articles'      => $articles,
            'statusCounts'  => $statusCounts,
            'currentStatus' => $status,
            'q'             => $search,
            'categories'    => HelpCategory::ordered()->get(),
            'currentCategoryId' => $categoryId > 0 ? $categoryId : null,
        ]);
    }

    public function create(): View
    {
        return view('admin.help.articles.create', [
            'article'    => new HelpArticle(),
            'categories' => HelpCategory::ordered()->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatePayload($request);
        $action = (string) $request->input('action', 'draft');

        $article = new HelpArticle();
        $article->fill($this->extractFillable($data));
        $article->author_user_id = (int) $request->user()->id;
        $this->applyAction($article, $action);
        $article->save();

        return redirect()
            ->route('admin.help.articles.edit', $article)
            ->with('success', 'Artikel bantuan tersimpan.');
    }

    public function edit(HelpArticle $article): View
    {
        return view('admin.help.articles.edit', [
            'article'    => $article,
            'categories' => HelpCategory::ordered()->get(),
        ]);
    }

    public function update(Request $request, HelpArticle $article): RedirectResponse
    {
        $data = $this->validatePayload($request, $article);
        $action = (string) $request->input('action', 'save');

        $article->fill($this->extractFillable($data));
        $this->applyAction($article, $action);
        $article->save();

        return redirect()
            ->route('admin.help.articles.edit', $article)
            ->with('success', 'Perubahan tersimpan.');
    }

    public function destroy(HelpArticle $article): RedirectResponse
    {
        $article->delete();

        return redirect()
            ->route('admin.help.articles.index')
            ->with('success', 'Artikel dipindah ke tong sampah.');
    }

    /**
     * One-click publish — used by the "Publish Now" button on the index/edit
     * screens.
     */
    public function publish(HelpArticle $article): RedirectResponse
    {
        $article->status = HelpArticle::STATUS_PUBLISHED;
        $article->save();

        return back()->with('success', 'Artikel diterbitkan.');
    }

    // ── Internals ─────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, ?HelpArticle $article = null): array
    {
        $slugRule = Rule::unique('help_articles', 'slug')->whereNull('deleted_at');
        if ($article) {
            $slugRule = $slugRule->ignore($article->id);
        }

        return $request->validate([
            'title'            => ['required', 'string', 'max:200'],
            'slug'             => ['nullable', 'string', 'max:160', $slugRule],
            'excerpt'          => ['nullable', 'string', 'max:2000'],
            'body'             => ['required', 'string'],
            'category_id'      => ['nullable', 'integer', 'exists:help_categories,id'],
            'status'           => ['nullable', 'string', Rule::in(HelpArticle::STATUSES)],
            'sort_order'       => ['nullable', 'integer', 'min:-32000', 'max:32000'],
            // Tags are submitted as a comma-separated string from the
            // chips Alpine component — we normalise to array below.
            'tags'             => ['nullable', 'string', 'max:2000'],
            'last_reviewed_at' => ['nullable', 'date'],
            'action'           => ['nullable', 'string', 'in:draft,publish,save'],
        ]);
    }

    /**
     * Pull only model-writable keys out of the validated payload (excludes
     * `action` and casts `tags` from comma string → array).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function extractFillable(array $data): array
    {
        $tags = null;
        if (isset($data['tags']) && is_string($data['tags']) && trim($data['tags']) !== '') {
            $tags = collect(explode(',', $data['tags']))
                ->map(fn ($t) => trim((string) $t))
                ->filter(fn ($t) => $t !== '' && mb_strlen($t) <= 40)
                ->unique()
                ->values()
                ->all();
        }

        return [
            'title'            => $data['title'],
            'slug'             => $data['slug'] ?? null,
            'excerpt'          => $data['excerpt'] ?? null,
            'body'             => $data['body'],
            'category_id'      => $data['category_id'] ?? null,
            'sort_order'       => $data['sort_order'] ?? 0,
            'tags'             => $tags,
            'last_reviewed_at' => $data['last_reviewed_at'] ?? null,
        ];
    }

    /**
     * Set status from the button the admin clicked.
     */
    private function applyAction(HelpArticle $article, string $action): void
    {
        switch ($action) {
            case 'publish':
                $article->status = HelpArticle::STATUS_PUBLISHED;
                break;

            case 'draft':
                $article->status = HelpArticle::STATUS_DRAFT;
                break;

            case 'save':
            default:
                if (! $article->exists) {
                    $article->status = HelpArticle::STATUS_DRAFT;
                }
                break;
        }
    }
}
