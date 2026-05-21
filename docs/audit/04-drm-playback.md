# AUDIT #4 — DRM / Playback Domain

**Scope**: HLS encryption, JWT DRM keys, geo blocking, forensic watermark, concurrent stream limiter, device fingerprint, Shaka Player + Video.js integration, transcoding pipeline, ABR ladder, CDN (Bunny / S3).

**Date**: 2026-05-20
**Branch**: `main` @ `1acadbd` (post sec-swarm)
**Mode**: read-only

---

## TL;DR

The domain is **wired end-to-end at the controller / route layer**, the player-side JS is real Shaka 4.x with heartbeat + fingerprint + auto-skip + X-Ray, and the watch view correctly branches `encoding_status === 'ready' && hls_manifest_path` → Shaka, else `video_path` → Video.js, else YouTube embed. JWT key gating, replay-binding (jti, kid, session_id), concurrent-stream limiter, audit logging, and admin upload/transcode trigger all exist and are reachable from real routes.

However, the **distribution pipeline does NOT work end-to-end**. Four call-site / signature mismatches between the queue jobs and the services they invoke will throw `BadMethodCallException` / `ArgumentCountError` the first time anyone hits "Start Transcode" in production. The manifest generator emits a structurally valid `m3u8`, but the segment URIs are stubs that don't resolve to any real route. The forensic watermarker is implemented but **never called from anywhere** in the pipeline. The `GeoBlock` middleware is registered as an alias but **never applied to any route**. Two important composer deps (`firebase/php-jwt`, `geoip2/geoip2`) are not in `composer.json`, so the code silently falls back to the in-house JWT/HTTP-API paths.

Severity headline: **Critical** for pipeline correctness (transcode → encrypt → upload chain is broken), **High** for the unmounted GeoBlock middleware and the missing segment route, **Medium** for the unused watermarker.

---

## 1. Inventory

### Services present (`app/Services/`)

| File | Status | Notes |
|---|---|---|
| `Drm/DrmKeyService.php` | OK | Generates random 16-byte AES key, stores via Eloquent `encrypted` cast, bumps `key_request_count`/`last_key_request_at` on each `getKey()`. |
| `Drm/DrmTokenService.php` | OK with caveat | HS256 JWT, two audiences (`playback`, `drm-key`). Uses `firebase/php-jwt` if present, else manual HMAC fallback. APP_KEY base64-decoded for full entropy. **`firebase/php-jwt` is NOT in `composer.json` — always runs the manual fallback today.** |
| `Drm/HlsEncryptor.php` | OK in isolation | `encryptSegments($hlsDir, $contentKey, $keyUrl): string` — re-packages `playlist.m3u8` → `encrypted.m3u8` via `ffmpeg -hls_key_info_file`. Writes `key.bin` 0600. |
| `Drm/PlaybackManifestGenerator.php` | OK with stub URLs | Emits master+media playlist with per-rendition + per-segment JWTs. **Segment URLs point at `/drm/segment/{movieId}/00000.ts` — no such route exists in `routes/web.php`. Same for `/drm/playlist/{token}/{movie}/{idx}.m3u8`.** |
| `Drm/DeviceFingerprinter.php` | OK | Canvas/WebGL/Audio/screen/timezone/lang → SHA-256. TOFU bind on first heartbeat. Ships its own client-side IIFE. |
| `Drm/ConcurrentStreamLimiter.php` | OK | DB-backed (no Redis dep). Caps to `subscription_plans.max_screens` via `User::currentPlan()`; defaults to 1. |
| `Drm/ForensicWatermarker.php` | UNUSED | `addBurnInWatermark()` implemented, `addInvisibleWatermark()` stubbed false. **Grep across `app/` finds zero callers** — neither the transcoding pipeline nor the encryption stage invokes it. Dead code as wired. |
| `Drm/DrmAuditEvent.php` | OK | Immutable VO with named constructors. Used indirectly via `AuditLogger::security(...)` in the controller. |
| `Transcoding/TranscodingPipeline.php` | **BROKEN call sites** | See §2.1 below. |
| `Transcoding/FfmpegTranscoder.php` | OK in isolation | `probe()`, `transcode(input, RenditionSpec, output)`, `extractKeyframe()`. Symfony Process, no shell escape bugs. |
| `Transcoding/AbrLadderBuilder.php` | OK | 360/480/720/1080/4K, never upscales, even-width snap, max_bitrate=1.5×, bufsize=2×. Returns `RenditionSpec[]`. |
| `Transcoding/HlsSegmenter.php` | OK in isolation | `segment(input, segmentDuration, outputDir): string[]`. Stream-copy, returns `.ts` paths. |
| `Transcoding/MediaInfo.php` | OK | `fromFfprobe(json)`, with parse helpers for `r_frame_rate` and `display_aspect_ratio`. |
| `Transcoding/RenditionSpec.php` | OK | Immutable VO: name/width/height/video_bitrate/audio_bitrate/max_bitrate/buffer_size/fps. |
| `Storage/BunnyStorageService.php` | OK with **missing methods used by job** | `put/putStream/delete/exists/signedUrl/publicUrl/listFiles`. **No `uploadDirectory()`, no `writeMasterManifest()`** — both called from `UploadToBunny` job. |
| `Storage/S3StorageService.php` | OK | Thin Flysystem wrapper. Adds `temporaryUrl()` and `presignedUploadUrl()` with adapter fallback. |
| `Geo/GeoIpResolver.php` | OK with caveat | MaxMind GeoLite2 mmdb + ip-api.com fallback, 24h cache, fail-open on private/loopback/unknown. **`geoip2/geoip2` is NOT in `composer.json` — MaxMind path is never taken; only the rate-limited public HTTP API works.** |
| `Http/Middleware/GeoBlock.php` | OK as code, **NEVER MOUNTED** | Returns HTTP 451 + audit. Alias registered in `Kernel.php:95` as `geoblock`. **Grep across `routes/` finds zero uses.** |

