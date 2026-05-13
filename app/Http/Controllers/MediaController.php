<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Movie;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Signed-URL gated media accessors.
 *
 * These endpoints are how the front-end reaches images that live on the
 * `private` disk (i.e. anything stored under `storage/app/private/...`).
 *
 * The `signed` middleware on each route validates the URL signature BEFORE
 * the controller is hit, so a tampered or expired link returns 403 with no
 * controller code running. The TTL is set on the URL itself by the
 * `Movie::get*UrlAttribute` accessors (see app/Models/Movie.php).
 *
 * Backwards compatibility:
 *   - Movies whose poster/backdrop is a public URL (CDN / Bunny / S3
 *     public bucket) NEVER hit this controller — the accessors short-
 *     circuit and return the direct URL string.
 *   - Movies on the legacy `public` disk (storage/app/public symlinked
 *     into public/storage) ALSO bypass this controller.
 *   - Only files on the `private` disk are routed through here.
 */
class MediaController extends Controller
{
    public function poster(Movie $movie): SymfonyResponse
    {
        return $this->serve($movie->poster_path, 'image/jpeg');
    }

    public function backdrop(Movie $movie): SymfonyResponse
    {
        return $this->serve($movie->backdrop_path, 'image/jpeg');
    }

    public function slider(Movie $movie): SymfonyResponse
    {
        return $this->serve($movie->slider_path, 'image/jpeg');
    }

    /**
     * Stream a file from the private disk with a sensible cache header.
     */
    protected function serve(?string $path, string $fallbackMime): SymfonyResponse
    {
        if ($path === null || $path === '' || ! Storage::disk('private')->exists($path)) {
            abort(404);
        }

        // Build a real Content-Type from the on-disk MIME so re-encoded
        // images keep their type even if the path extension lies.
        $mime = Storage::disk('private')->mimeType($path) ?: $fallbackMime;

        return Storage::disk('private')->response($path, null, [
            'Content-Type' => $mime,
            // 2-hour cache matches the default signed-URL TTL — no point
            // letting browsers cache past the URL expiry.
            'Cache-Control' => 'private, max-age=7200',
        ]);
    }
}
