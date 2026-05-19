<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Movie;
use App\Models\Season;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Admin CRUD for Seasons of a (series) Movie.
 *
 * Mounted as a nested resource under /admin/movies/{movie}/seasons.
 * Outer middleware (`can:movies.update`) is applied at the route level
 * — the controller itself is intentionally not declaring its own
 * authorize() calls so the route-table is the single source of truth.
 *
 * Counter maintenance: `movies.total_seasons` is bumped on store /
 * decremented on destroy inside a transaction so the column stays
 * consistent with the actual children rows even when the admin form
 * is hit concurrently.
 */
class SeasonController extends Controller
{
    /**
     * List seasons for a movie (per-movie index — not a flat index).
     */
    public function index(Movie $movie): View
    {
        $seasons = $movie->seasons()
            ->withCount('episodes')
            ->orderBy('season_number')
            ->get();

        return view('admin.seasons.index', [
            'movie'   => $movie,
            'seasons' => $seasons,
        ]);
    }

    public function create(Movie $movie): View
    {
        // Auto-suggest the next season number so the admin form doesn't
        // bounce on the (movie_id, season_number) unique constraint.
        $nextNumber = ((int) $movie->seasons()->max('season_number')) + 1;

        return view('admin.seasons.create', [
            'movie'       => $movie,
            'nextNumber'  => $nextNumber,
        ]);
    }

    public function store(Request $request, Movie $movie): RedirectResponse
    {
        $validated = $request->validate([
            'season_number' => ['required', 'integer', 'min:1', 'max:99'],
            'title'         => ['nullable', 'string', 'max:200'],
            'overview'      => ['nullable', 'string', 'max:5000'],
            'poster_path'   => ['nullable', 'string', 'max:500'],
            'air_date'      => ['nullable', 'date'],
        ]);

        // Friendly unique check (controller-side) — the DB UNIQUE
        // index is the real guard, but a 500 on duplicate would be
        // ugly for the admin user.
        $exists = $movie->seasons()
            ->where('season_number', $validated['season_number'])
            ->exists();
        if ($exists) {
            return back()
                ->withInput()
                ->with('error', "Season {$validated['season_number']} sudah ada untuk seri ini.");
        }

        DB::transaction(function () use ($movie, $validated) {
            $movie->seasons()->create($validated);

            // Keep the denormalised counter on the parent movie in sync.
            $movie->forceFill([
                'total_seasons' => $movie->seasons()->count(),
                // Promote to series automatically the first time a season
                // is added — admins don't have to remember to flip the
                // content_type switch on the movie form.
                'content_type'  => 'series',
            ])->save();
        });

        return redirect()
            ->route('admin.movies.seasons.index', $movie)
            ->with('success', "Season {$validated['season_number']} berhasil dibuat.");
    }

    public function edit(Movie $movie, Season $season): View
    {
        $this->ensureBelongs($movie, $season);

        return view('admin.seasons.edit', [
            'movie'  => $movie,
            'season' => $season,
        ]);
    }

    public function update(Request $request, Movie $movie, Season $season): RedirectResponse
    {
        $this->ensureBelongs($movie, $season);

        $validated = $request->validate([
            'season_number' => ['required', 'integer', 'min:1', 'max:99'],
            'title'         => ['nullable', 'string', 'max:200'],
            'overview'      => ['nullable', 'string', 'max:5000'],
            'poster_path'   => ['nullable', 'string', 'max:500'],
            'air_date'      => ['nullable', 'date'],
        ]);

        if ($validated['season_number'] !== $season->season_number) {
            $conflict = $movie->seasons()
                ->where('season_number', $validated['season_number'])
                ->where('id', '!=', $season->id)
                ->exists();
            if ($conflict) {
                return back()
                    ->withInput()
                    ->with('error', "Season {$validated['season_number']} sudah dipakai seri ini.");
            }
        }

        $season->update($validated);

        return redirect()
            ->route('admin.movies.seasons.index', $movie)
            ->with('success', "Season {$season->season_number} berhasil diupdate.");
    }

    public function destroy(Movie $movie, Season $season): RedirectResponse
    {
        $this->ensureBelongs($movie, $season);

        DB::transaction(function () use ($movie, $season) {
            // Cascade FKs handle episodes; we just refresh counters.
            $season->delete();
            $movie->forceFill([
                'total_seasons'  => $movie->seasons()->count(),
                'total_episodes' => $movie->episodes()->count(),
            ])->save();
        });

        return redirect()
            ->route('admin.movies.seasons.index', $movie)
            ->with('success', 'Season dihapus.');
    }

    /**
     * 404 if the season URL doesn't actually belong to the URL's movie.
     * Belt + braces: the route table uses scoped bindings already, but
     * an explicit check protects edits that bypass scopeBindings().
     */
    protected function ensureBelongs(Movie $movie, Season $season): void
    {
        abort_if((int) $season->movie_id !== (int) $movie->id, 404);
    }
}