### Models + migrations

| Table | Migration | Status |
|---|---|---|
| `drm_sessions` | `2026_05_10_020003_create_drm_sessions_table.php` | OK. `content_key` BLOB + Eloquent `encrypted` cast. Unique `session_token(128)`. |
| `playback_concurrent_locks` | `2026_05_10_020004_create_playback_concurrent_locks_table.php` | OK. Indexed `(user_id, expires_at)`. |
| `encoding_jobs` | `2026_05_10_020002_create_encoding_jobs_table.php` | OK. Enum status (`queued`/`transcoding`/`encrypting`/`uploading`/`completed`/`failed`), `rendition_specs` and `output_paths` JSON, `progress_percent`. |
| `movies.encoding_status / encoding_renditions / master_file_path/disk / drm_strategy/drm_config / hls/dash_manifest_path / cdn_disk / geo_allow` | `2026_05_10_020001_extend_movies_for_distribution.php` | OK, idempotent. Index on `encoding_status`. |
| `movies.intro/outro/recap` columns | `2026_05_10_020005_extend_movies_with_intro_outro.php` | OK. `decimal(8,3)`. |

All three models (`DrmSession`, `PlaybackConcurrentLock`, `EncodingJob`) use `$guarded = ['*']` with `forceFill()` write paths — consistent with the 2026-05-13 mass-assignment audit comments.

### Jobs

| Class | Queue | Tries | Status |
|---|---|---|---|
| `Jobs/TranscodeMovie.php` | `transcoding` | 2 | Dispatches `EncryptHlsSegments` on success. Reuses queued/in-flight `EncodingJob` row to avoid orphans. |
| `Jobs/EncryptHlsSegments.php` | `transcoding` | 2 | **Calls non-existent `HlsEncryptor::encrypt(movie:, hlsDir:, renditionKey:)`** (§2.2). |
| `Jobs/UploadToBunny.php` | `transcoding` | 3 | **Calls non-existent `BunnyStorageService::uploadDirectory()` and probes optional `writeMasterManifest()`** (§2.3). Also stamps `movies.encoding_renditions` with `spec.height/spec.bitrate` keys that don't match `RenditionSpec` (`video_bitrate`, no `bitrate`). |

### Routes / controllers

