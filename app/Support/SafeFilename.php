<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Generate filesystem-safe upload filenames.
 *
 * The user-supplied name (`$file->getClientOriginalName()`) is hostile input:
 * it can contain path traversal sequences (`../../etc/passwd`), null bytes,
 * RTL overrides, control chars, double extensions (`evil.php.jpg`), or names
 * long enough to blow past PATH_MAX. NEVER persist it to disk.
 *
 * `SafeFilename::generate()` produces `{prefix}{uuid}.{ext}` where:
 *   - `prefix` is an optional, sanitised, alphanumeric-only tag (e.g.
 *     `poster_`, `master_`).
 *   - `uuid` is a v4 UUID (collision-free, opaque, unguessable).
 *   - `ext` is the extension extracted from the ORIGINAL name with every
 *     non-alphanumeric byte stripped, lower-cased, and clamped to 8 chars.
 *
 * The original name is referenced ONLY to derive the extension — it never
 * appears in the result, so XSS-via-Content-Disposition or extension-spoof
 * attacks are eliminated by construction.
 *
 * @see docs/security/file-uploads.md
 */
final class SafeFilename
{
    /** Hard cap on extension length (everything past this is dropped). */
    private const MAX_EXT_LENGTH = 8;

    /** Hard cap on prefix length (after sanitisation). */
    private const MAX_PREFIX_LENGTH = 32;

    /**
     * Build a filesystem-safe filename of the form `{prefix}{uuid}.{ext}`.
     *
     * @param  string  $original  Raw client filename (used only to derive the extension).
     * @param  string  $prefix  Optional human-readable tag, sanitised to [A-Za-z0-9_].
     */
    public static function generate(string $original, string $prefix = ''): string
    {
        $ext = self::sanitiseExtension($original);
        $tag = self::sanitisePrefix($prefix);

        $name = $tag.Str::uuid()->toString();

        return $ext === '' ? $name : $name.'.'.$ext;
    }

    /**
     * Derive a safe extension from the original filename.
     *
     * Returns lower-case, alphanumeric-only, max 8 chars. Returns '' when
     * the input has no recognisable extension (caller decides whether to
     * fall back to a default like 'bin').
     */
    public static function sanitiseExtension(string $original): string
    {
        $ext = pathinfo($original, PATHINFO_EXTENSION);
        if ($ext === '' || $ext === null) {
            return '';
        }

        // Strip everything except a-z, 0-9. Drops null bytes, dots,
        // slashes, RTL overrides, etc. by construction.
        $ext = strtolower($ext);
        $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?? '';

        if ($ext === '') {
            return '';
        }

        return substr($ext, 0, self::MAX_EXT_LENGTH);
    }

    /**
     * Sanitise a caller-supplied prefix tag.
     *
     * Allows [A-Za-z0-9_], lower-cases, clamps length, then appends a single
     * underscore separator (only if non-empty) so the caller can pass either
     * `'poster'` or `'poster_'` and get the same result.
     */
    public static function sanitisePrefix(string $prefix): string
    {
        if ($prefix === '') {
            return '';
        }

        $cleaned = preg_replace('/[^A-Za-z0-9_]/', '', $prefix) ?? '';
        $cleaned = strtolower($cleaned);
        $cleaned = substr($cleaned, 0, self::MAX_PREFIX_LENGTH);

        if ($cleaned === '') {
            return '';
        }

        return rtrim($cleaned, '_').'_';
    }

    /**
     * Reject filenames that contain path-traversal sequences or directory
     * separators. Used by callers that — for legacy reasons — must echo a
     * client-provided filename back somewhere.
     *
     * Returns true when the name is structurally safe, false when it is
     * NOT (and so should be rejected before any disk operation).
     */
    public static function isSafePath(string $name): bool
    {
        if ($name === '' || strlen($name) > 255) {
            return false;
        }

        // Reject NUL bytes outright — they truncate paths in C-level APIs.
        if (str_contains($name, "\0")) {
            return false;
        }

        // Reject any directory separator (Windows + POSIX) and the parent-
        // directory marker `..`.
        if (preg_match('#(\.\.|/|\\\\)#', $name) === 1) {
            return false;
        }

        return true;
    }
}
