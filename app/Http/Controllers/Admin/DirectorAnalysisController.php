<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DirectorAnalysis;
use App\Services\Ai\Tasks\DirectorAuteurAnalyzer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Admin UI for the Director Auteur Analysis feature (O12).
 *
 * Routes are NOT registered here — wire them in routes/web.php manually.
 * Suggested:
 *   GET  /admin/director-analyses             → index
 *   POST /admin/director-analyses             → analyze
 *   GET  /admin/director-analyses/{slug}      → show
 *   DELETE /admin/director-analyses/{slug}    → destroy (optional)
 */
class DirectorAnalysisController extends Controller
{
    public function __construct(
        protected DirectorAuteurAnalyzer $analyzer,
    ) {}

    /**
     * List all directors that have been analysed.
     */
    public function index(): View
    {
        $analyses = DirectorAnalysis::query()
            ->orderByDesc('generated_at')
            ->orderByDesc('id')
            ->paginate(20);

        return view('admin.director-analyses.index', [
            'analyses' => $analyses,
        ]);
    }

    /**
     * Run the analyzer for a (possibly new) director name.
     */
    public function analyze(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'director_name' => ['required', 'string', 'min:2', 'max:200'],
            'count'         => ['nullable', 'integer', 'min:3', 'max:10'],
            'force'         => ['nullable', 'boolean'],
        ]);

        $name  = trim($validated['director_name']);
        $count = $validated['count'] ?? 5;
        $force = (bool) ($validated['force'] ?? false);

        try {
            $result = $force
                ? $this->analyzer->refresh($name, $count)
                : $this->analyzer->analyze($name, $count);
        } catch (\Throwable $e) {
            Log::error('DirectorAnalysisController analyze failed', [
                'director' => $name,
                'error'    => $e->getMessage(),
            ]);

            return back()
                ->withInput()
                ->with('error', 'Gagal generate analysis: ' . $e->getMessage());
        }

        return redirect()
            ->route('admin.director-analyses.show', $result['slug'])
            ->with('success', 'Analisis auteur untuk ' . $result['director_name'] . ' berhasil di-generate.');
    }

    /**
     * Display the analysis for a single director (lookup by slug).
     */
    public function show(string $directorSlug): View
    {
        $analysis = DirectorAnalysis::where('slug', $directorSlug)->firstOrFail();

        return view('admin.director-analyses.show', [
            'analysis' => $analysis,
        ]);
    }

    /**
     * Force-refresh an existing analysis (optional helper).
     */
    public function refresh(string $directorSlug): RedirectResponse
    {
        $row = DirectorAnalysis::where('slug', $directorSlug)->firstOrFail();

        try {
            $this->analyzer->refresh($row->director_name);
        } catch (\Throwable $e) {
            Log::error('DirectorAnalysisController refresh failed', [
                'director' => $row->director_name,
                'error'    => $e->getMessage(),
            ]);

            return back()->with('error', 'Gagal refresh analysis: ' . $e->getMessage());
        }

        return redirect()
            ->route('admin.director-analyses.show', $row->slug)
            ->with('success', 'Analisis untuk ' . $row->director_name . ' di-refresh.');
    }

    /**
     * Delete a stored analysis (optional helper).
     */
    public function destroy(string $directorSlug): RedirectResponse
    {
        $row = DirectorAnalysis::where('slug', $directorSlug)->firstOrFail();
        $name = $row->director_name;
        $row->delete();

        return redirect()
            ->route('admin.director-analyses.index')
            ->with('success', 'Analisis untuk ' . $name . ' dihapus.');
    }
}