| Route | File / Line | Notes |
|---|---|---|
| `GET /playback/{movie}/config` | `routes/web.php:1104` (auth group) | Mints DrmSession + JWT + fingerprint script bundle. |
| `GET /playback/{movie}/manifest.m3u8` | `routes/web.php:1105` (auth group) | Token-gated, never cached. |
| `POST /playback/{movie}/heartbeat` | `routes/web.php:1106` (auth group) | Validates fingerprint TOFU, bumps lock. |
| `GET /drm/key/{sessionToken}/{keyId}` | `routes/web.php:451` (TOP LEVEL — no auth group) | JWT-gated via `validateKeyRequestToken` + `session_id`/`kid` claim match. Geo-blocked separately. Returns raw 16 binary bytes. |
| `POST /admin/movies/{movie}/upload-master` | `routes/web.php:747` (admin can:movies.upload_master) | Chunked or single-shot upload, virus scan, mime sniff. |
| `POST /admin/movies/{movie}/start-transcode` | `routes/web.php:749` (admin) | Dispatches `TranscodeMovie::dispatch($movie->id)`. |
| `GET /admin/movies/{movie}/encoding-status` | `routes/web.php:751` (admin) | Polled by upload UI. |

### Player JS

| File | Status |
|---|---|
| `resources/js/player/flik-player.js` | OK. Real Shaka 4.x wrapper: `loadConfig()` → `shaka.Player.attach()` → `load(manifestUrl)` → `startHeartbeat()`. Network filter registered but currently a no-op (manifests already carry the JWT in the URL). |
| `resources/js/player/auto-skip.js` | OK. Reads `data-intro-start/end`, `data-outro-start`, `data-recap-end`; localStorage "always skip" preference; recap > intro > outro priority. |
| `resources/js/player/xray-overlay.js` | OK. Polls `GET /api/xray/{slug}?t={sec}` every 5 s, renders spatial hotspots + non-spatial chip strip + modal. AbortController on each cycle. |

All three are imported in `resources/js/app.js:12-14` and assigned to `window` globals so the Blade `x-data` can call `new window.FlikPlayer(...)`. Shaka is pulled from a CDN `<script defer>` in `resources/views/components/layout.blade.php:225`.

---

## 2. Critical findings (severity: Critical)

### 2.1 `TranscodingPipeline` calls `FfmpegTranscoder::transcode()` and `HlsSegmenter::segment()` with the wrong signatures

`TranscodingPipeline::run()` (lines 98-110 + 122-135) uses **named arguments** for a `RenditionSpec[]`-typed ladder element, but the concrete services accept positional args with different parameter ordering and types.

`TranscodingPipeline.php:98`:
```php
$this->ffmpeg->transcode(
    sourcePath: $masterPath,
    targetPath: $transcodedPath,
    rendition: $rendition,                  // array, not RenditionSpec
    onProgress: function (int $renditionPct) use (...) { ... },
);
```

Actual signature in `FfmpegTranscoder.php:87`:
```php
public function transcode(string $inputPath, RenditionSpec $spec, string $outputPath): bool
```

Mismatches:
- Param names: `sourcePath`/`targetPath`/`rendition`/`onProgress` vs `inputPath`/`spec`/`outputPath`. PHP 8 named-arg call against `inputPath` will throw `Error: Unknown named parameter $sourcePath`.
- Parameter order: output is 2nd in the call, 3rd in the implementation.
- `onProgress` param does not exist in the transcoder at all — there is no progress-streaming hook in the Symfony Process invocation, so the per-rendition progress bar will never increment.
- The pipeline passes `$rendition` as a raw `array` (it comes out of `AbrLadderBuilder::build()` which actually returns `RenditionSpec[]`, but the pipeline iterates with `array $rendition` and reads `$rendition['name']`, `$rendition['height']`). `AbrLadderBuilder` returns objects, not arrays — `$rendition['name']` on a `RenditionSpec` throws `Cannot use object of type RenditionSpec as array`.

The same shape of bug exists for segmentation. `TranscodingPipeline.php:125`:
```php
$manifestPath = $this->segmenter->segment(
    inputPath: $output['transcoded_path'],
    outputDir: $hlsDir,
    rendition: $output['spec'],
);
```

vs `HlsSegmenter.php:39`:
```php
public function segment(string $inputPath, int $segmentDuration, string $outputDir): array
```

