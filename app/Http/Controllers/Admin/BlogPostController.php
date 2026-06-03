<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\Movie;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * BlogPostController — admin CRUD + lifecycle for editorial posts.
 *
 * Permission gate: every action is wrapped by `can:blog.manage` at the
 * route layer (see routes/web.php). The controller also re-validates
 * status enums + scheduled_for ordering so a forged form payload can't
 * land a "published" row with a future scheduled date.
 */
class BlogPostController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): View
    {
        $status = $request->input('status');
        $search = trim((string) $request->input('q', ''));

        $query = BlogPost::query()
            ->with(['author:id,name', 'category:id,name,color,slug'])
            ->orderByDesc('id');

        if ($status && in_array($status, BlogPost::STATUSES, true)) {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->whereLike('title', "%{$search}%")
                  ->orWhereLike('slug', "%{$search}%");
            });
        }

        $posts = $query->paginate(self::PER_PAGE)->withQueryString();

        // Sidebar status counters (mirrors the filter chips).
        $statusCounts = BlogPost::query()
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        return view('admin.blog.posts.index', [
            'posts'        => $posts,
            'statusCounts' => $statusCounts,
            'currentStatus'=> $status,
            'q'            => $search,
        ]);
    }

    public function create(): View
    {
        return view('admin.blog.posts.create', [
            'post'       => new BlogPost(),
            'categories' => BlogCategory::query()->ordered()->get(),
            'movies'     => collect(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatePayload($request);
        $action = (string) $request->input('action', 'draft');

        $post = new BlogPost();
        $post->fill($data);
        $post->author_user_id = (int) $request->user()->id;
        $this->applyAction($post, $action, $data);
        $post->save();

        $this->syncMovies($post, $request);

        return redirect()
            ->route('admin.blog.posts.edit', $post)
            ->with('success', 'Artikel tersimpan.');
    }

    public function edit(BlogPost $post): View
    {
        $post->load('movies:id,title,slug,poster_path');

        return view('admin.blog.posts.edit', [
            'post'       => $post,
            'categories' => BlogCategory::query()->ordered()->get(),
            'movies'     => $post->movies,
        ]);
    }

    public function update(Request $request, BlogPost $post): RedirectResponse
    {
        $data = $this->validatePayload($request, $post);
        $action = (string) $request->input('action', 'save');

        $post->fill($data);
        $this->applyAction($post, $action, $data);
        $post->save();

        $this->syncMovies($post, $request);

        return redirect()
            ->route('admin.blog.posts.edit', $post)
            ->with('success', 'Perubahan tersimpan.');
    }

    public function destroy(BlogPost $post): RedirectResponse
    {
        $post->delete();

        return redirect()
            ->route('admin.blog.posts.index')
            ->with('success', 'Artikel dipindah ke tong sampah (soft delete).');
    }

    /**
     * Restore a soft-deleted post.
     */
    public function restore(int $id): RedirectResponse
    {
        $post = BlogPost::onlyTrashed()->findOrFail($id);
        $post->restore();

        return redirect()
            ->route('admin.blog.posts.edit', $post)
            ->with('success', 'Artikel berhasil di-restore.');
    }

    /**
     * Force-publish (button on the edit screen).
     */
    public function publish(BlogPost $post): RedirectResponse
    {
        $post->publish();

        return redirect()
            ->route('admin.blog.posts.edit', $post)
            ->with('success', 'Artikel diterbitkan.');
    }

    // ── Internals ─────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, ?BlogPost $post = null): array
    {
        $slugRule = Rule::unique('blog_posts', 'slug')
            ->whereNull('deleted_at');
        if ($post) {
            $slugRule = $slugRule->ignore($post->id);
        }

        return $request->validate([
            'title'           => ['required', 'string', 'max:200'],
            'slug'            => ['nullable', 'string', 'max:160', $slugRule],
            'excerpt'         => ['nullable', 'string', 'max:2000'],
            'body'            => ['required', 'string'],
            'cover_image'     => ['nullable', 'string', 'max:255'],
            'category_id'     => ['nullable', 'integer', 'exists:blog_categories,id'],
            'seo_title'       => ['nullable', 'string', 'max:200'],
            'seo_description' => ['nullable', 'string', 'max:1000'],
            'is_featured'     => ['sometimes', 'boolean'],
            'scheduled_for'   => ['nullable', 'date'],
            'action'          => ['nullable', 'string', 'in:draft,publish,schedule,save,archive'],
        ]);
    }

    /**
     * Decide status + published_at + scheduled_for from the button the
     * admin clicked. Keeps the controller's POST handler symmetrical
     * between create + update.
     *
     * @param array<string, mixed> $data
     */
    private function applyAction(BlogPost $post, string $action, array $data): void
    {
        $post->is_featured = (bool) ($data['is_featured'] ?? false);

        switch ($action) {
            case 'publish':
                $post->status = BlogPost::STATUS_PUBLISHED;
                $post->published_at = $post->published_at ?? now();
                $post->scheduled_for = null;
                break;

            case 'schedule':
                $scheduled = $data['scheduled_for'] ?? null;
                if ($scheduled) {
                    $when = Carbon::parse($scheduled);
                    // Future date → scheduled; past date → publish immediately
                    if ($when->isFuture()) {
                        $post->status = BlogPost::STATUS_SCHEDULED;
                        $post->scheduled_for = $when;
                        $post->published_at = null;
                    } else {
                        $post->status = BlogPost::STATUS_PUBLISHED;
                        $post->published_at = $when;
                        $post->scheduled_for = null;
                    }
                } else {
                    // Schedule clicked without a date → fall back to draft.
                    $post->status = BlogPost::STATUS_DRAFT;
                }
                break;

            case 'archive':
                $post->status = BlogPost::STATUS_ARCHIVED;
                break;

            case 'save':
                // Pure edit — don't touch status unless caller explicitly switched.
                break;

            case 'draft':
            default:
                // New posts default to draft; updates preserve their status.
                if (! $post->exists) {
                    $post->status = BlogPost::STATUS_DRAFT;
                }
                break;
        }
    }

    /**
     * Sync the related-movies pivot from a posted ID list. Preserves
     * the admin-curated order via sort_order.
     */
    private function syncMovies(BlogPost $post, Request $request): void
    {
        $ids = $request->input('movie_ids', []);
        if (! is_array($ids)) {
            $ids = [];
        }

        $sync = [];
        $order = 0;
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            // Defensive: ignore IDs that don't resolve to a real movie row.
            $sync[$id] = ['sort_order' => $order++];
        }

        // Strip to known movies to keep referential integrity tidy.
        if (! empty($sync)) {
            $known = Movie::query()->whereIn('id', array_keys($sync))->pluck('id')->all();
            $sync = array_intersect_key($sync, array_flip($known));
        }

        $post->movies()->sync($sync);
    }
}
