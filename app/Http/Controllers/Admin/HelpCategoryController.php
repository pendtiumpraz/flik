<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HelpCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin CRUD for Help Center categories.
 *
 * Permission gate: `can:help.manage` (routes/web.php).
 */
class HelpCategoryController extends Controller
{
    public function index(): View
    {
        $categories = HelpCategory::query()
            ->ordered()
            ->withCount(['allArticles as articles_total'])
            ->get();

        return view('admin.help.categories.index', [
            'categories' => $categories,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatePayload($request);

        HelpCategory::create($data);

        return redirect()
            ->route('admin.help.categories.index')
            ->with('success', 'Kategori dibuat.');
    }

    public function update(Request $request, HelpCategory $category): RedirectResponse
    {
        $data = $this->validatePayload($request, $category);

        $category->fill($data)->save();

        return redirect()
            ->route('admin.help.categories.index')
            ->with('success', 'Kategori diperbarui.');
    }

    public function destroy(HelpCategory $category): RedirectResponse
    {
        // FK is nullOnDelete — articles in this category lose the link but
        // remain published. Recount handled inside HelpArticle::saved hooks
        // (set_null doesn't fire model events, but the bucket is gone anyway).
        $category->delete();

        return redirect()
            ->route('admin.help.categories.index')
            ->with('success', 'Kategori dihapus.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, ?HelpCategory $category = null): array
    {
        $slugRule = Rule::unique('help_categories', 'slug');
        if ($category) {
            $slugRule = $slugRule->ignore($category->id);
        }

        return $request->validate([
            'name'        => ['required', 'string', 'max:120'],
            'slug'        => ['nullable', 'string', 'max:80', $slugRule],
            'description' => ['nullable', 'string', 'max:2000'],
            'icon'        => ['nullable', 'string', 'max:40'],
            'sort_order'  => ['nullable', 'integer', 'min:-32000', 'max:32000'],
        ]);
    }
}
