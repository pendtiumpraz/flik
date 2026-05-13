<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Magic-byte signatures for the file types FLiK accepts on upload.
 *
 * Browsers and `mime_content_type()` both trust the file extension first;
 * a malicious PHP file renamed to `pwn.png` will report `image/png` from a
 * fast-path MIME check. The magic-byte fingerprint at the START of the file
 * is the only authoritative MIME signal for binary formats — if the bytes
 * don't match, the file IS NOT what it claims to be, full stop.
 *
 * Pair these constants with `\App\Services\Security\FileUploadValidator`,
 * which:
 *   1. Reads the first ~16 bytes of the uploaded temp file.
 *   2. Compares them with `matches($bytes, $type)`.
 *   3. Refuses the upload BEFORE any path is touched on disk.
 *
 * The signatures themselves are taken from the official format specs
 * (W3C / IETF / ISO/IEC 14496-12) — refs in the per-constant docblocks.
 *
 * @see docs/security/file-uploads.md
 */
final class MagicBytes
{
    /** JPEG / JFIF / EXIF — all start with `FF D8 FF`. */
    public const JPEG = "\xFF\xD8\xFF";

    /** PNG — fixed 8-byte preamble per RFC 2083 §3.1. */
    public const PNG = "\x89PNG\r\n\x1a\n";

    /**
     * GIF — accepted only for video-thumbnail import, not first-class image.
     * Both 87a and 89a variants share the leading `GIF8`.
     */
    public const GIF = 'GIF8';

    /**
     * WebP — `RIFF` + 4-byte little-endian length + `WEBP` (bytes 0-3 + 8-11).
     * Use `matches()` with this constant; the 4 length bytes between are
     * skipped automatically (`?` placeholder).
     */
    public const WEBP = 'RIFF????WEBP';

    /**
     * MP4 / QuickTime / 3GP — ISO BMFF "ftyp" box at offset 4.
     * The first 4 bytes are the box length, then `ftyp`, then the brand
     * (e.g. `mp42`, `qt  `, `isom`). We only check up to `ftyp`.
     */
    public const MP4 = '????ftyp';

    /** WebM / Matroska — EBML header `1A 45 DF A3`. */
    public const WEBM = "\x1A\x45\xDF\xA3";

    /** Matroska container shares the WebM EBML header. */
    public const MATROSKA = "\x1A\x45\xDF\xA3";

    /**
     * WebVTT — header line `WEBVTT` (optional BOM stripped by caller).
     * Not strictly a "magic byte" sequence — VTT is text — but we
     * fingerprint the required first token the same way.
     */
    public const WEBVTT = 'WEBVTT';

    /**
     * SubRip — first cue starts with the index `1` followed by CR/LF and a
     * timestamp. We just look for the digit at offset 0; full validation is
     * out of scope (the parser will reject malformed cues downstream).
     */
    public const SRT_LEADING_DIGIT = '1';

    /**
     * Compare $bytes against a signature. Use `?` in the signature to skip
     * a single byte (e.g. WebP / MP4 length-prefixed boxes).
     *
     * @param  string  $bytes  Raw bytes read from the start of the file.
     * @param  string  $signature  One of the constants above (or a custom pattern).
     */
    public static function matches(string $bytes, string $signature): bool
    {
        $sigLen = strlen($signature);

        if (strlen($bytes) < $sigLen) {
            return false;
        }

        for ($i = 0; $i < $sigLen; $i++) {
            if ($signature[$i] === '?') {
                continue;
            }
            if ($bytes[$i] !== $signature[$i]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve the canonical MIME for an uploaded file by sniffing magic
     * bytes. Returns null when no known signature matches — callers
     * should treat that as a hard rejection, NOT fall back to the
     * client-supplied MIME header.
     *
     * Reads only the first 16 bytes which is enough for every signature
     * defined here.
     */
    public static function detectImageMime(string $filePath): ?string
    {
        $bytes = self::head($filePath, 16);
        if ($bytes === null) {
            return null;
        }

        return match (true) {
            self::matches($bytes, self::JPEG) => 'image/jpeg',
            self::matches($bytes, self::PNG) => 'image/png',
            self::matches($bytes, self::WEBP) => 'image/webp',
            self::matches($bytes, self::GIF) => 'image/gif',
            default => null,
        };
    }

    /**
     * Same as detectImageMime() but for video formats.
     */
    public static function detectVideoMime(string $filePath): ?string
    {
        $bytes = self::head($filePath, 16);
        if ($bytes === null) {
            return null;
        }

        return match (true) {
            self::matches($bytes, self::MP4) => 'video/mp4', // also matches QT — caller refines via extension
            self::matches($bytes, self::WEBM) => 'video/webm',
            self::matches($bytes, self::MATROSKA) => 'video/x-matroska',
            default => null,
        };
    }

    /**
     * Read up to $length bytes from the start of $path. Returns null
     * if the file is unreadable or empty.
     */
    public static function head(string $path, int $length = 16): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return null;
        }

        try {
            $bytes = (string) fread($fh, $length);
        } finally {
            fclose($fh);
        }

        return $bytes === '' ? null : $bytes;
    }
}
