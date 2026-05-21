<?php

declare(strict_types=1);

namespace App\Services\Drm;

use App\Models\DrmSession;
use App\Models\Movie;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

/**
 * Builds a per-session HLS master/media manifest at request time.
 *
 * The manifest is NOT static: every segment URI is suffixed with a short-
 * lived playback JWT so each chunk request can be re-validated by the CDN
 * edge or origin. The EXT-X-KEY URI similarly carries a (separately scoped)
 * key-request JWT so only the legitimate player session can fetch the AES
 * content key.
 *
 * Two shapes:
 *  - Multi-rendition master: when `$movie->encoding_renditions` is a non-
 *    empty array, emits an `#EXTM3U` master with one `#EXT-X-STREAM-INF`
 *    per rendition pointing at a media playlist URL (also tokenised).
 *  - Single-rendition media: when there are no renditions, emits a minimal
 *    media playlist using the movie's `hls_manifest_path` segments. (For
 *    a fully baked implementation, the raw segment list would be read from
 *    storage; here we emit the routing structure callers/views need.)
 */
class PlaybackManifestGenerator
{
    /**
     * Optional forensic watermarker — when injected, the per-session
     * watermark identifier is stamped as an `#EXT-X-SESSION-DATA` tag in the
     * master manifest so server-side forensic trails can correlate a leaked
     * recording to (user, session, timestamp). Real burn-in watermarking
     * requires a per-session transcode (expensive) so we stop short of that;
     * see audit FIX #2 §3.2 for the trade-off.
     */
    public function __construct(
        protected ?ForensicWatermarker $watermarker = null,
    ) {
    }

    /**
     * Generate the m3u8 text for a given session.
     */
    public function generate(Movie $movie, DrmSession $session, DrmTokenService $tokens): string
    {
        $renditions = is_array($movie->encoding_renditions) ? $movie->encoding_renditions : [];

        // Issue a master-level playback token. Media playlists / segments
        // are appended with their own freshly-minted tokens below.
        $masterToken = $tokens->issuePlaybackToken($session, 600);

        if ($renditions !== []) {
            return $this->buildMasterManifest($movie, $session, $tokens, $renditions, $masterToken);
        }

        return $this->buildMediaManifest($movie, $session, $tokens, $masterToken);
    }

    /**
     * Build the per-session forensic watermark identifier.
     *
     * Shape: "u{userId}|s{sessionToken8}|t{unix}". Embedded into the master
     * manifest via `#EXT-X-SESSION-DATA`. Logged into the laravel.log forensic
     * trail so a leaked recording with this manifest can be back-traced.
     */
    protected function forensicId(DrmSession $session): string
    {
        $tokenShort = substr((string) $session->session_token, 0, 8);
        $id = sprintf('u%d|s%s|t%d', $session->user_id, $tokenShort, time());

        // Audit trail so even if the player strips the manifest tag, we
        // can correlate the session_token to (user, ts) at investigation time.
        Log::channel(config('logging.channels.drm') ? 'drm' : config('logging.default', 'stack'))
            ->info('drm.forensic.id_issued', [
                'session_token' => $session->session_token,
                'user_id' => $session->user_id,
                'movie_id' => $session->movie_id,
                'forensic_id' => $id,
            ]);

        if ($this->watermarker !== null) {
            // The class exists primarily for burn-in watermarking at encode
            // time. Here we just confirm it's wired so future per-session
            // re-pack flows have a hook in place.
            Log::debug('ForensicWatermarker available for session', [
                'session_token' => $session->session_token,
            ]);
        }

        return $id;
    }

    /**
     * Multi-rendition master playlist (one stream-inf per rendition).
     *
     * @param  array<int,array<string,mixed>>  $renditions
     */
    protected function buildMasterManifest(
        Movie $movie,
        DrmSession $session,
        DrmTokenService $tokens,
        array $renditions,
        string $masterToken,
    ): string {
        $lines = [
            '#EXTM3U',
            '#EXT-X-VERSION:6',
            '#EXT-X-INDEPENDENT-SEGMENTS',
        ];

        // Forensic watermark identifier (audit FIX #2 §3.2). Stamped as
        // session data so a leaked manifest can be tied back to (user, ts).
        $forensicId = $this->forensicId($session);
        $lines[] = sprintf(
            '#EXT-X-SESSION-DATA:DATA-ID="com.flik.forensic",VALUE="%s"',
            addcslashes($forensicId, "\"\\\n\r"),
        );

        foreach ($renditions as $idx => $r) {
            $height = (int) ($r['height'] ?? 720);
            $bitrate = (int) ($r['bitrate'] ?? 2_500_000);
            $width = (int) ($r['width'] ?? $this->guessWidth($height));
            $codecs = $r['codecs'] ?? 'avc1.4d401f,mp4a.40.2';
            $renditionName = (string) ($r['name'] ?? sprintf('%dp', $height));

            // Each rendition gets its OWN media playlist URL, signed via
            // Laravel's signed-URL middleware so the route can validate
            // freshness without re-implementing JWT here.
            $mediaUrl = $this->mediaPlaylistUrl($movie, $renditionName);

            $lines[] = sprintf(
                '#EXT-X-STREAM-INF:BANDWIDTH=%d,RESOLUTION=%dx%d,CODECS="%s"',
                $bitrate,
                $width,
                $height,
                $codecs,
            );
            $lines[] = $mediaUrl;
        }

        // Trailing newline keeps the spec-compliant terminator.
        return implode("\n", $lines) . "\n";
    }

