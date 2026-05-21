<?php

namespace App\Http\Controllers;

use App\Models\Episode;
use App\Models\WatchHistory;
use App\Services\Drm\ConcurrentStreamLimiter;
use App\Services\Drm\DeviceFingerprinter;
use App\Services\Drm\DrmKeyService;
use App\Services\Drm\DrmTokenService;
use App\Services\Drm\PlaybackManifestGenerator;
use App\Services\Geo\GeoIpResolver;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Per-episode player. Lives behind the `auth` middleware in web.php because
 * progress tracking + "next episode" only make sense for an authenticated viewer.
 *
 * DRM enforcement parity with the movie playback flow (audit FIX #2 §5):
 *   - Geo allow-list check via the parent Movie's geo_allow column.
 *   - Concurrent-stream lock acquisition (same per-user cap as movies).
 *   - JWT manifest URL exposed to the view for HLS playback. The legacy raw
 *     mp4 path is GATED behind an admin-side "allow_raw_mp4" config flag —
 *     by default, episodes without an HLS manifest now refuse to play
 *     (returns a friendly notice instead of leaking the master file).
 */
class EpisodeWatchController extends Controller
{
    public function __construct(
        protected DrmKeyService $keys,
        protected DrmTokenService $tokens,
        protected ConcurrentStreamLimiter $streams,
        protected DeviceFingerprinter $fingerprinter,
        protected PlaybackManifestGenerator $manifests,
        protected GeoIpResolver $geo,
    ) {
    }

    /**
     * Full-screen episode player view.
     *
     * @param  Episode  $episode  Route-model-bound by primary key.
     */
    public function show(Request $request, Episode $episode): View
    {
        // Eager-load everything the view + auto-next overlay need so we
        // hit the DB once instead of N times during the render.
        $episode->load(['movie', 'season.episodes']);

        $movie = $episode->movie;

        // ━━━ DRM Gate #1 — Geo block (per audit FIX #2 §3.1 + §5) ━━━
        // The {episode} route doesn't carry a {movie} param so the global
        // `geoblock` middleware can't fire; enforce inline here.
        if ($movie !== null) {
            $allowList = is_array($movie->geo_allow) ? $movie->geo_allow : null;
            if (is_array($allowList) && $allowList !== []) {
                $ip = (string) $request->ip();
                if (! $this->geo->isAllowed($ip, $allowList)) {
                    throw new HttpException(451, 'Konten tidak tersedia di negara Anda');
                }
            }
        }

        $next = $movie?->nextEpisodeAfter($episode);
        $previous = $episode->previousInSeason();

        $resumeAt = 0;
        if (auth()->check()) {
            $history = WatchHistory::query()
                ->where('user_id', auth()->id())
                ->where('episode_id', $episode->id)
                ->first();
            $resumeAt = (int) ($history?->current_time
                ?? $history?->progress_seconds
                ?? 0);
        }

        // ━━━ DRM Gate #2 — Concurrent stream cap (per audit FIX #2 §5) ━━━
        // Episodes share the same per-user stream cap as movies. We mint a
        // DrmSession bound to the PARENT movie (not the episode) so all
        // episodes of the same series count as one slot.
        $drmBundle = null;
        $user = $request->user();

        if ($user !== null && $movie !== null && $episode->hls_manifest_path) {
            $maxStreams = $this->streams->getMaxStreamsForUser($user);
            $candidateToken = bin2hex(random_bytes(32));

            if (! $this->streams->acquire($user, $candidateToken, $movie->id, $maxStreams)) {
                throw new HttpException(
                    429,
                    "Concurrent stream limit ({$maxStreams}) reached. Stop another device first."
                );
            }

            $session = $this->keys->createSession(
                user: $user,
                movie: $movie,
                deviceFingerprint: null,
                ip: $request->ip(),
                country: null,
                ttlMinutes: 30,
            );
            $this->streams->release($candidateToken);
            $this->streams->acquire($user, $session->session_token, $movie->id, $maxStreams);

            $playbackJwt = $this->tokens->issuePlaybackToken($session, 600);
            $drmBundle = [
                'manifest_url' => route('playback.manifest', ['movie' => $movie])
                    . '?token=' . rawurlencode($playbackJwt),
                'session_token' => $session->session_token,
                'jwt' => $playbackJwt,
                'heartbeat_url' => route('playback.heartbeat', ['movie' => $movie]),
                'fingerprint_script' => $this->fingerprinter->generateClientFingerprintScript(),
            ];
        }

        return view('episodes.watch', [
            'episode'   => $episode,
            'movie'     => $movie,
            'season'    => $episode->season,
            'next'      => $next,
            'previous'  => $previous,
            'resumeAt'  => $resumeAt,
            'drmBundle' => $drmBundle,
        ]);
    }
}