Mismatches:
- Named args `inputPath`/`outputDir`/`rendition` vs `inputPath`/`segmentDuration`/`outputDir`.
- Missing required `$segmentDuration` (int seconds).
- `HlsSegmenter::segment()` returns `array<int,string>` of `.ts` paths, but the pipeline assigns to `$manifestPath` and later stores it under `manifest` and lets `UploadToBunny` `basename($output['manifest'] ?? 'index.m3u8')` on it. With the real return value, that becomes `basename(['/abs/segment_000.ts','/abs/segment_001.ts',...])` → fatal type error.

**Impact**: the first call into `TranscodingPipeline::run()` (i.e., any admin clicking "Start Transcode" or any `php artisan flik:transcode` invocation) throws within seconds and the `EncodingJob` lands in `failed`. The downstream `EncryptHlsSegments` and `UploadToBunny` jobs never even get dispatched (the chain at `TranscodeMovie.php:90` only fires after `$pipeline->run()` returns cleanly).

**Reproduce**: `php artisan flik:transcode <slug> --sync` against any movie with a valid `master_file_path`. Expected: completed `EncodingJob`. Observed (predicted): `Error: Unknown named parameter $sourcePath` and `EncodingJob.status='failed'`.

### 2.2 `EncryptHlsSegments` calls `HlsEncryptor::encrypt()` — method does not exist

`EncryptHlsSegments.php:84`:
```php
$result = $encryptor->encrypt(
    movie: $movie,
    hlsDir: $output['hls_dir'],
    renditionKey: $renditionKey,
);
```

`HlsEncryptor` only exposes `encryptSegments(string $hlsDir, string $contentKey, string $keyUrl): string`. There is no `encrypt()` method, the parameter names don't match, and there is no way for the job to derive the `contentKey` or `keyUrl` to pass (the job has no `DrmKeyService` or `DrmTokenService` injection and no per-rendition session to bind keys to).

**Impact**: even if §2.1 were fixed and `TranscodeMovie` reached the encrypt stage, `BadMethodCallException: Call to undefined method App\Services\Drm\HlsEncryptor::encrypt()` fires before any segment is touched. Job is retried twice, then marked `failed`.

### 2.3 `UploadToBunny` calls `BunnyStorageService::uploadDirectory()` — method does not exist

`UploadToBunny.php:88`:
```php
$uploaded = $bunny->uploadDirectory(
    localDir: $output['hls_dir'],
    remotePrefix: $remotePrefix,
);
```

`BunnyStorageService` exposes only `put / putStream / delete / exists / signedUrl / publicUrl / listFiles / storageUrl`. `uploadDirectory()` does not exist.

`writeMasterManifest()` is guarded by `method_exists($bunny, 'writeMasterManifest')` and falls back gracefully — that part is fine — but the `uploadDirectory()` call is unconditional and throws `BadMethodCallException` on the first rendition.

Secondary issue in the same job (lines 126-132): the post-upload `movies.encoding_renditions` payload is built from `$r['spec']['height']` and `$r['spec']['bitrate']`. The pipeline writes `RenditionSpec` objects (or arrays once §2.1 is resolved) under `spec`, and that VO has `video_bitrate`/`max_bitrate` but **no `bitrate` key**. So even after fixing the missing method, the manifest list will get `'bitrate' => null` and the player's master-playlist `BANDWIDTH=` line will be `0`.

**Impact**: assets never reach Bunny; `movies.encoding_status` flips back to `failed`; `movies.hls_manifest_path` stays NULL; the watch view falls through to the Video.js raw-mp4 path forever.

### 2.4 The dynamic manifest emits segment URLs that don't route anywhere

`PlaybackManifestGenerator::segmentUrl()` (line 208) hardcodes `/drm/segment/{movieId}/{00000}.ts`. `PlaybackManifestGenerator::mediaPlaylistUrl()` (line 194) hardcodes `/drm/playlist/{token}/{movieId}/{idx}.m3u8`. Neither route is defined in `routes/web.php` — `Route::get('/drm/key/...')` at line 451 is the **only** `/drm/...` route present.

So when Shaka loads `/playback/{movie}/manifest.m3u8`, the response body is a multi-rendition master that points at media playlists that 404, which point at segments that also 404. Shaka surfaces this as `MANIFEST_FAILURE` and bubbles up to the `flik:session-lost` event without ever requesting an AES key.