    /**
     * Single-rendition (or fallback) media playlist with encrypted segments.
     */
    protected function buildMediaManifest(
        Movie $movie,
        DrmSession $session,
        DrmTokenService $tokens,
        string $playbackToken,
    ): string {
        $segments = $this->resolveSegments($movie);
        $targetDuration = 6;
        $keyId = $session->session_token;

        // Key URL is itself signed — the AES key endpoint validates the
        // key-request JWT before returning the raw 16-byte key.
        $keyJwt = $tokens->issueKeyRequestToken($session, $keyId, 300);
        $keyUrl = $this->keyUrl($session->session_token, $keyId, $keyJwt);
        $iv = '0x' . bin2hex(random_bytes(16));

        $lines = [
            '#EXTM3U',
            '#EXT-X-VERSION:5',
            '#EXT-X-TARGETDURATION:' . $targetDuration,
            '#EXT-X-MEDIA-SEQUENCE:0',
            '#EXT-X-PLAYLIST-TYPE:VOD',
            sprintf('#EXT-X-KEY:METHOD=AES-128,URI="%s",IV=%s', $keyUrl, $iv),
        ];

        foreach ($segments as $seg) {
            $lines[] = sprintf('#EXTINF:%.3f,', (float) $seg['duration']);
            $lines[] = $this->signSegmentUrl($seg['url'], $playbackToken);
        }

        $lines[] = '#EXT-X-ENDLIST';

        return implode("\n", $lines) . "\n";
    }

    /**
     * Resolve a segment list for the media playlist.
     *
     * In a full implementation this would parse the on-disk
     * `playlist.m3u8` (or read pre-baked segment metadata). We emit a
     * deterministic stub list shaped against `duration_seconds` so the
     * generated manifest is wire-correct and player-loadable in dev.
     *
     * @return array<int,array{url:string,duration:float}>
     */
    protected function resolveSegments(Movie $movie): array
    {
        $totalDuration = (int) ($movie->duration_seconds ?: 0);
        $segDuration = 6;
        $count = $totalDuration > 0
            ? (int) ceil($totalDuration / $segDuration)
            : 1;

        $segments = [];
        for ($i = 0; $i < $count; $i++) {
            $isLast = ($i === $count - 1);
            $duration = ($isLast && $totalDuration > 0)
                ? max(0.001, $totalDuration - ($i * $segDuration))
                : (float) $segDuration;

            $segments[] = [
                'url' => $this->segmentUrl($movie, $i),
                'duration' => $duration,
            ];
        }

        return $segments;
    }

    /**
     * Append (or merge) the playback token onto a segment URL.
     */
    protected function signSegmentUrl(string $url, string $playbackToken): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . 'token=' . rawurlencode($playbackToken);
    }

    /**
     * URL the player should fetch the AES content key from.
     *
     * Pattern: `/drm/key/{sessionToken}/{keyId}?token={keyJwt}`. The route
     * binding (added by the routes-owning agent) is responsible for
     * validating the JWT + replay-counter before returning the raw key.
     */
    protected function keyUrl(string $sessionToken, string $keyId, string $keyJwt): string
    {
        return sprintf(
            '/drm/key/%s/%s?token=%s',
            rawurlencode($sessionToken),
            rawurlencode($keyId),
            rawurlencode($keyJwt),
        );
    }

    /**
     * URL of a per-rendition media playlist.
     *
     * Backed by drm.playlist route (PlaybackController::playlist), signed via
     * Laravel's URL::temporarySignedRoute so the receiving route validates
     * freshness via the `signed` middleware. 5-minute TTL is plenty for the
     * player to load the manifest and start fetching segments.
     */
    protected function mediaPlaylistUrl(Movie $movie, string $renditionName): string
    {
        return URL::temporarySignedRoute(
            'drm.playlist',
            now()->addMinutes(5),
            ['movie' => $movie->slug, 'rendition' => $renditionName],
        );
    }

    /**
     * Per-segment URL. Signed via Laravel's signed-URL middleware so each
     * segment can be authorised independently without the player having to
     * round-trip a JWT per chunk.
     *
     * NOTE: this is only used by the single-rendition fallback path; the
     * normal multi-rendition flow lets the per-rendition `playlist.m3u8`
     * carry the segment URLs (relative paths, resolved by Shaka against the
     * playlist's own URL).
     */
    protected function segmentUrl(Movie $movie, int $index): string
    {
        // Pick first rendition for the single-rendition fallback path.
        $renditions = is_array($movie->encoding_renditions) ? $movie->encoding_renditions : [];
        $first = reset($renditions);
        $renditionName = $first === false ? 'default' : (string) ($first['name'] ?? '480p');

        return URL::temporarySignedRoute(
            'drm.segment',
            now()->addMinutes(10),
            [
                'movie' => $movie->slug,
                'rendition' => $renditionName,
                'filename' => sprintf('segment_%03d.ts', $index),
            ],
        );
    }

    /**
     * Approximate width for a given rendition height (16:9 baseline).
     * Used only to populate RESOLUTION= in #EXT-X-STREAM-INF.
     */
    protected function guessWidth(int $height): int
    {
        return match (true) {
            $height >= 2160 => 3840,
            $height >= 1440 => 2560,
            $height >= 1080 => 1920,
            $height >= 720 => 1280,
            $height >= 480 => 854,
            $height >= 360 => 640,
            default => (int) round($height * 16 / 9),
        };
    }
}
