<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Episode;
use App\Models\Movie;
use App\Models\Season;
use App\Services\Ai\Tasks\EpisodeSummarizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Admin CRUD for Episodes (nested under a Season of a series Movie).
 *
 * Counter convention mirrors SeasonController: every create/destroy
 * recomputes `seasons.episode_count` + `movies.total_episodes` inside
 * the same transaction so dashboards stay accurate.
 *
 * "AI Fill" button on the create form posts `ai_fill=1`, which calls
 * EpisodeSummarizer right after the row lands. The blurb persists to
 * `episodes.generated_summary` via the service (forceFill) — never
 * via mass-assignment here.
 */
class EpisodeController extends Controller
{
    public function index(Movie $movie, Season $season): View
    {
        $this->ensureBelongs($movie, $season);

        $episodes = $season->episodes()
            ->orderBy('episode_number')
            ->get();

        return view('admin.episodes.index', [
            'movie'    => $movie,
            'season'   => $season,
            'episodes' => $episodes,
        ]);
    }

    public function create(Movie $movie, Season $season): View
    {
        $this->ensureBelongs($movie, $season);

        $nextNumber = ((int) $season->episodes()->max('episode_number')) + 1;

        return view('admin.episodes.create', [
            'movie'      => $movie,
            'season'     => $season,
            'nextNumber' => $nextNumber,
        ]);
    }

    public function store(
        Request $request,
        Movie $movie,
        Season $season,
        EpisodeSummarizer $summarizer,
    ): RedirectResponse {
        $this->ensureBelongs($movie, $season);

        $validated = $request->validate([
            'episode_number'      => ['required', 'integer', 'min:1', 'max:999'],
            'title'               => ['required', 'string', 'max:200'],
            'overview'            => ['nullable', 'string', 'max:5000'],
            'still_path'          => ['nullable', 'string', 'max:500'],
            'runtime_minutes'     => ['nullable', 'integer', 'min:1', 'max:600'],
            'air_date'            => ['nullable', 'date'],
            'video_path'          => ['nullable', 'string', 'max:500'],
            'video_disk'          => ['nullable', 'string', 'max:50'],
            'hls_manifest_path'   => ['nullable', 'string', 'max:500'],
            'intro_start_seconds' => ['nullable', 'integer', 'min:0'],
            'intro_end_seconds'   => ['nullable', 'integer', 'min:0'],
            'outro_start_seconds' => ['nullable', 'integer', 'min:0'],
            // Trigger toggle from the form's "AI fill" button.
            'ai_fill'             => ['nullable', 'boolean'],
        ]);

        $duplicate = $season->episodes()
            ->where('episode_number', $validated['episode_number'])
            ->exists();
        if ($duplicate) {
            return back()
                ->withInput()
                ->with('error', "Episode {$validated['episode_number']} sudah ada di season ini.");
        }

        $aiFill = (bool) ($validated['ai_fill'] ?? false);
        unset($validated['ai_fill']);

        // Denormalised movie_id makes the (movie_id, air_date) index useful
        // for "all episodes in release order" queries.
        $validated['movie_id'] = $movie->id;

        $episode = DB::transaction(function () use ($season, $movie, $validated) {
            $episode = $season->episodes()->create($validated);

            $season->forceFill([
                'episode_count' => $season->episodes()->count(),
            ])->save();

            $movie->forceFill([
                'total_episodes' => $movie->episodes()->count(),
            ])->save();

            return $episode;
        });

        if ($aiFill) {
            // Service logs + swallows its own failures — never throws.
            $summarizer->summarize($episode, force: true);
        }

        return redirect()
            ->route('admin.movies.seasons.episodes.index', [$movie, $season])
            ->with('success', "Episode {$episode->episode_number}: {$episode->title} dibuat"
                . ($aiFill ? ' (dengan AI blurb).' : '.'));
    }

    public function edit(Movie $movie, Season $season, Episode $episode): View
    {
        $this->ensureBelongs($movie, $season, $episode);

        return view('admin.episodes.edit', [
            'movie'   => $movie,
            'season'  => $season,
            'episode' => $episode,
        ]);
    }

    public function update(
        Request $request,
        Movie $movie,
        Season $season,
        Episode $episode,
        EpisodeSummarizer $summarizer,
    ): RedirectResponse {
        $this->ensureBelongs($movie, $season, $episode);

        $validated = $request->validate([
            'episode_number'      => ['required', 'integer', 'min:1', 'max:999'],
            'title'               => ['required', 'string', 'max:200'],
            'overview'            => ['nullable', 'string', 'max:5000'],
            'still_path'          => ['nullable', 'string', 'max:500'],
            'runtime_minutes'     => ['nullable', 'integer', 'min:1', 'max:600'],
            'air_date'            => ['nullable', 'date'],
            'video_path'          => ['nullable', 'string', 'max:500'],
            'video_disk'          => ['nullable', 'string', 'max:50'],
            'hls_manifest_path'   => ['nullable', 'string', 'max:500'],
            'intro_start_seconds' => ['nullable', 'integer', 'min:0'],
            'intro_end_seconds'   => ['nullable', 'integer', 'min:0'],
            'outro_start_seconds' => ['nullable', 'integer', 'min:0'],
            'ai_fill'             => ['nullable', 'boolean'],
        ]);

        if ($validated['episode_number'] !== $episode->episode_number) {
            $conflict = $season->episodes()
                ->where('episode_number', $validated['episode_number'])
                ->where('id', '!=', $episode->id)
                ->exists();
            if ($conflict) {
                return back()
                    ->withInput()
                    ->with('error', "Episode {$validated['episode_number']} sudah dipakai.");
            }
        }

        $aiFill = (bool) ($validated['ai_fill'] ?? false);
        unset($validated['ai_fill']);

        $episode->update($validated);

        if ($aiFill) {
            $summarizer->summarize($episode, force: true);
        }

        return redirect()
            ->route('admin.movies.seasons.episodes.index', [$movie, $season])
            ->with('success', "Episode {$episode->episode_number} berhasil diupdate.");
    }

    public function destroy(Movie $movie, Season $season, Episode $episode): RedirectResponse
    {
        $this->ensureBelongs($movie, $season, $episode);

        DB::transaction(function () use ($movie, $season, $episode) {
            $episode->delete();

            $season->forceFill([
                'episode_count' => $season->episodes()->count(),
            ])->save();

            $movie->forceFill([
                'total_episodes' => $movie->episodes()->count(),
            ])->save();
        });

        return redirect()
            ->route('admin.movies.seasons.episodes.index', [$movie, $season])
            ->with('success', 'Episode dihapus.');
    }

    /**
     * Validate URL hierarchy: season belongs to movie, episode (if any)
     * belongs to season. Cheap defence against a crafted route that
     * passes IDs from a different series.
     */
    protected function ensureBelongs(Movie $movie, Season $season, ?Episode $episode = null): void
    {
        abort_if((int) $season->movie_id !== (int) $movie->id, 404);
        if ($episode !== null) {
            abort_if((int) $episode->season_id !== (int) $season->id, 404);
        }
    }
}
