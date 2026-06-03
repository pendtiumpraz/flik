<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Cast;
use App\Models\DirectorAnalysis;
use App\Models\Movie;
use App\Services\Ai\Tasks\CastBiographyEnricher;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Public Cast / Director profile pages.
 *
 * Routes (registered in routes/web.php):
 *   GET  /cast                       → index   (public.cast.index)
 *   GET  /cast/{cast}                → show    (public.cast.show) — 301s to slug URL when canonical
 *   GET  /cast/{cast}/{slug?}        → show    (public.cast.show) — canonical
 *   POST /admin/cast/{cast}/enrich-bio → enrichBio (admin-only)
 *   GET  /api/movies/{movie}/cast    → byMovie (JSON; for embed components)
 */
class PublicCastController extends Controller
{
    /**
     * Paginated cast list — searchable and filterable by inferred role.
     */
    public function index(Request $request): View
    {
        $q     = trim((string) $request->query('q', ''));
        $role  = strtolower((string) $request->query('role', 'all'));
        $valid = ['all', 'actor', 'director'];
        if (! in_array($role, $valid, true)) {
            $role = 'all';
        }

        $casts = Cast::query()
            ->withMovies()
            ->withCount('movies')
            ->when($q !== '', fn ($qq) => $qq->whereLike('name', '%' . $q . '%'))
            ->when($role !== 'all', fn ($qq) => $qq->ofRole($role))
            ->orderByDesc('movies_count')
            ->orderBy('name')
            ->paginate(24)
            ->withQueryString();

        return view('cast.index', [
            'casts' => $casts,
            'q'     => $q,
            'role'  => $role,
        ]);
    }

    /**
     * Show a single cast / director profile.
     *
     * Canonical URL pattern is `/cast/{id}/{slug}`. When the slug is missing
     * or stale we 301 to the correct one — this is the SEO contract that
     * makes /cast/42 land at /cast/42/john-doe permanently.
     */
    public function show(Cast $cast, ?string $slug = null): View|RedirectResponse
    {
        $canonical = $cast->slug;

        // 301 to canonical URL when slug is absent or stale. Compare lowercased
        // so capitalisation differences from external links also normalise.
        if ($slug === null || strtolower($slug) !== strtolower($canonical)) {
            return redirect()->route('public.cast.show', [
                'cast' => $cast->id,
                'slug' => $canonical,
            ], 301);
        }

        // Eager-load movies once with pivot, then split client-side for the
        // tabs. Cheaper than two queries against the same pivot.
        $cast->load(['movies' => function ($q) {
            $q->select('movies.id', 'movies.title', 'movies.slug', 'movies.poster_path', 'movies.backdrop_path', 'movies.release_date', 'movies.vote_average')
              ->withPivot('character', 'order')
              ->orderByDesc('movies.release_date');
        }]);

        $directedMovies = $cast->movies->filter(fn (Movie $m) => $this->isDirectorPivot($m->pivot->character ?? null))->values();
        $actedMovies    = $cast->movies->filter(fn (Movie $m) => ! $this->isDirectorPivot($m->pivot->character ?? null))->values();

        // For directors with 3+ films we lazily attach any existing
        // DirectorAnalysis row (no AI call here — admin triggers that).
        $directorAnalysis = null;
        if ($directedMovies->count() >= 3) {
            $directorAnalysis = DirectorAnalysis::query()
                ->where('director_name', $cast->name)
                ->orWhere('slug', $cast->slug)
                ->first();
        }

        // Stats — cheap aggregates derived from the already-loaded relation.
        $allMovies     = $cast->movies;
        $movieCount    = $allMovies->count();
        $avgRating     = $allMovies->avg(fn ($m) => (float) ($m->vote_average ?? 0));
        $activeYears   = $this->resolveActiveYears($allMovies);

        // Awards placeholder — sourced from cast->bio or future awards table.
        $awards = [];

        return view('cast.show', [
            'cast'             => $cast,
            'actedMovies'      => $actedMovies,
            'directedMovies'   => $directedMovies,
            'allMovies'        => $allMovies,
            'directorAnalysis' => $directorAnalysis,
            'awards'           => $awards,
            'movieCount'       => $movieCount,
            'avgRating'        => $avgRating ? round((float) $avgRating, 1) : null,
            'activeYears'      => $activeYears,
        ]);
    }

    /**
     * Admin-only: trigger CastBiographyEnricher in-band.
     *
     * Light enough for synchronous execution (one web search + one AI call).
     * If we ever need it async, queue an EnrichCastBioJob with this body.
     */
    public function enrichBio(Cast $cast, CastBiographyEnricher $enricher, Request $request): RedirectResponse
    {
        // Defence-in-depth: route is already guarded by `can:admin`, but we
        // re-check inside the controller so a future routing mistake can't
        // expose this endpoint to non-admins.
        if (! $request->user() || ! $request->user()->can('admin')) {
            abort(403);
        }

        try {
            $enricher->enrich($cast);
        } catch (\Throwable $e) {
            Log::error('PublicCastController: enrichBio failed', [
                'cast_id' => $cast->id,
                'error'   => $e->getMessage(),
            ]);
            return back()->with('error', 'Gagal memperkaya bio: ' . $e->getMessage());
        }

        return back()->with('success', 'Bio berhasil diperkaya untuk ' . $cast->name . '.');
    }

    /**
     * JSON helper for the movie-detail page cast list (used by future embed).
     * Returns a flat list of {id, name, character, profile_image, slug, url}.
     */
    public function byMovie(Movie $movie): JsonResponse
    {
        $cast = $movie->castMembers()->orderBy('cast_movie.order')->get();

        return response()->json([
            'movie_id' => $movie->id,
            'cast'     => $cast->map(fn (Cast $c) => [
                'id'            => $c->id,
                'name'          => $c->name,
                'character'     => $c->pivot->character,
                'profile_image' => $c->profile_image,
                'slug'          => $c->slug,
                'url'           => route('public.cast.show', ['cast' => $c->id, 'slug' => $c->slug]),
            ])->values(),
        ]);
    }

    // ━━━ helpers ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Active years string, e.g. "2008–2024". Returns null when no release
     * dates are available on the loaded movies.
     */
    protected function resolveActiveYears(\Illuminate\Support\Collection $movies): ?string
    {
        $years = $movies
            ->map(fn ($m) => $m->release_date ? (int) $m->release_date->format('Y') : null)
            ->filter()
            ->unique()
            ->sort()
            ->values();

        if ($years->isEmpty()) {
            return null;
        }

        $min = $years->first();
        $max = $years->last();

        return $min === $max ? (string) $min : $min . '–' . $max;
    }

    /**
     * Mirror of Cast::isDirectorPivot() — duplicated here because the
     * accessor on the model is protected (avoid widening its visibility
     * just to satisfy controller introspection).
     */
    protected function isDirectorPivot(?string $character): bool
    {
        if ($character === null) {
            return true;
        }
        $lower = strtolower($character);
        return str_contains($lower, 'director') || str_contains($lower, 'sutradara');
    }
}
