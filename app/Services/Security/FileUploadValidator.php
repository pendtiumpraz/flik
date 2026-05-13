<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Services\Audit\AuditLogger;
use App\Support\MagicBytes;
use App\Support\SafeFilename;
use App\Support\SecurityEvents;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Centralised, defence-in-depth validator for everything FLiK accepts on
 * upload.
 *
 * Each public method returns the SAME shape:
 *
 *     [
 *       'ok'         => bool,
 *       'errors'     => string[],          // empty when ok=true
 *       'mime'       => string|null,       // detected from magic bytes
 *       'extension'  => string|null,       // sanitised (a-z0-9 only)
 *       'safe_path'  => string|null,       // path to re-encoded copy (images) or original (others)
 *     ]
 *
 * Callers MUST short-circuit on `!ok` and ignore every other field. The
 * shape is stable so controllers can `return response()->json($result, 422)`
 * straight through to the API surface.
 *
 * Why every check exists:
 *
 *  1. **Size cap (cheapest, run first)** — stops a 50 GB tarball from ever
 *     touching `finfo`. Configurable per-call.
 *  2. **Filename safety** — rejects path traversal (`../`), embedded NULs,
 *     directory separators. Run BEFORE any disk operation.
 *  3. **Magic-byte sniff** — the only authoritative source of truth for
 *     binary MIME. The browser-supplied `Content-Type` is ignored.
 *  4. **Extension/MIME consistency** — `pwn.php` re-saved as a JPEG with a
 *     fake `FF D8 FF` header is still hostile if the webserver routes by
 *     extension. We require both axes to agree.
 *  5. **Re-encode (images only)** — a JPEG with embedded EXIF/XMP that
 *     contains `<?php ?>` payloads is a real attack against poorly-
 *     configured webservers. Re-encoding through GD/Imagick produces a
 *     clean copy with metadata stripped — and conveniently rejects every
 *     malformed file the parser can't handle.
 *  6. **Subtitle text scan** — VTT/SRT are text formats and the front-end
 *     renders the cue payload directly into the DOM. We block any cue body
 *     that contains `<script>` or `javascript:` URIs before persistence.
 *
 * @see docs/security/file-uploads.md
 */
final class FileUploadValidator
{
    /**
     * Optional audit logger. When present, every rejected upload writes a
     * `security.file_upload_rejected` row to `audit_logs` so the security
     * working group can spot scanning campaigns or repeated abuse from a
     * single account. Nullable so unit tests can construct the validator
     * without booting the full container.
     */
    public function __construct(private ?AuditLogger $audit = null) {}