This means the JWT-protected key endpoint (`/drm/key/{token}/{kid}`) has **never been reachable from the dynamic-manifest path** in any environment where `encoding_status='ready'` and a real player session has been bootstrapped. The unit-level checks (token shape, replay binding, geo gate) all work in isolation — but the upstream wiring delivers no key requests in practice.

There is also no segment route definition or controller registered for `/drm/segment/*` despite `PlaybackManifestGenerator` being designed to point at one. Either the manifest generator needs to emit Bunny CDN URLs directly (using `BunnyStorageService::signedUrl()`), or a `PlaybackController::segment()` method + route is missing.

---

## 3. High-severity findings

### 3.1 `GeoBlock` middleware is registered as an alias but never applied

`app/Http/Kernel.php:95` registers `'geoblock' => GeoBlock::class`. Grep across `routes/` and the codebase (excluding the middleware file itself, Kernel, and docs) returns **zero** uses. Movie detail page, the play API, the manifest, the key endpoint — none of them go through the middleware.

The `PlaybackController::key()` action does its own inline `geoBlocked()` check (line 386-413), so the *key delivery* endpoint is geo-aware. But:
- `/movies`, `/movie/{slug}`, `/playback/{movie}/config`, `/playback/{movie}/manifest.m3u8`, `/playback/{movie}/heartbeat`, `/api/movies/{movie}/plot-explain`, `/api/xray/{slug}`, the highlight reel pages, and the watch-party room are all reachable from blocked countries.
- The inline check in `PlaybackController` only fires on the *key* path; a determined client could still pull the manifest and probe segment URLs.
- The middleware audit-logs `geo.blocked`; the inline check audits via `SecurityEvents::DRM_KEY_DENIED reason=geo_restricted` — two different action names, harder to correlate.

### 3.2 The forensic watermarker is never invoked

`ForensicWatermarker::addBurnInWatermark()` is fully implemented (Symfony Process + `drawtext` filter + char escaping), and `addInvisibleWatermark()` returns false with a "use commercial NexGuard" log. Grep across `app/` finds **zero callers**. The transcoding pipeline does not call it, the encryption stage does not call it, the upload stage does not call it, the playback controller does not call it. The "forensic identifier per playback" promise from the brand is currently untrue. The class exists as scaffolding only.

### 3.3 Two optional composer deps are not declared, so security-critical paths use the in-house fallback

