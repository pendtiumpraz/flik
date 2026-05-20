<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BlogCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * BlogCategoryController — simple CRUD for the editorial taxonomy.
 *
 * Per-action route gate: `can:blog.manage` (see routes/web.php).
 */
class BlogCategoryController extends Controller
{
    public function index(): View
    {
        $categories = BlogCategory::query()
            ->ordered()
            ->withCount('posts')
            ->get();

        return view('admin.blog.categories.index', [
            'categories' => $categories,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatePayload($request);
        BlogCategory::create($data);

        return redirect()
            ->route('admin.blog.categories.index')
            ->with('success', 'Kategori dibuat.');
    }

    public function update(Request $request, BlogCategory $category): RedirectResponse
    {
        $data = $this->validatePayload($request, $category);
        $category->update($data);

        return redirect()
            ->route('admin.blog.categories.index')
            ->with('success', 'Kategori diperbarui.');
    }

    public function destroy(BlogCategory $category): RedirectResponse
    {
        // Posts pointing at this category get category_id=null via the FK's
        // nullOnDelete. We don't need to detach manually.
        $category->delete();

        return redirect()
            ->route('admin.blog.categories.index')
            ->with('success', 'Kategori dihapus.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, ?BlogCategory $cat = null): array
    {
        $slugRule = Rule::unique('blog_categories', 'slug');
        if ($cat) {
            $slugRule = $slugRule->ignore($cat->id);
        }

        return $request->validate([
            'name'       => ['required', 'string', 'max:120'],
            'slug'       => ['nullable', 'string', 'max:80', $slugRule],
            'color'      => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'sort_order' => ['nullable', 'integer', 'between:-32768,32767'],
        ]);
    }
}