    /**
     * Image MIMEs we accept. SVG is intentionally excluded — it's an XML
     * format and an XSS vector by design.
     */
    private const ALLOWED_IMAGE_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    /**
     * Allowed extensions, keyed by MIME. The validator enforces that the
     * supplied file's extension appears in the list for its sniffed MIME.
     */
    private const IMAGE_EXTENSIONS_BY_MIME = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
    ];

    private const ALLOWED_VIDEO_MIMES = [
        'video/mp4',
        'video/webm',
        'video/quicktime',
        'video/x-matroska',
    ];

    private const VIDEO_EXTENSIONS_BY_MIME = [
        'video/mp4' => ['mp4', 'm4v'],
        'video/webm' => ['webm'],
        'video/quicktime' => ['mov', 'qt'],
        'video/x-matroska' => ['mkv'],
    ];

    private const ALLOWED_SUBTITLE_MIMES = [
        'text/vtt',
        'text/plain',
        'application/x-subrip',
    ];

    private const SUBTITLE_EXTENSIONS_BY_MIME = [
        'text/vtt' => ['vtt'],
        'text/plain' => ['vtt', 'srt', 'txt'],
        'application/x-subrip' => ['srt'],
    ];

    /** Hard cap on subtitle file size (1 MB is plenty for a 3-hour film). */
    private const MAX_SUBTITLE_BYTES = 1_048_576;

    /**
     * Validate an uploaded image. On success, ALSO re-encodes the file
     * through GD (or Imagick when available) into a sibling temp file with
     * EXIF/XMP/IPTC stripped — `safe_path` points at the cleaned copy.
     *
     * Callers SHOULD persist `safe_path`, NOT the original, to make sure
     * any embedded payload never reaches disk.
     *
     * @return array{ok:bool, errors:string[], mime:?string, extension:?string, safe_path:?string}
     */
    public function validateImage(UploadedFile $file, int $maxBytes = 5_000_000): array
    {
        $errors = [];

        // 1. Filename safety. Cheapest possible check, runs first.
        $original = (string) $file->getClientOriginalName();
        if (! SafeFilename::isSafePath($original)) {
            $errors[] = 'Filename contains illegal characters (path traversal, NUL, or separators).';
        }

        // 2. Size cap.
        $size = (int) $file->getSize();
        if ($size <= 0) {
            $errors[] = 'Uploaded file is empty.';
        } elseif ($size > $maxBytes) {
            $errors[] = sprintf('File too large (%d bytes, max %d).', $size, $maxBytes);
        }

        // If structural checks already failed, abort before touching disk.
        if (! empty($errors)) {
            return $this->fail($errors, kind: 'image', filename: $original, size: $size);
        }

        $realPath = (string) $file->getRealPath();

        // 3. Magic-byte sniff via finfo (more standards-compliant than our
        //    own MagicBytes::detectImageMime, which we use as a backstop).
        $mime = $this->finfoMime($realPath) ?? MagicBytes::detectImageMime($realPath);
        if ($mime === null) {
            return $this->fail(['Could not determine file type from contents.'], kind: 'image', filename: $original, size: $size);
        }

        if (! in_array($mime, self::ALLOWED_IMAGE_MIMES, true)) {
            return $this->fail([
                sprintf('Image MIME %s is not allowed. Accepted: %s.', $mime, implode(', ', self::ALLOWED_IMAGE_MIMES)),
            ], kind: 'image', filename: $original, size: $size, mime: $mime);
        }

        // 4. Extension/MIME consistency.
        $ext = SafeFilename::sanitiseExtension($original);
        $allowedExts = self::IMAGE_EXTENSIONS_BY_MIME[$mime] ?? [];
        if ($ext === '' || ! in_array($ext, $allowedExts, true)) {
            return $this->fail([
                sprintf('Extension ".%s" does not match detected MIME %s (expected one of: %s).', $ext, $mime, implode(', ', $allowedExts)),
            ], kind: 'image', filename: $original, size: $size, mime: $mime);
        }

        // 5. Re-encode through GD/Imagick to strip metadata and reject
        //    malformed files. The re-encoded copy is what callers should
        //    persist.
        $safePath = $this->reencodeImage($realPath, $mime);
        if ($safePath === null) {
            return $this->fail(['Image is malformed or could not be re-encoded.'], kind: 'image', filename: $original, size: $size, mime: $mime);
        }

        return [
            'ok' => true,
            'errors' => [],
            'mime' => $mime,
            'extension' => $ext,
            'safe_path' => $safePath,
        ];
    }

    /**
     * Validate an uploaded video. Does NOT re-encode (transcoding is the
     * job of the TranscodingPipeline) — `safe_path` is the original temp
     * path, returned verbatim for caller convenience.
     *
     * @return array{ok:bool, errors:string[], mime:?string, extension:?string, safe_path:?string}
     */
    public function validateVideo(UploadedFile $file, int $maxBytes = 5_000_000_000): array
    {
        $errors = [];

        $original = (string) $file->getClientOriginalName();
        if (! SafeFilename::isSafePath($original)) {
            $errors[] = 'Filename contains illegal characters (path traversal, NUL, or separators).';
        }

        $size = (int) $file->getSize();
        if ($size <= 0) {
            $errors[] = 'Uploaded file is empty.';
        } elseif ($size > $maxBytes) {
            $errors[] = sprintf('File too large (%d bytes, max %d).', $size, $maxBytes);
        }

        if (! empty($errors)) {
            return $this->fail($errors, kind: 'video', filename: $original, size: $size);
        }

        $realPath = (string) $file->getRealPath();

        // finfo first (knows about ftyp brand variants), magic-byte fallback
        // for environments without fileinfo configured.
        $mime = $this->finfoMime($realPath) ?? MagicBytes::detectVideoMime($realPath);

        // QuickTime + MP4 share the `ftyp` box; finfo distinguishes via the
        // brand 4cc but our MagicBytes fallback returns 'video/mp4' for both.
        // When the extension says `.mov`, accept the upload as quicktime.
        $ext = SafeFilename::sanitiseExtension($original);
        if ($mime === 'video/mp4' && in_array($ext, ['mov', 'qt'], true)) {
            $mime = 'video/quicktime';
        }

        if ($mime === null) {
            return $this->fail(['Could not determine video type from contents.'], kind: 'video', filename: $original, size: $size);
        }

        if (! in_array($mime, self::ALLOWED_VIDEO_MIMES, true)) {
            return $this->fail([
                sprintf('Video MIME %s is not allowed. Accepted: %s.', $mime, implode(', ', self::ALLOWED_VIDEO_MIMES)),
            ], kind: 'video', filename: $original, size: $size, mime: $mime);
        }

        $allowedExts = self::VIDEO_EXTENSIONS_BY_MIME[$mime] ?? [];
        if ($ext === '' || ! in_array($ext, $allowedExts, true)) {
            return $this->fail([
                sprintf('Extension ".%s" does not match detected MIME %s (expected one of: %s).', $ext, $mime, implode(', ', $allowedExts)),
            ], kind: 'video', filename: $original, size: $size, mime: $mime);
        }

        return [
            'ok' => true,
            'errors' => [],
            'mime' => $mime,
            'extension' => $ext,
            'safe_path' => $realPath,
        ];
    }

    /**
     * Validate an uploaded subtitle (VTT / SRT / TXT).
     *
     * Subtitles are rendered into the DOM by the player, so the cue text
     * itself is an XSS surface — we block `<script>` tags and `javascript:`
     * URIs in the cue payload before the file ever reaches disk.
     *
     * @return array{ok:bool, errors:string[], mime:?string, extension:?string, safe_path:?string}
     */
    public function validateSubtitle(UploadedFile $file): array
    {
        $errors = [];

        $original = (string) $file->getClientOriginalName();
        if (! SafeFilename::isSafePath($original)) {
            $errors[] = 'Filename contains illegal characters (path traversal, NUL, or separators).';
        }

        $size = (int) $file->getSize();
        if ($size <= 0) {
            $errors[] = 'Subtitle file is empty.';
        } elseif ($size > self::MAX_SUBTITLE_BYTES) {
            $errors[] = sprintf('Subtitle too large (%d bytes, max %d).', $size, self::MAX_SUBTITLE_BYTES);
        }

        if (! empty($errors)) {
            return $this->fail($errors, kind: 'subtitle', filename: $original, size: $size);
        }

        $realPath = (string) $file->getRealPath();

        $mime = $this->finfoMime($realPath) ?? 'text/plain';
        if (! in_array($mime, self::ALLOWED_SUBTITLE_MIMES, true)) {
            return $this->fail([
                sprintf('Subtitle MIME %s is not allowed. Accepted: %s.', $mime, implode(', ', self::ALLOWED_SUBTITLE_MIMES)),
            ], kind: 'subtitle', filename: $original, size: $size, mime: $mime);
        }

        $ext = SafeFilename::sanitiseExtension($original);
        $allowedExts = self::SUBTITLE_EXTENSIONS_BY_MIME[$mime] ?? [];
        if ($ext === '' || ! in_array($ext, $allowedExts, true)) {
            return $this->fail([
                sprintf('Extension ".%s" does not match detected MIME %s (expected one of: %s).', $ext, $mime, implode(', ', $allowedExts)),
            ], kind: 'subtitle', filename: $original, size: $size, mime: $mime);
        }

        // Hostile content scan. We're reading at most 1 MB so this is cheap.
        $contents = (string) @file_get_contents($realPath);
        if ($contents === '') {
            return $this->fail(['Could not read subtitle contents for validation.'], kind: 'subtitle', filename: $original, size: $size, mime: $mime);
        }

        // Tolerate UTF-8 BOM, normalise newlines for the regex.
        $sample = ltrim($contents, "\xEF\xBB\xBF");

        if (preg_match('/<\s*script[\s>]/i', $sample) === 1) {
            return $this->fail(['Subtitle contains <script> tags — refusing.'], kind: 'subtitle', filename: $original, size: $size, mime: $mime, threat: 'script_tag');
        }
        if (stripos($sample, 'javascript:') !== false) {
            return $this->fail(['Subtitle contains javascript: URI — refusing.'], kind: 'subtitle', filename: $original, size: $size, mime: $mime, threat: 'javascript_uri');
        }

        return [
            'ok' => true,
            'errors' => [],
            'mime' => $mime,
            'extension' => $ext,
            'safe_path' => $realPath,
        ];
    }

    // ── Internals ─────────────────────────────────────────────────

    /**
     * Sniff MIME via fileinfo. Returns null when fileinfo is unavailable
     * or the file has no recognisable signature.
     */
    private function finfoMime(string $path): ?string
    {
        if (! function_exists('finfo_open')) {
            return null;
        }

        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }

        try {
            $mime = @finfo_file($finfo, $path);
        } finally {
            finfo_close($finfo);
        }

        return is_string($mime) && $mime !== '' ? $mime : null;
    }

    /**
     * Re-encode an image through GD (preferred) or Imagick (fallback) into
     * a sibling temp file with metadata stripped. Returns the new path on
     * success, null on failure (which the caller treats as a malformed
     * file and rejects).
     */
    private function reencodeImage(string $sourcePath, string $mime): ?string
    {
        try {
            if (extension_loaded('gd') && function_exists('imagecreatefromstring')) {
                return $this->reencodeViaGd($sourcePath, $mime);
            }

            if (extension_loaded('imagick')) {
                return $this->reencodeViaImagick($sourcePath, $mime);
            }
        } catch (\Throwable $e) {
            Log::warning('FileUploadValidator: re-encode failed', [
                'mime' => $mime,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        // Neither extension available — log a warning and pass the original
        // through. We still return $sourcePath so dev environments without
        // GD/Imagick keep working; production environments MUST have GD.
        Log::warning('FileUploadValidator: neither GD nor Imagick available; image not re-encoded', [
            'mime' => $mime,
        ]);

        return $sourcePath;
    }

    private function reencodeViaGd(string $sourcePath, string $mime): ?string
    {
        $bytes = @file_get_contents($sourcePath);
        if ($bytes === false || $bytes === '') {
            return null;
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return null;
        }

        $outPath = $sourcePath.'.clean';

        try {
            $ok = match ($mime) {
                'image/jpeg' => @imagejpeg($image, $outPath, 90),
                'image/png' => @imagepng($image, $outPath, 6),
                'image/webp' => function_exists('imagewebp') ? @imagewebp($image, $outPath, 90) : false,
                default => false,
            };
        } finally {
            imagedestroy($image);
        }

        return $ok ? $outPath : null;
    }

    private function reencodeViaImagick(string $sourcePath, string $mime): ?string
    {
        $img = new \Imagick;

        try {
            $img->readImage($sourcePath);
            $img->stripImage(); // drop EXIF / XMP / IPTC / colour profiles
            $img->setImageFormat(match ($mime) {
                'image/jpeg' => 'jpeg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => 'jpeg',
            });

            $outPath = $sourcePath.'.clean';
            $img->writeImage($outPath);

            return $outPath;
        } finally {
            $img->clear();
            $img->destroy();
        }
    }

    /**
     * Build the standard failure shape AND emit a {@see SecurityEvents::FILE_UPLOAD_REJECTED}
     * security audit so /admin/audit-logs can surface upload-vector probes.
     *
     * The audit row carries the kind/filename/size/MIME context so triage
     * can distinguish "user fat-fingered a .gif" from "attacker tried to
     * upload .php hidden as .jpg". `threat` is set when the rejection was
     * driven by a hostile-content check (script tag, javascript: URI, etc.)
     * rather than benign mismatches.
     *
     * Audit failures are swallowed — a logging glitch must NEVER cause a
     * file that failed validation to slip through (the caller already short-
     * circuits on `ok = false`).
     *
     * @param  string[]  $errors
     * @return array{ok:bool, errors:string[], mime:?string, extension:?string, safe_path:?string}
     */
    private function fail(
        array $errors,
        string $kind = 'unknown',
        ?string $filename = null,
        ?int $size = null,
        ?string $mime = null,
        ?string $threat = null,
    ): array {
        try {
            // Prefer the constructor-injected logger so tests can pass a
            // mock; fall back to container resolution for legacy callers
            // that built the validator with `new` before the ctor existed.
            $audit = $this->audit
                ?? (app()->bound(AuditLogger::class) ? app(AuditLogger::class) : null);

            if ($audit !== null && class_exists(SecurityEvents::class)) {
                $audit->security(
                    event: SecurityEvents::FILE_UPLOAD_REJECTED,
                    meta: array_filter([
                        'kind' => $kind,
                        'filename' => $filename !== null ? mb_substr($filename, 0, 200) : null,
                        'size' => $size,
                        'mime' => $mime,
                        'threat' => $threat,
                        'errors' => array_values($errors),
                    ], static fn ($v) => $v !== null),
                );
            }
        } catch (Throwable $e) {
            Log::warning('FileUploadValidator: audit write failed', [
                'kind' => $kind,
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'ok' => false,
            'errors' => array_values($errors),
            'mime' => null,
            'extension' => null,
            'safe_path' => null,
        ];
    }
}