- `firebase/php-jwt`: not in `composer.json` (`grep firebase composer.json` → no matches). `DrmTokenService::encode/decode` always runs the manual `base64UrlEncode + hash_hmac` paths. The manual decoder is functionally correct (algorithm pinning, `hash_equals`, `exp`/`nbf` checks) but does not validate `iat` skew, does not enforce `typ='JWT'`, and does not enforce that `alg` matches the configured one before signature comparison (it does check `alg === self::ALG`, so that's fine; but the lack of `firebase/php-jwt` means we are also missing future security patches it would auto-deliver via composer updates).
- `geoip2/geoip2`: not in `composer.json`. `GeoIpResolver::resolveViaMaxMind()` short-circuits to `null`, so every uncached IP resolution goes to ip-api.com over plaintext HTTP (line 178) — which is rate-limited to ~45 req/min/source IP and adds 2 s of HTTP latency to first key-request per IP per day. Production-grade geo enforcement at our scale is not viable on the HTTP fallback alone.

### 3.4 The watch view's Shaka branch is gated on `hls_manifest_path` being non-null

`resources/views/components/movies/show.blade.php:32`:
```blade
@if($movieModel->encoding_status === 'ready' && $movieModel->hls_manifest_path)
```

`hls_manifest_path` is only written by `UploadToBunny::handle()` (the broken job). Until §2.1-2.4 are fixed, no movie will ever satisfy this branch in production, and every "Play" click will fall through to the `@elseif($movieModel->video_path)` branch — i.e., **raw-mp4 streaming via Video.js with no DRM**. Today's deployment effectively has no DRM in practice, regardless of how complete the underlying service code is.

This branching itself is correct (HLS+DRM with fallback to mp4 is the right design); the issue is that today the HLS branch is structurally unreachable.

---

## 4. Medium / informational findings

### 4.1 `DrmKeyService::createSession` ignores TTL → concurrent-stream lock TTL coupling

`createSession()` sets `expires_at = now()+30min` for the DRM session, while `ConcurrentStreamLimiter::acquire()` sets the lock TTL to `DEFAULT_EXTEND_MINUTES=5` and relies on heartbeats. If the player crashes silently, the DRM session is still valid for up to 25 more minutes (i.e., the same `session_token` can be presented for AES key fetches even though the stream slot has been released and the user has started another stream on a different device). Practical exploit requires the user to lift the token out of the page mid-session — not trivial, but it does mean the "concurrent" limit can briefly be exceeded by 1 during the heartbeat-loss window.

### 4.2 `PlaybackController::config()` issues a fresh DrmSession on every page load

There is no idempotency on `/playback/{movie}/config`. Refreshing the page mints a new `session_token` (and inside the limiter, a new concurrent lock — the old token's lock decays in 5 minutes). For a user who refreshes a few times to debug playback, this can briefly hit the stream cap. Consider deduping on `(user_id, movie_id)` with an active session in the last N minutes.

### 4.3 `PlaybackController::config()` is a double-acquire on the concurrent limiter

Lines 85-109 acquire on a candidate token, then release it and re-acquire on the real session token. The window between release and acquire is small but real — if the user is *already* at their stream cap, the candidate-acquire correctly fails (good), but the success path momentarily uses 2 locks before falling back to 1. Low impact, but worth fixing by either (a) letting `DrmKeyService::createSession()` accept a pre-generated token, or (b) deferring lock acquisition until the first heartbeat.

### 4.4 `BunnyStorageService` throws in constructor when keys are missing

`BunnyStorageService::__construct()` (line 47) throws `RuntimeException` when `BUNNY_STORAGE_ZONE` or `BUNNY_STORAGE_KEY` are empty. This contradicts the project-wide convention documented in `CLAUDE.md` ("Bunny CDN... no-ops gracefully when keys are missing"). It also means that if the container is asked to resolve `BunnyStorageService` anywhere in a request lifecycle on a dev box without Bunny keys, the request 500s. Today only `UploadToBunny::handle()` resolves it, but service-container autowiring will trigger this in any controller / future job that type-hints it. The `S3StorageService` does not have this problem (lazy via `Storage::disk()`).

### 4.5 `GeoIpResolver` HTTP fallback uses plaintext HTTP

`http://ip-api.com/json/{ip}` (line 178). Should be `https://` — ip-api.com offers HTTPS on the paid tier, but at minimum the request leaks the user's IP geo-lookup to any on-path observer between the FLiK origin and ip-api. Low risk because the answer is the same `countryCode` that the origin's ISP-level routing already telegraphed, but defaults should be HTTPS.

### 4.6 `Cache-Control` on the manifest is good; cache busting on the AES key endpoint missing intermediate-proxy guard

The `key()` response sets `Cache-Control: no-store, no-cache, must-revalidate, max-age=0`. Add `Surrogate-Control: no-store` for Bunny CDN-edge cases where the FLiK origin sits behind Bunny perimeter and `Cache-Control` could be re-honoured per-pull-zone settings.

### 4.7 `PlaybackController::resolveCountry()` references `App\\Services\\GeoIpResolver` not `App\\Services\\Geo\\GeoIpResolver`

Line 364 binds-check `'App\\Services\\GeoIpResolver'`. The real class is at `App\Services\Geo\GeoIpResolver`. The fallback branch (CDN headers → resolver) silently skips the resolver path because the container is never bound under the bare namespace. Today only the explicit CF/X-Country-Code/X-Geoip-Country headers actually populate the country code; the configured MaxMind/HTTP resolver is **not** consulted from this controller.

### 4.8 `EncodingJob::updateProgress()` is called via `array_search` in a foreach — quadratic

`TranscodingPipeline::run()` (line 135): `array_search($renditionKey, array_keys($outputs), true)+1`. Not a hot path, but unnecessary work; a manual counter would be `O(n)` instead of `O(n²)`.

### 4.9 Player JS: `Authorization: Bearer ${jwt}` and `?token=` both sent on heartbeat

`flik-player.js:244` sends the JWT as both an `Authorization: Bearer` header and a JSON body field. The controller reads `request->bearerToken() ?? request->input('token', '')` — they collapse to the same value. Harmless duplication, but useful to remove the body field once you trust the header path.

### 4.10 `DeviceFingerprinter` runs in dev tools easily; not a security control

The fingerprint is documented as anti-share, not anti-bot. That's correctly framed in the docblock ("Real anti-piracy guarantees come from server-side key gating + commercial Widevine/PlayReady"). Worth re-emphasising in any operational doc that promises "device DRM" — what we have here is a low-friction casual-sharing deterrent, not a cert-pinned hardware DRM.

---

## 5. Answers to the critical checks from the brief

| Check | Answer |
|---|---|
| Is the DRM key endpoint actually JWT-protected? | **Yes**. `validateKeyRequestToken` is called, payload `iss`/`aud`/`exp`/`session_id`/`kid` are all bound. Manual HMAC fallback is in use today (no `firebase/php-jwt` dep). |
| Does FFmpeg pipeline work end-to-end? | **No**. Three call-site signature mismatches (§2.1, §2.2, §2.3) cause the chain to throw at the transcode stage. Nothing reaches Bunny. |
| Is Shaka Player actually loaded + initialized? | **Yes**, but unreachable in practice. Shaka 4.7.11 is loaded via CDN `<script defer>` in the layout, `FlikPlayer` wrapper is real, the watch view branches on `encoding_status='ready'`. Because pipeline never reaches that state (see above), the Shaka branch is never taken. |
| Concurrent stream limiter wired? | **Yes**, in `PlaybackController::config()` and `::heartbeat()`. Backed by `playback_concurrent_locks` table, capped to `subscription_plans.max_screens`. Note brief over-cap window (§4.3). |
| Geo-blocking middleware applied? | **No**. `GeoBlock` middleware is defined and registered as the `geoblock` alias in Kernel.php, but never mounted on any route (§3.1). Inline geo check exists only on the AES key endpoint. |
| Watermark applied per playback? | **No**. `ForensicWatermarker` is implemented but never invoked anywhere in the pipeline (§3.2). |
| Forensic identifier tracked? | **No** (per-playback). DRM sessions do record `user_id`, `ip`, `country`, `device_fingerprint`, and `session_token`, so a server-side trail exists. But there is no per-stream forensic mark embedded in the actual video bytes. |
| Can admin upload master video + start transcode from UI? | **Upload yes, transcode start yes, transcode complete no.** `MovieUploadController::uploadMaster()` + `startTranscode()` work; they pre-create an `EncodingJob` row and dispatch `TranscodeMovie::dispatch($movie->id)`. The job runs and immediately fails at the call sites in §2.1. |
| Is the player view using HLS when `encoding_status=ready`, or still falling back to video.js+raw mp4? | **In code, it would correctly use HLS.** In practice no movie reaches `encoding_status='ready'`, so the `@elseif($movieModel->video_path)` branch always wins — Video.js with raw mp4, no DRM. |

---

## 6. Suggested remediation order (no code changes made in this audit)

1. **Fix the three job/service signature mismatches** (§2.1, §2.2, §2.3) or unify on a single canonical signature for each service. Decide whether to push the rendition object/array shape conversion up into the pipeline or down into the services.
2. **Add the missing segment + media-playlist routes** (or change `PlaybackManifestGenerator` to point at signed Bunny URLs derived from `movies.encoding_renditions`). §2.4.
3. **Apply `geoblock` middleware** to `/movie/{movie}`, `/playback/{movie}/*`, `/highlight/*`, and the X-Ray API. §3.1.
4. **Wire `ForensicWatermarker::addBurnInWatermark()`** into either a pre-encrypt rendition pass or a per-session manifest re-pack (the latter is more flexible but requires per-session storage of watermarked variants — large cost). §3.2.
5. **Add `firebase/php-jwt` and `geoip2/geoip2` to `composer.json`** and update `GeoIpResolver` HTTP fallback to HTTPS. §3.3, §4.5.
6. **Loosen `BunnyStorageService` constructor** so missing env doesn't 500 dev boxes that don't need the CDN. §4.4.
7. **Fix the namespace typo in `PlaybackController::resolveCountry()`** so the MaxMind/HTTP resolver is actually consulted. §4.7.
8. Replace the `encoding_renditions` payload key `bitrate` with `video_bitrate` (or vice versa) to keep player BANDWIDTH= correct (§2.3 secondary).

---

## 7. Files inspected (absolute paths)

DRM services:
- `D:\AI\velflix\velflix\app\Services\Drm\DrmKeyService.php`
- `D:\AI\velflix\velflix\app\Services\Drm\DrmTokenService.php`
- `D:\AI\velflix\velflix\app\Services\Drm\HlsEncryptor.php`
- `D:\AI\velflix\velflix\app\Services\Drm\PlaybackManifestGenerator.php`
- `D:\AI\velflix\velflix\app\Services\Drm\DeviceFingerprinter.php`
- `D:\AI\velflix\velflix\app\Services\Drm\ConcurrentStreamLimiter.php`
- `D:\AI\velflix\velflix\app\Services\Drm\ForensicWatermarker.php`
- `D:\AI\velflix\velflix\app\Services\Drm\DrmAuditEvent.php`

Transcoding services:
- `D:\AI\velflix\velflix\app\Services\Transcoding\TranscodingPipeline.php`
- `D:\AI\velflix\velflix\app\Services\Transcoding\FfmpegTranscoder.php`
- `D:\AI\velflix\velflix\app\Services\Transcoding\AbrLadderBuilder.php`
- `D:\AI\velflix\velflix\app\Services\Transcoding\HlsSegmenter.php`
- `D:\AI\velflix\velflix\app\Services\Transcoding\MediaInfo.php`
- `D:\AI\velflix\velflix\app\Services\Transcoding\RenditionSpec.php`

Storage / geo:
- `D:\AI\velflix\velflix\app\Services\Storage\BunnyStorageService.php`
- `D:\AI\velflix\velflix\app\Services\Storage\S3StorageService.php`
- `D:\AI\velflix\velflix\app\Services\Geo\GeoIpResolver.php`
- `D:\AI\velflix\velflix\app\Http\Middleware\GeoBlock.php`

Controllers / jobs / models:
- `D:\AI\velflix\velflix\app\Http\Controllers\PlaybackController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\Admin\MovieUploadController.php`
- `D:\AI\velflix\velflix\app\Jobs\TranscodeMovie.php`
- `D:\AI\velflix\velflix\app\Jobs\EncryptHlsSegments.php`
- `D:\AI\velflix\velflix\app\Jobs\UploadToBunny.php`
- `D:\AI\velflix\velflix\app\Models\EncodingJob.php`
- `D:\AI\velflix\velflix\app\Models\DrmSession.php`
- `D:\AI\velflix\velflix\app\Models\PlaybackConcurrentLock.php`

Player JS + views:
- `D:\AI\velflix\velflix\resources\js\player\flik-player.js`
- `D:\AI\velflix\velflix\resources\js\player\auto-skip.js`
- `D:\AI\velflix\velflix\resources\js\player\xray-overlay.js`
- `D:\AI\velflix\velflix\resources\views\components\movies\show.blade.php`
- `D:\AI\velflix\velflix\resources\js\app.js`
- `D:\AI\velflix\velflix\resources\views\components\layout.blade.php` (Shaka CDN tag)

Migrations + routing:
- `D:\AI\velflix\velflix\database\migrations\2026_05_10_020001_extend_movies_for_distribution.php`
- `D:\AI\velflix\velflix\database\migrations\2026_05_10_020002_create_encoding_jobs_table.php`
- `D:\AI\velflix\velflix\database\migrations\2026_05_10_020003_create_drm_sessions_table.php`
- `D:\AI\velflix\velflix\database\migrations\2026_05_10_020004_create_playback_concurrent_locks_table.php`
- `D:\AI\velflix\velflix\database\migrations\2026_05_10_020005_extend_movies_with_intro_outro.php`
- `D:\AI\velflix\velflix\routes\web.php` (lines 180, 451-452, 747-752, 1103-1106)
- `D:\AI\velflix\velflix\app\Http\Kernel.php` (line 95)
- `D:\AI\velflix\velflix\config\services.php` (lines 71-78, Bunny block)
- `D:\AI\velflix\velflix\composer.json` (firebase/php-jwt, geoip2/geoip2 absent)
- `D:\AI\velflix\velflix\app\Console\Commands\TranscodeMovie.php`
