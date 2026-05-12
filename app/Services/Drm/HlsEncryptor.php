<?php

declare(strict_types=1);

namespace App\Services\Drm;

use Symfony\Component\Process\Process;

/**
 * Re-packages an existing HLS playlist into an AES-128-encrypted variant.
 *
 * Workflow per call:
 *   1. Write the raw 16-byte content key to `<hlsDir>/key.bin`.
 *   2. Write FFmpeg's keyinfo file (3 lines: client URL, local key path,
 *      hex IV) to `<hlsDir>/enc.keyinfo`.
 *   3. Invoke FFmpeg to read the input playlist and emit `encrypted.m3u8`
 *      (plus encrypted .ts segments) alongside it.
 *
 * Returns the absolute path to the encrypted manifest. Callers are
 * responsible for uploading the resulting artifacts to the CDN disk and
 * cleaning up `key.bin` / `enc.keyinfo` (those should NEVER be exposed
 * publicly — only the manifest, the segments, and the dynamic key URL).
 *
 * NOTE: the source playlist (`playlist.m3u8`) and its segments must
 * already exist in `$hlsDir`. This service does not transcode — that's
 * the encoding pipeline's job.
 */
class HlsEncryptor
{
    /**
     * @param  string  $hlsDir       Absolute directory containing playlist.m3u8.
     * @param  string  $contentKey   Raw 16-byte AES-128 key.
     * @param  string  $keyUrl       URL the player should fetch the key from
     *                                (typically a signed `/drm/key/...` route).
     * @return string                 Absolute path to encrypted.m3u8.
     */
    public function encryptSegments(string $hlsDir, string $contentKey, string $keyUrl): string
    {
        if (! is_dir($hlsDir)) {
            throw new \RuntimeException("HLS directory does not exist: {$hlsDir}");
        }

        if (strlen($contentKey) !== 16) {
            throw new \InvalidArgumentException(
                'Content key must be exactly 16 raw bytes (AES-128); got ' . strlen($contentKey)
            );
        }

        $sourceManifest = $hlsDir . DIRECTORY_SEPARATOR . 'playlist.m3u8';
        if (! is_file($sourceManifest)) {
            throw new \RuntimeException("Source playlist not found: {$sourceManifest}");
        }

        $keyBinPath = $hlsDir . DIRECTORY_SEPARATOR . 'key.bin';
        $keyInfoPath = $hlsDir . DIRECTORY_SEPARATOR . 'enc.keyinfo';
        $outManifest = $hlsDir . DIRECTORY_SEPARATOR . 'encrypted.m3u8';

        // 1. Write raw key bytes (FFmpeg reads exactly 16 bytes for AES-128).
        if (file_put_contents($keyBinPath, $contentKey) === false) {
            throw new \RuntimeException("Failed to write key file: {$keyBinPath}");
        }
        @chmod($keyBinPath, 0600);

        // 2. Write keyinfo. FFmpeg expects:
        //    line 1: URL the *player* should use to fetch the key
        //    line 2: local path FFmpeg reads the raw key from
        //    line 3 (optional): IV in hex (16 bytes / 32 hex chars)
        $iv = bin2hex(random_bytes(16));
        $keyInfoContents = $keyUrl . "\n" . $keyBinPath . "\n" . $iv . "\n";
        if (file_put_contents($keyInfoPath, $keyInfoContents) === false) {
            throw new \RuntimeException("Failed to write keyinfo: {$keyInfoPath}");
        }
        @chmod($keyInfoPath, 0600);

        // 3. Run FFmpeg.
        $ffmpeg = (string) env('FFMPEG_BINARY', 'ffmpeg');

        $process = new Process([
            $ffmpeg,
            '-y',
            '-allowed_extensions', 'ALL',
            '-i', $sourceManifest,
            '-c', 'copy',
            '-hls_time', '6',
            '-hls_key_info_file', $keyInfoPath,
            '-hls_playlist_type', 'vod',
            $outManifest,
        ]);
        $process->setTimeout(1800); // 30 min ceiling for long features.
        $process->setWorkingDirectory($hlsDir);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(
                'FFmpeg HLS encryption failed: ' . $process->getErrorOutput()
            );
        }

        if (! is_file($outManifest)) {
            throw new \RuntimeException(
                "Encrypted manifest was not produced at: {$outManifest}"
            );
        }

        return $outManifest;
    }
}
