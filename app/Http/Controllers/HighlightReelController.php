<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use App\Models\MovieHighlightReel;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Public-facing access to a movie's auto-generated 3-minute highlight reel.
 *
 * Routes (suggested — wire in routes/web.php):
 *   GET  /movie/{movie}/highlight           → show()
 *   GET  /movie/{movie}/highlight/download  → download()  (auth required)
 */
class HighlightReelController extends Controller
{
    /**
     * Render the embedded video player for the highlight reel.
     *
     * Falls back to a friendly empty-state when the reel hasn't been
     * generated yet (or the source movie has no video file).
     *
     * Movie is resolved by slug via Movie::getRouteKeyName().
     */
    public function show(Movie $movie): View|Factory|RedirectResponse
    {
        $movie->loadMissing('genres');

        $reel = MovieHighlightReel::where('movie_id', $movie->id)
            ->orderByDesc('id')
            ->first();

        $reelUrl = ($reel && $reel->isReady()) ? $reel->url : null;

        return view('components.movies.highlight-reel', [
            'movie'        => $movie,
            'reel'         => $reel,
            'reelUrl'      => $reelUrl,
            'isReady'      => (bool) $reelUrl,
            'errorMessage' => $reel?->error_message,
        ]);
    }

    /**
     * Force-download the reel as <slug>-highlight.mp4.
     *
     * Auth gate is enforced both via route middleware('auth') and as
     * defence-in-depth here.
     */
    public function download(Movie $movie): StreamedResponse|RedirectResponse
    {
        if (!auth()->check()) {
            return redirect()->route('login');
        }

        $reel = MovieHighlightReel::where('movie_id', $movie->id)
            ->where('status', 'ready')
            ->orderByDesc('id')
            ->first();

        if (!$reel || empty($reel->reel_path)) {
            abort(404, 'Highlight reel is not available for this movie.');
        }

        $disk = $reel->reel_disk ?: 'public';

        if (!Storage::disk($disk)->exists($reel->reel_path)) {
            abort(404, 'Highlight reel file is missing on storage.');
        }

        $filename = ($movie->slug ?: ('movie-' . $movie->id)) . '-highlight.mp4';

        return Storage::disk($disk)->download($reel->reel_path, $filename, [
            'Content-Type' => 'video/mp4',
        ]);
    }
}
