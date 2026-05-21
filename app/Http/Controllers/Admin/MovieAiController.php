<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\AnalyzeSoundtrack;
use App\Models\Movie;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Per-movie AI action endpoints that don't fit the existing
 * MarketingAiController / AiReviewController / SubtitleController buckets.
 *
 * Currently houses:
 *   - soundtrack(POST) → queues AnalyzeSoundtrack for the given movie.
 */
final class MovieAiController extends Controller
{
    /**
     * Queue a soundtrack analysis pass for the movie. Result lands on
     * `movies.soundtrack_analysis` (JSON) — the public detail page
     * renders the <x-movies.soundtrack-analysis> component when the
     * column is populated.
     *
     * Permission gating is handled by the route middleware (can:admin).
     */
    public function soundtrack(Request $request, Movie $movie): RedirectResponse
    {
        AnalyzeSoundtrack::dispatch($movie->id);

        return back()->with('success', "Soundtrack analysis dijadwalkan untuk {$movie->title}. Cek halaman film dalam beberapa menit.");
    }
}
