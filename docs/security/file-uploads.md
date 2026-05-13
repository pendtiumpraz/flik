# File Upload Security

Hardening notes for every multipart upload endpoint in FLiK. The pipeline is
designed so every upload — whether it comes from the legacy `AdminController`
movie form, the chunked master-video uploader, or the subtitle manager —
goes through the SAME defence-in-depth checks. Adding a new endpoint that
skips them is a regression: review with the Security WG before merging.

Status: Done (initial pass — peer review item #11).

## Threat model

| Threat | Mitigation |
|--------|------------|
| Webshell upload (`pwn.php` renamed `pwn.jpg`) | Magic-byte sniff via `finfo` + extension/MIME consistency check + image re-encode |
| Path traversal in stored filename (`../../etc/passwd`) | `SafeFilename::isSafePath()` rejects, then `SafeFilename::generate()` produces a UUID-based name — original is never persisted |
| Polyglot file (valid JPEG containing PHP / JS payload in EXIF) | GD/Imagick re-encode strips ALL metadata (EXIF, XMP, IPTC, ICC) and produces a clean copy |
| SVG XSS | `image/svg+xml` is NOT in the allowlist — rejected at MIME check |
| `<script>` in subtitle cue | `FileUploadValidator::validateSubtitle()` scans for `<script` tags and `javascript:` URIs |
| Massive upload exhausting disk | Per-method size cap (5 MB default for images, 5 GB default for videos, 1 MB for subtitles) |
| Direct hot-link of private content | Files on `private` disk served via `URL::temporarySignedRoute()` only |
| Malware distribution | Optional ClamAV scan via `VirusScanner::scan()` (engaged when `CLAMAV_HOST` is set) |

## Components

| File | Responsibility |
|------|----------------|
| `app/Services/Security/FileUploadValidator.php` | One class, three methods (`validateImage` / `validateVideo` / `validateSubtitle`) — runs every check listed above and returns a stable result envelope |
| `app/Services/Security/VirusScanner.php` | Optional ClamAV INSTREAM client — fail-open when unconfigured, fail-closed when engaged |
| `app/Support/SafeFilename.php` | UUID-based filename generation; client name is referenced ONLY for the extension |
| `app/Support/MagicBytes.php` | Magic-byte signatures + helpers for sniffing image/video MIMEs without trusting the client |
| `app/Http/Controllers/MediaController.php` | Signed-URL gated accessor for any image stored on the `private` disk |

## Allowed types

### Images (`validateImage`, default cap 5 MB)
- `image/jpeg` (`.jpg`, `.jpeg`)
- `image/png` (`.png`)
- `image/webp` (`.webp`)

SVG is intentionally NOT allowed — it's an XML format and an XSS vector by design.

### Videos (`validateVideo`, default cap 5 GB)
- `video/mp4` (`.mp4`, `.m4v`)
- `video/webm` (`.webm`)
- `video/quicktime` (`.mov`, `.qt`)
- `video/x-matroska` (`.mkv`)

### Subtitles (`validateSubtitle`, hard cap 1 MB)
- `text/vtt` (`.vtt`)
- `text/plain` (`.vtt`, `.srt`, `.txt`)
- `application/x-subrip` (`.srt`)

Subtitle cue text is additionally scanned for `<script>` tags and `javascript:` URIs.

## How to call the validator (canonical pattern)

```php
public function storeMovie(
    Request $request,
    FileUploadValidator $uploads,
    VirusScanner $scanner,
) {
    if ($request->hasFile('video_file')) {
        $upload = $request->file('video_file');

        $check = $uploads->validateVideo($upload);
        if (! $check['ok']) {
            return back()->withErrors(['video_file' => $check['errors']])->withInput();
        }

        if (! $scanner->scan($check['safe_path'] ?? $upload->getRealPath())) {
            return back()->withErrors(['video_file' => 'File ditolak oleh anti-malware scanner.'])->withInput();
        }

        $safeName = SafeFilename::generate($upload->getClientOriginalName(), 'movie');
        $path = $upload->storeAs('videos', $safeName, 'public');
        // ... persist $path on the Movie row
    }
}
```

The result envelope is always:

```php
[
  'ok'        => bool,
  'errors'    => string[],
  'mime'      => ?string,   // detected via finfo
  'extension' => ?string,   // sanitised, [a-z0-9]+
  'safe_path' => ?string,   // re-encoded copy (images) or original tmp path (others)
]
```

When `validateImage` succeeds, `safe_path` points at a clean re-encoded copy with EXIF/XMP/IPTC stripped. Persist `safe_path`, NOT the original temp file.

## Storage location

- **Public disk** (`storage/app/public/...` → symlinked into `public/storage/`) — legacy. New uploads should go here ONLY when the file is meant to be cacheable + linkable from any user context (e.g. movie posters that are already public information).
- **Private disk** (`storage/app/private/...`) — NEVER symlinked into `public/`. Reachable only via signed routes. Use this for anything user-specific or scoped (avatars, GDPR exports, drafts).
- **Master files** (`storage/app/.../movies/{id}/master_*.mp4`) — original mezzanine uploads consumed by the transcoding pipeline. These should ALWAYS be on the private disk or an external bucket; they are NEVER served directly to end users (the transcoded HLS variants are).

## Signed-URL accessors (private disk)

The `Movie` model exposes `poster_url` / `backdrop_url` / `slider_url` accessors that automatically branch:

| Stored path shape | Resolved URL |
|---|---|
| `null` / empty | `/images/no-poster.png` (poster) or chained fallback |
| `http://...` / `https://...` | Returned verbatim (CDN / TMDB / external) |
| `private/...` | `URL::temporarySignedRoute('media.poster', now()->addHours(2), ['movie' => $id])` |
| Anything else | `asset('storage/'.$path)` — legacy public disk |

Routes live in `routes/web.php`:
- `media.poster`   — `GET /media/poster/{movie}`
- `media.backdrop` — `GET /media/backdrop/{movie}`
- `media.slider`   — `GET /media/slider/{movie}`

All three are wrapped in the `signed` middleware. Cache-Control on the response is `private, max-age=7200` to match the 2-hour signed-URL TTL — there's no point letting browsers cache past the URL expiry.

## ClamAV (`VirusScanner`)

| Behaviour | When |
|---|---|
| Fail-open (warn once, return true) | `CLAMAV_HOST` env var is empty (dev / CI / first deploy) |
| Fail-closed (return false on socket / proto error) | `CLAMAV_HOST` is set but unreachable |
| Reject (return false) | clamd reply ends in ` FOUND` |

Configuration:
```env
CLAMAV_HOST=clamav        # leave blank to disable
CLAMAV_PORT=3310          # default
CLAMAV_TIMEOUT=10         # seconds
```

## Operator runbook

- **Adding a new upload endpoint**: inject `FileUploadValidator` + (optionally) `VirusScanner`, call the matching `validate*` method, short-circuit on `!ok`, persist `safe_path` (images) or the original temp path (others) with a `SafeFilename::generate` filename.
- **Adding a new MIME type**: edit the `ALLOWED_*_MIMES` and `*_EXTENSIONS_BY_MIME` constants in `FileUploadValidator`; add the magic-byte signature to `MagicBytes` if it's a binary format. Update this doc.
- **A user reports their upload is rejected**: check `storage/logs/laravel.log` for `FileUploadValidator:` warnings and the `security` channel for `VirusScanner:` events.

## Audit checklist

Run before every release. Tick or annotate.

- [ ] No new call to `$request->file(...)->store(...)` skips `FileUploadValidator`. (`grep -rn "->store('" app/Http/Controllers`)
- [ ] No new call to `getClientOriginalName()` writes to disk. (`grep -rn "getClientOriginalName" app/Http/Controllers`)
- [ ] Every signed media accessor returns 403 on a tampered URL (`signed` middleware on each route).
- [ ] `CLAMAV_HOST` is set in production env.
- [ ] GD and/or Imagick is enabled on production PHP. (`php -m | grep -iE "gd|imagick"`)
