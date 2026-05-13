<?php

declare(strict_types=1);

namespace App\Services\Security;

use Illuminate\Support\Facades\Log;

/**
 * Optional anti-malware scan for accepted uploads.
 *
 * Talks to a ClamAV daemon (clamd) over TCP using the INSTREAM protocol
 * documented at https://docs.clamav.net/manual/Usage/Configuration.html
 *
 * Configuration (env):
 *   CLAMAV_HOST     — clamd host (e.g. `clamav` in Docker network)
 *   CLAMAV_PORT     — clamd port (default 3310)
 *   CLAMAV_TIMEOUT  — TCP timeout seconds (default 10)
 *
 * Behaviour:
 *   - When CLAMAV_HOST is empty: log a single warning per process and
 *     return TRUE (fail-open). Dev / CI / first deploy don't have clamd
 *     wired yet and we don't want to block uploads on missing infra.
 *   - When CLAMAV_HOST is set: opens a TCP socket, streams the file via
 *     the INSTREAM command, parses the response. Returns FALSE if clamd
 *     reports any signature (`FOUND`) — caller MUST refuse the upload
 *     and delete the temp file. Returns TRUE on `OK`. On socket / proto
 *     errors the call returns FALSE (fail-closed once clamd is enabled).
 *
 * Why fail-open by default but fail-closed once configured:
 *   The whole point of opt-in scanning is that operators can roll it out
 *   without re-deploying. A misconfigured CLAMAV_HOST that breaks every
 *   upload would push operators to disable scanning entirely; we'd rather
 *   catch the misconfiguration via the security log channel and block
 *   uploads only when scanning is genuinely engaged.
 *
 * @see docs/security/file-uploads.md
 */
final class VirusScanner
{
    /** Marker so we only log the "fail-open" warning once per process. */
    private static bool $warnedNoHost = false;

    /**
     * Scan a file at $path for malware.
     *
     * @return bool TRUE = clean (or scanning disabled), FALSE = infected / scan failed.
     */
    public function scan(string $path): bool
    {
        $host = (string) env('CLAMAV_HOST', '');
        if ($host === '') {
            if (! self::$warnedNoHost) {
                Log::channel(config('logging.default') ?? 'stack')->warning(
                    'VirusScanner: CLAMAV_HOST not configured — scanning disabled, accepting upload as clean.'
                );
                self::$warnedNoHost = true;
            }

            return true;
        }

        if (! is_file($path) || ! is_readable($path)) {
            Log::warning('VirusScanner: file unreadable, refusing.', ['path' => $path]);

            return false;
        }

        $port = (int) env('CLAMAV_PORT', 3310);
        $timeout = (int) env('CLAMAV_TIMEOUT', 10);

        $errno = 0;
        $errstr = '';
        $sock = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($sock === false) {
            Log::error('VirusScanner: clamd connection failed.', [
                'host' => $host,
                'port' => $port,
                'errno' => $errno,
                'errstr' => $errstr,
            ]);

            return false; // fail-closed once configured
        }

        try {
            stream_set_timeout($sock, $timeout);

            // INSTREAM: server expects "zINSTREAM\0" then a stream of
            // length-prefixed chunks, terminated by a zero-length chunk.
            fwrite($sock, "zINSTREAM\0");

            $fh = @fopen($path, 'rb');
            if ($fh === false) {
                Log::warning('VirusScanner: could not open source file for streaming.', ['path' => $path]);

                return false;
            }

            try {
                while (! feof($fh)) {
                    $chunk = (string) fread($fh, 8192);
                    if ($chunk === '') {
                        break;
                    }
                    fwrite($sock, pack('N', strlen($chunk)).$chunk);
                }

                // Zero-length chunk terminates the stream.
                fwrite($sock, pack('N', 0));
            } finally {
                fclose($fh);
            }

            $reply = trim((string) stream_get_contents($sock));
        } finally {
            fclose($sock);
        }

        // Reply formats:
        //   "stream: OK"
        //   "stream: <SignatureName> FOUND"
        //   "stream: <error> ERROR"
        if ($reply === '') {
            Log::warning('VirusScanner: empty reply from clamd.');

            return false;
        }

        if (str_ends_with($reply, ' FOUND')) {
            Log::channel(config('logging.default') ?? 'stack')->warning(
                'VirusScanner: malware detected, rejecting upload.',
                ['path' => $path, 'reply' => $reply]
            );

            return false;
        }

        if (str_ends_with($reply, ' ERROR')) {
            Log::error('VirusScanner: clamd returned ERROR.', ['reply' => $reply]);

            return false;
        }

        // Anything else (typically "stream: OK") is treated as clean.
        return true;
    }
}
