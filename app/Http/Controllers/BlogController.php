<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Services\Blog\BlogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * BlogController — public reading surface for the editorial blog.
 *
 *   - index     → paginated published posts, filterable by category
 *   - show      → single post (preview allowed for blog.manage holders)
 *   - byCategory→ same listing scoped to one category
 *   - rss       → application/rss+xml feed of last 20 published posts
 *
 * View increments are throttled per session+post via the cache (1 row /
 * hour) so a page reload spree can't inflate the counter.
 */
class BlogController extends Controller
{
    private const PER_PAGE = 12;
    private const RSS_LIMIT = 20;
    private const VIEW_LOCK_TTL_SECONDS = 3600;

    public function __construct(
        protected BlogService $blog,
    ) {}

    public function index(Request $request)
    {
        $search = trim((string) $request->input('q', ''));
        $categorySlug = (string) $request->input('category', '');

        $query = BlogPost::query()
            ->published()
            ->with(['author:id,name', 'category:id,name,color,slug'])
            ->latest('published_at');

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('excerpt', 'like', "%{$search}%");
            });
        }

        if ($categorySlug !== '') {
            $catId = BlogCategory::query()->where('slug', $categorySlug)->value('id');
            if ($catId) {
                $query->where('category_id', $catId);
            }
        }

        $posts = $query->paginate(self::PER_PAGE)->withQueryString();

        // Featured spotlight for the index hero — only when not filtering.
        $featured = null;
        if ($search === '' && $categorySlug === '' && $request->input('page', 1) == 1) {
            $featured = BlogPost::query()
                ->published()
                ->featured()
                ->with(['author:id,name', 'category:id,name,color,slug'])
                ->latest('published_at')
                ->first();
        }

        return view('blog.index', [
            'posts'      => $posts,
            'featured'   => $featured,
            'categories' => BlogCategory::query()->ordered()->get(),
            'q'          => $search,
            'activeCategory' => $categorySlug,
        ]);
    }

    public function show(Request $request, BlogPost $post)
    {
        // Preview allowed for users who can manage the blog (so editors
        // can review a draft before scheduling). Everyone else gets 404
        // for a non-published post — never reveal that the slug exists.
        if (! $post->isPublished()) {
            $canPreview = $request->user() && Gate::allows('blog.manage');
            abort_unless($canPreview, Response::HTTP_NOT_FOUND);
        }

        $post->load([
            'author:id,name',
            'category:id,name,color,slug',
            'movies' => function ($q): void {
                $q->select('movies.id', 'movies.title', 'movies.slug', 'movies.poster_path', 'movies.overview')
                  ->limit(6);
            },
        ]);

        // Session+post fingerprint — debounces views to 1 / hour.
        $fingerprint = sprintf('blog:view:%d:%s', $post->id, $request->session()->getId());
        if (! Cache::has($fingerprint)) {
            BlogPost::query()->whereKey($post->id)->increment('views_count');
            Cache::put($fingerprint, 1, self::VIEW_LOCK_TTL_SECONDS);
        }

        $related = $this->blog->relatedPosts($post, 4);

        return view('blog.show', [
            'post'    => $post,
            'related' => $related,
        ]);
    }

    public function byCategory(Request $request, BlogCategory $category)
    {
        $posts = BlogPost::query()
            ->published()
            ->where('category_id', $category->id)
            ->with(['author:id,name', 'category:id,name,color,slug'])
            ->latest('published_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('blog.category', [
            'category'   => $category,
            'posts'      => $posts,
            'categories' => BlogCategory::query()->ordered()->get(),
        ]);
    }

    /**
     * RSS 2.0 feed of the latest published posts. Content-Type matters —
     * feed readers refuse to subscribe if it isn't application/rss+xml.
     */
    public function rss(): Response
    {
        $posts = BlogPost::query()
            ->published()
            ->with('author:id,name')
            ->latest('published_at')
            ->limit(self::RSS_LIMIT)
            ->get();

        $xml = view('blog.rss', [
            'posts'   => $posts,
            'site'    => config('app.name', 'FLiK'),
            'siteUrl' => url('/'),
            'feedUrl' => route('blog.rss'),
            'now'     => now(),
        ])->render();

        return response($xml, 200, [
            'Content-Type' => 'application/rss+xml; charset=UTF-8',
        ]);
    }
}
