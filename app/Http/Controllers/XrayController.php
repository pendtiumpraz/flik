<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use App\Models\MovieSceneActor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * X-Ray (O14) — Netflix-style "who's on screen right now?" overlay backend.
 *
 * The frontend (resources/js/player/xray-overlay.js) polls this endpoint every
 * few seconds with the current playback time and renders clickable hotspots
 * for any cast members visible in that window.
 */
class XrayController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * GET /api/xray/{movie}?t={seconds}
     *
     * Returns actors visible at the given playback second, with bio excerpts
     * and on-screen hotspot coordinates (if known).
     */
    public function forMovie(Movie $movie, Request $request): JsonResponse
    {
        $second = (float) $request->query('t', '0');
        if ($second < 0) {
            $second = 0.0;
        }

        $presences = MovieSceneActor::query()
            ->with('cast')
            ->where('movie_id', $movie->id)
            ->activeAt($second)
            ->orderByDesc('confidence')
            ->get();

        // Map presence rows → flat actor payload. We pull the `character` from
        // the cast_movie pivot so the overlay can show "Tony Stark" not just "RDJ".
        $characterByCastId = $movie->castMembers()
            ->whereIn('casts.id', $presences->pluck('cast_id')->all())
            ->get()
            ->mapWithKeys(fn ($cast) => [$cast->id => $cast->pivot->character ?? null])
            ->all();

        $actors = $presences
            ->filter(fn ($p) => $p->cast !== null)
            ->map(function (MovieSceneActor $p) use ($characterByCastId) {
                $cast = $p->cast;

                return [
                    'id'           => $cast->id,
                    'name'         => $cast->name,
                    'character'    => $characterByCastId[$cast->id] ?? null,
                    'bio_excerpt'  => $this->excerpt($cast->bio),
                    'photo_url'    => $this->photoUrl($cast->profile_path),
                    'screen_x'     => $p->screen_x !== null ? (float) $p->screen_x : null,
                    'screen_y'     => $p->screen_y !== null ? (float) $p->screen_y : null,
                ];
            })
            ->values();

        return response()->json([
            'actors' => $actors,
        ]);
    }

    /**
     * Trim the bio down to a tooltip-sized excerpt (~180 chars on word boundary).
     */
    protected function excerpt(?string $bio): ?string
    {
        if ($bio === null) return null;

        $bio = trim($bio);
        if ($bio === '') return null;

        if (mb_strlen($bio) <= 180) {
            return $bio;
        }

        $cut = mb_substr($bio, 0, 180);
        $lastSpace = mb_strrpos($cut, ' ');

        return ($lastSpace !== false ? mb_substr($cut, 0, $lastSpace) : $cut) . '…';
    }

    /**
     * Resolve photo URL — accepts either a full http(s) URL or a storage path.
     */
    protected function photoUrl(?string $path): ?string
    {
        if ($path === null || $path === '') return null;
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }
        return asset('storage/' . ltrim($path, '/'));
    }
}
