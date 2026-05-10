# RANCANGAN — Implementasi FLiK 3-Bulan Build

**Document ini = peta jalan teknis** untuk dev yang akan eksekusi pitch deck v2.1.

> Versi: 1.0 · Tanggal: 2026-05-10 · Untuk: senior dev FLiK
> Scope: melengkapi aplikasi FLiK Laravel 12 existing menjadi platform OTT production-ready
> Companion docs: [PITCH_DECK.md](PITCH_DECK.md) · [ARCHITECTURE.md](ARCHITECTURE.md) · [CLAUDE.md](CLAUDE.md)

---

## 0. Status Saat Ini (Inventory)

### ✅ Sudah ada di codebase

| Komponen | Lokasi | Status |
|----------|--------|--------|
| Web app + admin panel | `app/Http/Controllers/AdminController.php` | Working |
| Auth + Google OAuth | `LoginController`, `RegisterController` | Working |
| Payment Midtrans | `PaymentController.php` | Working (env-gated) |
| Gamification (XP/coin/achievement) | `RewardsController`, models | Working |
| Comments, ratings, watchlist | Controllers + models | Working |
| Watch history & resume | `WatchHistoryController` | Working |
| PWA + service worker | `public/sw.js`, `manifest.json` | Working |
| NativePHP Android wrapper | `nativephp/`, `native` script | Configured |
| **AI Provider settings** (encrypted API key) | `App\Models\AiProvider`, `/admin/ai-settings` | Working |
| Pitch deck admin page | `/admin/pitch-deck` | Working |
| Movie/Genre/Cast/Banner CRUD | `AdminController` | Working |

### 🚧 Gap (Yang Perlu Dibangun)

| Komponen | Priority | Fase |
|----------|----------|------|
| **Transcoding pipeline** (FFmpeg → ABR ladder) | 🔴 Critical | Phase 1 |
| **DIY DRM stack** (AES-128 + key server + JWT) | 🔴 Critical | Phase 1 |
| **Bunny CDN integration** (storage + stream) | 🔴 Critical | Phase 1 |
| **Shaka Player** (web playback) | 🔴 Critical | Phase 1 |
| **Playback controller** (signed manifests) | 🔴 Critical | Phase 1 |
| **AiClient service layer** (multi-provider abstraction) | 🟡 High | Phase 2 |
| **Subtitle pipeline** (gpt-4o-mini-transcribe) | 🟡 High | Phase 2 |
| **Auto-tagging** (Claude Haiku + Gemini vision) | 🟡 High | Phase 2 |
| **Semantic search** (pgvector embeddings) | 🟡 High | Phase 2 |
| **Recommendation engine** (DeepSeek batch nightly) | 🟢 Medium | Phase 3 |
| **AI chatbot CS** (Groq Llama 4) | 🟢 Medium | Phase 3 |
| **Auto-translation subtitle** (3 bahasa) | 🟢 Medium | Phase 3 |
| **Comment moderation** (Gemini Flash-Lite batch) | 🟢 Medium | Phase 3 |
| **Trailer auto-suggestion** (vision) | 🟢 Medium | Phase 3 |
| **Thumbnail selection** (Gemini vision) | 🟢 Medium | Phase 3 |
| **EZDRM optional toggle** (per-film) | 🔵 Optional | Phase 3 |
| **Audit log** (admin actions) | 🟢 Medium | Phase 3 |
| **Resumable upload** (TUS protocol) | 🔵 Optional | Phase 3 |
| **Email personalization** (DeepSeek) | 🔵 Optional | Phase 3 |

---

## 1. Pre-requisites (Sebelum Mulai Coding)

### 1.1 Akun & Service yang Perlu Disiapkan

| Service | Action | Cost |
|---------|--------|------|
| AWS account | Buat IAM user untuk dev, attach S3FullAccess + EC2FullAccess (untuk dev) | Free tier dulu |
| Bunny.net | Sign up, buat Storage Zone "flik-storage", Pull Zone "flik-cdn", Stream Library "flik-stream" | $0 untuk daftar |
| MaxMind | Daftar GeoLite2 free account, generate license key | Free |
| DeepSeek | Daftar, generate API key, top up $50 dev credit | $50 |
| OpenAI | Daftar, generate API key untuk transcribe | $50 dev credit |
| Anthropic | (Optional, untuk auto-tag quality) Daftar, generate key | $50 dev credit |
| Google AI Studio | Daftar, generate Gemini API key | Free tier sampai limit |
| Sentry | Daftar free tier untuk error tracking | Free |
| EZDRM (optional) | Trial account untuk testing | Trial |

### 1.2 Library Baru yang Perlu Di-install

#### Composer (PHP)
```bash
composer require aws/aws-sdk-php           # AWS S3, MediaConvert SDK
composer require league/flysystem-aws-s3-v3 # Laravel S3 driver
composer require firebase/php-jwt           # JWT untuk DRM tokens
composer require pgvector/pgvector-php      # pgvector client
composer require league/commonmark          # Markdown parser (untuk admin)
composer require torann/geoip               # Geo lookup wrapper
composer require predis/predis              # Redis client (atau sudah ada)
composer require symfony/process            # FFmpeg shell wrapper
composer require spatie/image               # Image processing untuk thumbnail
```

#### NPM (Frontend)
```bash
npm install shaka-player                    # DRM-aware video player
npm install hls.js                          # HLS fallback player
npm install @videojs/http-streaming         # If using Video.js
npm install crypto-js                       # Browser-side encryption helpers
```

#### System (Server)
```bash
# Di server production (Ubuntu/AWS Linux):
apt install ffmpeg                          # Transcoding
apt install postgresql-15-pgvector          # pgvector extension
```

### 1.3 Environment Variables Baru

Tambah ke `.env`:
```env
# Bunny CDN
BUNNY_STORAGE_ZONE=flik-storage
BUNNY_STORAGE_KEY=
BUNNY_STORAGE_HOSTNAME=storage.bunnycdn.com
BUNNY_PULL_ZONE_URL=https://flik-cdn.b-cdn.net
BUNNY_STREAM_LIBRARY_ID=
BUNNY_STREAM_API_KEY=

# DRM
DRM_KEY_ROTATION_MINUTES=5
DRM_TOKEN_TTL_SECONDS=600
DRM_GEO_RESTRICTIONS=ID,SG,MY,TH

# MaxMind
MAXMIND_LICENSE_KEY=
MAXMIND_DB_PATH=storage/app/geoip/GeoLite2-Country.mmdb

# Transcoding
FFMPEG_BINARY=/usr/bin/ffmpeg
FFPROBE_BINARY=/usr/bin/ffprobe
TRANSCODE_TEMP_DIR=storage/app/transcode-tmp
TRANSCODE_HARDWARE_ACCEL=false      # set true di server dengan GPU

# Sentry
SENTRY_LARAVEL_DSN=

# EZDRM (optional)
EZDRM_USERNAME=
EZDRM_PASSWORD=
EZDRM_WIDEVINE_PROFILE=
EZDRM_PLAYREADY_PROFILE=
EZDRM_FAIRPLAY_PROFILE=
```

---

## 2. Phase 1 — Distribution Layer (Bulan 1, ~4 minggu)

**Goal**: Film bisa di-upload admin → transcode → encrypt → publish ke CDN → playable di browser dengan auth & DRM.

### 2.1 Database Migrations

**File**: `database/migrations/2026_05_10_000001_extend_movies_for_distribution.php`
```php
Schema::table('movies', function (Blueprint $table) {
    // Encoding
    $table->string('encoding_status', 20)->default('pending')->after('video_path'); // pending|processing|ready|failed
    $table->json('encoding_renditions')->nullable()->after('encoding_status'); // [{height: 360, bitrate: 800000, path: '...'}]
    $table->string('master_file_path')->nullable()->after('encoding_renditions');
    $table->string('master_file_disk', 20)->default('s3')->after('master_file_path');
    $table->unsignedInteger('duration_seconds')->nullable()->after('master_file_disk');

    // DRM
    $table->enum('drm_strategy', ['none', 'diy_aes128', 'ezdrm'])->default('diy_aes128');
    $table->json('drm_config')->nullable(); // key id refs, content keys (encrypted)

    // CDN/Streaming
    $table->string('hls_manifest_path')->nullable();
    $table->string('dash_manifest_path')->nullable();
    $table->string('cdn_disk', 20)->default('bunny');

    // Geo
    $table->json('geo_allow')->nullable(); // ['ID', 'SG'] or null = all

    $table->index('encoding_status');
});
```

**File**: `database/migrations/2026_05_10_000002_create_encoding_jobs_table.php`
```php
Schema::create('encoding_jobs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('movie_id')->constrained()->cascadeOnDelete();
    $table->enum('status', ['queued', 'transcoding', 'encrypting', 'uploading', 'completed', 'failed']);
    $table->json('rendition_specs')->nullable();
    $table->json('output_paths')->nullable();
    $table->text('error_message')->nullable();
    $table->unsignedInteger('progress_percent')->default(0);
    $table->timestamp('started_at')->nullable();
    $table->timestamp('completed_at')->nullable();
    $table->timestamps();

    $table->index(['movie_id', 'status']);
});
```

**File**: `database/migrations/2026_05_10_000003_create_drm_sessions_table.php`
```php
Schema::create('drm_sessions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->foreignId('movie_id')->constrained()->cascadeOnDelete();
    $table->string('session_token', 128)->unique();
    $table->string('device_fingerprint', 128)->nullable();
    $table->string('client_ip', 45)->nullable();
    $table->string('country_code', 2)->nullable();
    $table->binary('content_key', 16)->nullable(); // AES-128 binary key (encrypted at rest via APP_KEY)
    $table->timestamp('last_key_request_at')->nullable();
    $table->unsignedInteger('key_request_count')->default(0);
    $table->timestamp('expires_at');
    $table->timestamp('revoked_at')->nullable();
    $table->timestamps();

    $table->index(['user_id', 'movie_id']);
    $table->index('session_token');
    $table->index('expires_at');
});
```

**File**: `database/migrations/2026_05_10_000004_create_audit_logs_table.php`
```php
Schema::create('audit_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
    $table->string('action', 80); // movie.uploaded, drm.key_issued, etc
    $table->string('subject_type', 80)->nullable(); // App\Models\Movie
    $table->unsignedBigInteger('subject_id')->nullable();
    $table->string('client_ip', 45)->nullable();
    $table->json('meta')->nullable();
    $table->timestamps();

    $table->index(['user_id', 'created_at']);
    $table->index(['subject_type', 'subject_id']);
    $table->index('action');
});
```

### 2.2 Service Classes

```
app/Services/
├── Storage/
│   ├── BunnyStorageService.php          # Upload, list, delete, presigned URL
│   └── S3StorageService.php             # AWS S3 wrapper untuk master backup
├── Transcoding/
│   ├── FfmpegTranscoder.php             # Wrapper FFmpeg shell process
│   ├── AbrLadderBuilder.php             # Generate rendition specs (360p/480p/720p/1080p)
│   └── TranscodingPipeline.php          # Orchestrator: download master → transcode → encrypt → upload
├── Drm/
│   ├── DrmKeyService.php                # Generate, rotate, retrieve content keys
│   ├── DrmTokenService.php              # JWT issue/validate untuk session token
│   ├── PlaybackTokenSigner.php          # Sign manifest URL token
│   ├── HlsEncryptor.php                 # AES-128 segment encryption (FFmpeg-driven)
│   ├── DeviceFingerprinter.php          # Validate fingerprint claims
│   └── ConcurrentStreamLimiter.php      # Redis counter per user
├── Geo/
│   └── GeoIpResolver.php                # MaxMind lookup, geo-block check
└── Audit/
    └── AuditLogger.php                  # Write entries to audit_logs
```

### 2.3 Controllers Baru

**File**: `app/Http/Controllers/PlaybackController.php`
```php
class PlaybackController extends Controller
{
    // GET /playback/{movie}/config — return manifest URL + key URL + JWT token
    public function config(Movie $movie);

    // GET /drm/key/{sessionToken}/{keyId} — serve binary AES-128 key
    public function key(string $sessionToken, string $keyId);

    // GET /playback/{movie}/manifest.m3u8 — generate dynamic HLS manifest with signed segments
    public function manifest(Movie $movie);

    // POST /playback/{movie}/heartbeat — keep session alive, validate concurrent
    public function heartbeat(Movie $movie);
}
```

**File**: `app/Http/Controllers/Admin/MovieUploadController.php`
```php
class MovieUploadController extends Controller
{
    // POST /admin/movies/{movie}/upload-master — chunked upload entry
    public function uploadMaster(Request $request, Movie $movie);

    // POST /admin/movies/{movie}/start-transcode — dispatch TranscodeMovie job
    public function startTranscode(Movie $movie);

    // GET /admin/movies/{movie}/encoding-status — return EncodingJob status (polling)
    public function encodingStatus(Movie $movie);
}
```

### 2.4 Queue Jobs

```
app/Jobs/
├── TranscodeMovie.php                    # Trigger pipeline, update encoding_jobs
├── EncryptHlsSegments.php                # Run after transcode, AES-128
├── UploadToBunny.php                     # Stream output to Bunny storage
├── RotateDrmKey.php                      # Scheduled, rotate dormant content keys
└── CleanupExpiredDrmSessions.php         # Scheduled, hourly
```

### 2.5 Routes Baru

`routes/web.php` (tambahan):
```php
Route::middleware('auth')->group(function () {
    // Playback
    Route::get('/playback/{movie}/config', [PlaybackController::class, 'config'])->name('playback.config');
    Route::get('/playback/{movie}/manifest.m3u8', [PlaybackController::class, 'manifest'])->name('playback.manifest');
    Route::post('/playback/{movie}/heartbeat', [PlaybackController::class, 'heartbeat'])->name('playback.heartbeat');
});

// DRM key endpoint — bypass session, validate via signed token
Route::get('/drm/key/{sessionToken}/{keyId}', [PlaybackController::class, 'key'])
    ->middleware('throttle:120,1')
    ->name('drm.key');

// Admin upload routes
Route::middleware(['auth', 'can:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::post('/movies/{movie}/upload-master', [MovieUploadController::class, 'uploadMaster'])->name('movies.upload-master');
    Route::post('/movies/{movie}/start-transcode', [MovieUploadController::class, 'startTranscode'])->name('movies.start-transcode');
    Route::get('/movies/{movie}/encoding-status', [MovieUploadController::class, 'encodingStatus'])->name('movies.encoding-status');
});
```

### 2.6 Frontend (Blade + JS)

**File**: `resources/views/components/movies/player.blade.php` (replace existing player)
```blade
<div id="flik-player-mount" data-movie-id="{{ $movie->id }}"></div>

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/shaka-player@4.10.10/dist/shaka-player.compiled.min.js"></script>
<script>
    // Initialize Shaka with DRM
    // 1. Fetch /playback/{movie}/config
    // 2. Configure Shaka with manifest URL + key request handler
    // 3. Setup heartbeat interval (every 30s)
    // 4. Handle DRM errors gracefully
</script>
@endpush
```

**File**: `resources/js/player/flik-player.js`
- Shaka Player wrapper class
- Heartbeat manager
- Device fingerprint generator (canvas + WebGL hash)
- DevTools detector
- Resume from last watch position

### 2.7 Estimasi Phase 1

| Task | Estimasi | Dependency |
|------|----------|------------|
| Setup AWS, Bunny, MaxMind accounts | 1 hari | - |
| 4 migrations + run | 0.5 hari | - |
| BunnyStorageService + S3StorageService | 1.5 hari | accounts ready |
| FfmpegTranscoder + AbrLadderBuilder | 2 hari | - |
| TranscodingPipeline orchestrator | 2 hari | transcoder, storage |
| DrmKeyService + DrmTokenService | 2 hari | - |
| HlsEncryptor + integrate ke pipeline | 1.5 hari | DrmKeyService, transcoder |
| GeoIpResolver + middleware | 0.5 hari | MaxMind |
| ConcurrentStreamLimiter | 0.5 hari | Redis |
| PlaybackController (4 endpoints) | 2 hari | DRM services |
| TranscodeMovie + UploadToBunny jobs | 1 hari | pipeline |
| MovieUploadController + admin UI | 2 hari | jobs |
| Shaka Player integration di Blade | 2.5 hari | PlaybackController |
| Test E2E (upload → transcode → play) | 2 hari | semua di atas |
| Bug fix & polish | 2 hari | testing |
| **TOTAL Phase 1** | **~22 hari kerja (~4-5 minggu)** | |

---

## 3. Phase 2 — AI Layer (Bulan 2, ~4 minggu)

**Goal**: AI service abstraction yang bisa pakai multi-provider, plus 4 fitur AI utama (subtitle, tagging, search, recommendation).

### 3.1 Database Migrations

**File**: `database/migrations/2026_06_01_000001_create_ai_usage_logs_table.php`
```php
Schema::create('ai_usage_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('ai_provider_id')->constrained()->cascadeOnDelete();
    $table->string('task_type', 60); // subtitle, tagging, search, chat, etc
    $table->string('subject_type', 80)->nullable();
    $table->unsignedBigInteger('subject_id')->nullable();
    $table->unsignedInteger('input_tokens')->default(0);
    $table->unsignedInteger('output_tokens')->default(0);
    $table->decimal('cost_usd', 10, 6)->default(0);
    $table->unsignedInteger('latency_ms')->nullable();
    $table->boolean('cache_hit')->default(false);
    $table->boolean('success')->default(true);
    $table->text('error_message')->nullable();
    $table->timestamps();

    $table->index(['ai_provider_id', 'created_at']);
    $table->index(['task_type', 'created_at']);
});
```

**File**: `database/migrations/2026_06_01_000002_create_ai_jobs_table.php`
```php
Schema::create('ai_jobs', function (Blueprint $table) {
    $table->id();
    $table->string('job_type', 60); // subtitle.generate, tagging.movie, embed.movie
    $table->string('subject_type', 80);
    $table->unsignedBigInteger('subject_id');
    $table->enum('status', ['queued', 'processing', 'completed', 'failed'])->default('queued');
    $table->json('input_data')->nullable();
    $table->json('output_data')->nullable();
    $table->text('error_message')->nullable();
    $table->timestamp('started_at')->nullable();
    $table->timestamp('completed_at')->nullable();
    $table->timestamps();

    $table->index(['subject_type', 'subject_id']);
    $table->index(['job_type', 'status']);
});
```

**File**: `database/migrations/2026_06_01_000003_create_movie_embeddings_table.php`
```php
// Run on PostgreSQL with pgvector extension
DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

Schema::create('movie_embeddings', function (Blueprint $table) {
    $table->id();
    $table->foreignId('movie_id')->constrained()->cascadeOnDelete();
    $table->string('embedding_type', 40); // 'overview', 'metadata', 'combined'
    $table->string('model_name', 60); // text-embedding-3-small
    $table->unsignedSmallInteger('dimensions');
    $table->timestamps();
    $table->unique(['movie_id', 'embedding_type']);
});

DB::statement('ALTER TABLE movie_embeddings ADD COLUMN embedding vector(1536)');
DB::statement('CREATE INDEX movie_embeddings_vector_idx ON movie_embeddings USING ivfflat (embedding vector_cosine_ops) WITH (lists = 100)');
```

**File**: `database/migrations/2026_06_01_000004_create_movie_subtitles_table.php`
```php
Schema::create('movie_subtitles', function (Blueprint $table) {
    $table->id();
    $table->foreignId('movie_id')->constrained()->cascadeOnDelete();
    $table->string('language_code', 10); // id, en, zh, ar
    $table->string('label', 60); // 'Indonesia', 'English (auto)', etc
    $table->string('webvtt_path'); // path di Bunny
    $table->boolean('is_auto_generated')->default(false);
    $table->boolean('is_translated')->default(false);
    $table->string('source_language', 10)->nullable();
    $table->timestamps();
    $table->unique(['movie_id', 'language_code']);
});
```

**File**: `database/migrations/2026_06_01_000005_extend_movies_for_ai.php`
```php
Schema::table('movies', function (Blueprint $table) {
    $table->json('ai_tags')->nullable(); // {mood:['nostalgic'], era:'1970s', themes:['family']}
    $table->json('ai_metadata')->nullable(); // {auto_synopsis, quality_score, restoration_priority}
    $table->timestamp('ai_processed_at')->nullable();
});
```

### 3.2 Service Classes

```
app/Services/Ai/
├── AiClient.php                          # Main facade: chat(), embed(), transcribe(), vision()
├── Providers/
│   ├── ProviderInterface.php
│   ├── OpenAiProvider.php
│   ├── AnthropicProvider.php
│   ├── DeepSeekProvider.php
│   ├── GeminiProvider.php
│   ├── GroqProvider.php
│   └── OpenAiCompatibleProvider.php      # Generic for OpenRouter/Mistral/custom
├── Tasks/
│   ├── SubtitleGenerator.php             # Whisper/gpt-4o-mini-transcribe
│   ├── MovieTagger.php                   # Claude Haiku + Gemini vision
│   ├── SynopsisGenerator.php             # Claude Sonnet
│   ├── ThumbnailPicker.php               # Gemini vision
│   ├── TrailerSuggester.php              # Gemini vision
│   ├── ContentClassifier.php             # DeepSeek
│   └── SubtitleTranslator.php            # DeepSeek
├── Embeddings/
│   ├── EmbeddingService.php              # OpenAI text-embedding-3-small
│   └── SemanticSearchService.php         # pgvector cosine similarity
├── Recommendations/
│   ├── RecommendationEngine.php          # DeepSeek batch nightly
│   └── SimilarityRanker.php              # Hybrid: collaborative + content
├── ProviderRouter.php                    # Pick best provider per task + fallback
└── UsageTracker.php                      # Log to ai_usage_logs, update ai_providers totals
```

### 3.3 Jobs Baru

```
app/Jobs/
├── GenerateMovieSubtitle.php             # Trigger SubtitleGenerator
├── TagMovie.php                          # Trigger MovieTagger
├── GenerateMovieSynopsis.php             # Trigger SynopsisGenerator
├── EmbedMovie.php                        # Generate vector embedding
├── BatchRecomputeRecommendations.php     # Scheduled nightly
└── TranslateSubtitles.php                # Per language
```

### 3.4 Console Commands

```
app/Console/Commands/
├── ProcessMovieCatalogAi.php             # `php artisan flik:ai:process-catalog` — bulk
├── GenerateRecommendations.php           # `php artisan flik:ai:recommend` — daily
└── BackfillEmbeddings.php                # `php artisan flik:ai:embed-all`
```

### 3.5 Controllers / Endpoints

`routes/web.php`:
```php
Route::middleware('auth')->group(function () {
    Route::get('/search/semantic', [SearchController::class, 'semantic'])->name('search.semantic');
    Route::get('/recommendations', [RecommendationController::class, 'forUser'])->name('recommendations.user');
});

Route::middleware(['auth', 'can:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::post('/movies/{movie}/ai/process', [Admin\AiTaskController::class, 'process'])->name('movies.ai.process');
    Route::get('/movies/{movie}/ai/status', [Admin\AiTaskController::class, 'status'])->name('movies.ai.status');
    Route::get('/ai-usage', [Admin\AiUsageController::class, 'index'])->name('ai-usage.index');
});
```

### 3.6 Estimasi Phase 2

| Task | Estimasi |
|------|----------|
| 5 migrations (incl pgvector setup) | 1 hari |
| AiClient + Provider abstraction (5 providers) | 3 hari |
| ProviderRouter + UsageTracker | 1 hari |
| SubtitleGenerator + GenerateMovieSubtitle job | 2 hari |
| MovieTagger + TagMovie job | 1.5 hari |
| SynopsisGenerator + ThumbnailPicker | 1 hari |
| EmbeddingService + SemanticSearchService | 2 hari |
| Search controller + UI integration (Livewire) | 2 hari |
| RecommendationEngine batch + scheduler | 2 hari |
| Console commands untuk bulk processing | 1 hari |
| AI Usage admin dashboard | 1.5 hari |
| Run bulk AI processing untuk 400 film | 2 hari |
| Test & polish | 2 hari |
| **TOTAL Phase 2** | **~22 hari kerja (~4-5 minggu)** |

---

## 4. Phase 3 — Polish & Optional Features (Bulan 3, ~4 minggu)

**Goal**: Fitur AI tambahan untuk user engagement, EZDRM toggle, security audit, soft launch.

### 4.1 Sub-features

| Feature | Effort | Notes |
|---------|--------|-------|
| AI Chatbot CS (Groq Llama 4) | 3 hari | New `App\Services\Ai\Tasks\ChatbotResponder`, Livewire component, knowledge base seeded |
| Comment moderation pipeline | 2 hari | Job `ModerateComment` triggered on `Comment::created`, auto-hide flagged |
| Trailer auto-suggestion | 2 hari | `TrailerSuggester` analyze keyframes, admin review UI |
| Auto-translation subtitle (3 bahasa) | 2 hari | Per-language `TranslateSubtitles` job, queue chain |
| Email/Notification personalization | 2 hari | `App\Services\Ai\Tasks\EmailPersonalizer`, Mailchimp integration |
| EZDRM optional integration | 4 hari | `App\Services\Drm\EzDrmAdapter`, per-movie toggle UI, manifest packaging |
| Audit log dashboard | 1.5 hari | `Admin\AuditLogController`, filter UI |
| Resumable upload (TUS protocol) | 3 hari | TUS PHP package, frontend with `tus-js-client` |
| Security hardening | 3 hari | CSP, rate limit per endpoint, secrets audit, SAST scan |
| Load testing (k6 / Locust) | 2 hari | Test 5K concurrent, identify bottleneck |
| Soft launch bug fix | 3 hari | Closed beta 100 user, iterate |
| **TOTAL Phase 3** | **~28 hari (~5-6 minggu)** | (boleh paralel beberapa) |

---

## 5. Dependency Graph

```
Phase 1 (Distribution)
   ┌─────────────────────────┐
   │  AWS + Bunny accounts   │
   └────────┬────────────────┘
            │
   ┌────────▼─────────┐
   │  Migrations 1-4  │
   └────────┬─────────┘
            │
   ┌────────┴───────┬──────────────┬─────────────┐
   ▼                ▼              ▼             ▼
[Storage svc]  [FFmpeg svc]  [DRM Key svc]  [Geo svc]
   │                │              │
   └────────────────┴──────────────┘
                    │
            ┌───────▼───────┐
            │   Pipeline    │
            └───────┬───────┘
                    │
            ┌───────▼────────┐
            │ PlaybackCtrl + │
            │ Shaka Player   │
            └───────┬────────┘
                    │
                ▼ E2E TEST ▼

Phase 2 (AI) — depends on Phase 1 movies ready
   ┌─────────────────────┐
   │ AiClient + Provider │
   └────────┬────────────┘
            │
   ┌────────┴────────┬───────────┬───────────┐
   ▼                 ▼           ▼           ▼
[Subtitle]      [Tagger]    [Embedding]  [Recommend]
                                 │
                          ┌──────▼──────┐
                          │ pgvector    │
                          │ SearchSvc   │
                          └─────────────┘

Phase 3 (Polish) — bisa paralel:
[Chatbot]  [Moderation]  [Translation]  [EZDRM]  [Audit]
```

---

## 6. Library Summary

### Composer (production)
```bash
composer require \
  aws/aws-sdk-php \
  league/flysystem-aws-s3-v3 \
  firebase/php-jwt \
  pgvector/pgvector-php \
  league/commonmark \
  torann/geoip \
  predis/predis \
  symfony/process \
  spatie/image \
  ankitpokhrel/tus-php
```

### Composer (dev)
```bash
composer require --dev \
  pestphp/pest \
  laravel/dusk \
  brianium/paratest
```

### NPM
```bash
npm install \
  shaka-player \
  hls.js \
  crypto-js \
  tus-js-client
```

### System
- ffmpeg (Ubuntu: `apt install ffmpeg`)
- pgvector extension untuk PostgreSQL
- redis (kalau belum ada)

---

## 7. File Inventory Summary

Total file baru yang dibuat sepanjang 3 phase:

| Kategori | Phase 1 | Phase 2 | Phase 3 | Total |
|----------|---------|---------|---------|-------|
| Migrations | 4 | 5 | 2 | 11 |
| Models | 2 | 4 | 2 | 8 |
| Services | 14 | 18 | 7 | 39 |
| Controllers | 2 | 3 | 4 | 9 |
| Jobs | 5 | 6 | 4 | 15 |
| Console Commands | - | 3 | 2 | 5 |
| Blade views | 4 | 6 | 8 | 18 |
| JavaScript modules | 3 | 2 | 3 | 8 |
| Routes (groups) | 8 endpoints | 5 endpoints | 6 endpoints | 19 |
| **Total file baru** | **~42** | **~47** | **~38** | **~127** |

---

## 8. Resiko & Catatan Implementasi

### Resiko Teknis

| Resiko | Mitigasi |
|--------|----------|
| FFmpeg encoding lambat di t3.medium | Pakai instance EC2 c-family untuk transcode worker terpisah, atau switch ke MediaConvert (pay per minute) |
| Bunny upload bottleneck untuk file 2GB+ | Pakai Bunny REST API multipart, parallelize upload chunks |
| pgvector query lambat di 10K+ embeddings | Tune `lists` parameter di ivfflat index, pakai HNSW index kalau pgvector >= 0.5 |
| Shaka Player + custom AES-128 = limited browser support | Test cross-browser E2E, fallback ke hls.js, force PWA install di iOS Safari |
| DeepSeek API outage | ProviderRouter auto-fallback ke Gemini Flash-Lite |
| Concurrent stream limit race condition | Pakai Redis `INCR` atomic, expire dengan `EXPIRE` |
| MaxMind GeoLite2 data outdated | Setup auto-update mingguan via cron |

### Catatan Coding

1. **Encrypt content keys at rest**: simpan di `drm_sessions.content_key` sebagai binary, tapi encrypt pakai `Crypt::encrypt()` (Laravel APP_KEY).
2. **APP_KEY rotation**: harus ada artisan command custom untuk re-encrypt semua `ai_providers.api_key` dan `drm_sessions.content_key` saat APP_KEY rotate.
3. **Audit trail**: semua admin action (movie upload, EZDRM toggle, AI provider change) wajib log ke `audit_logs`.
4. **Cost monitoring**: AI API spend tracker harus alert kalau > 80% budget. Cron daily check.
5. **Testing E2E**: minimal 1 happy path test untuk upload → transcode → play (Pest + Dusk).

---

## 9. Cara Menggunakan Dokumen Ini

1. **Dev senior**: baca [ARCHITECTURE.md](ARCHITECTURE.md) dulu untuk technical design depth.
2. **Project manager**: pakai dependency graph (§5) untuk Gantt chart.
3. **Implementation**: kerjakan urut Phase 1 → 2 → 3, jangan skip Phase 1 deps.
4. **Estimate**: total ~72 hari kerja senior dev = ~14-16 minggu kalender (matches 3-bulan budget kalau 1 dev FT + buffer).

---

## 10. AI Feature Catalog Lengkap (162 Fitur)

> **🎯 PROVIDER STRATEGY: DeepSeek V4 Flash sebagai DEFAULT untuk SEMUA text task.**
>
> DeepSeek V4 Flash text-only — 3 exception teknis wajib (audio, vision, embedding). Total ~95% AI spend tetap di DeepSeek.
>
> | Provider | Untuk | Mengapa Wajib |
> |----------|-------|---------------|
> | **DeepSeek V4 Flash** ($0.14/$0.28 per MTok) | Semua text: synopsis, tagging, translate, recommendation, chat, moderation, review, copy, dll | DEFAULT |
> | **OpenAI gpt-4o-mini-transcribe** ($0.003/min) | Audio transcription (subtitle, voice search, etc) | DeepSeek tidak punya audio model |
> | **Google Gemini 2.5 Flash-Lite** ($0.10/$0.40 per MTok) | Vision tasks (thumbnail, NSFW, X-Ray frame, scene) | DeepSeek tidak punya vision |
> | **OpenAI text-embedding-3-small** ($0.02 per 1M tok) | Semantic search vector embeddings | DeepSeek belum punya embedding API |
>
> Semua tabel di bawah: kalau model column tidak menyebut "vision/audio/embedding", **default = DeepSeek V4 Flash**. Eksplisit disebut hanya saat butuh capability lain.
>
> Catatan: Phase 2 cover ~16 fitur Wave 1, Phase 3 cover ~28 fitur Wave 2. Sisanya bertahap aktivasi post-launch tanpa nambah arsitektur — semua reuse `AiClient` service yang sama.

### 10.1 Kategori A — Content Intelligence (Catalog Enrichment)

Diproses **one-time per film** saat upload, hasil di-cache. Cost: per film, bukan per user.

| # | Fitur | Model | Cost/Film | Phase | Priority |
|---|-------|-------|-----------|-------|----------|
| A1 | **Auto-subtitle generation** | gpt-4o-mini-transcribe ($0.003/min) | ~$0.27 | 2 | 🔴 Critical |
| A2 | **Auto-tagging** (genre/mood/era/themes) | Claude Haiku + Gemini vision | ~$0.20 | 2 | 🔴 Critical |
| A3 | **Auto-synopsis generation** (editorial copy) | Claude Sonnet 4.6 | ~$0.30 | 2 | 🟡 High |
| A4 | **Thumbnail picker** (pick best frame) | Gemini Flash-Lite vision | ~$0.05 | 2 | 🟡 High |
| A5 | **Trailer auto-suggestion** (30-sec dramatic windows) | Gemini Flash vision | ~$0.20 | 3 | 🟢 Medium |
| A6 | **Auto-translation subtitle** (Indo→EN, Mandarin, Arab) | DeepSeek V4 Flash | ~$0.50 (3 lang) | 3 | 🟡 High |
| A7 | **Content quality classifier** (audio sync, visual issues) | DeepSeek V4 Flash + vision | ~$0.10 | 3 | 🟢 Medium |
| A8 | **Restoration priority ranking** | DeepSeek + metadata | ~$0.05 | 3 | 🔵 Optional |
| A9 | **Auto-chapter detection** (scene boundaries) | Gemini vision (keyframe analysis) | ~$0.30 | 4 | 🟢 Medium |
| A10 | **Face recognition** (auto-tag aktor) | Custom ML (or AWS Rekognition $1/1K img) | ~$0.50 | 4 | 🟢 Medium |
| A11 | **Music identification** (license alert) | ACRCloud API ($0.005/sec audio) | ~$0.27 | 4 | 🔵 Optional |
| A12 | **NSFW/violence content rating** | Gemini Flash vision (rated frames) | ~$0.20 | 4 | 🟡 High (compliance) |
| A13 | **Cultural sensitivity check** (term flagging untuk diaspora) | Claude Haiku | ~$0.10 | 4 | 🔵 Optional |
| A14 | **Color grading analysis** (restorasi hint) | Gemini vision | ~$0.10 | 4 | 🔵 Optional |

**Total one-time cost per film (semua 14 fitur)**: ~$3.10/film. Untuk 400 film = **~$1.240 (Rp 20jt)**. Kalau cuma critical (A1-A6): ~$1.50/film = $600 untuk 400 film.

### 10.2 Kategori B — Discovery & Recommendation

Diproses **per-user** (recommendation engine) atau **per-query** (search). Cost scales dengan MAU.

| # | Fitur | Model | Cost Pattern | Phase | Priority |
|---|-------|-------|--------------|-------|----------|
| B1 | **Personalized recommendations homepage** | DeepSeek V4 Flash batch nightly | $0.50/1K user/bln | 2 | 🔴 Critical |
| B2 | **Semantic search** ("film tentang petani jaman Belanda") | OpenAI embedding + DeepSeek refine | $0.30/1K user/bln | 2 | 🔴 Critical |
| B3 | **Similar movies** ("more like this") | pgvector cosine (no AI cost) | Free | 2 | 🔴 Critical |
| B4 | **Mood-based discovery** ("hari ini lagi mellow") | Gemini Flash-Lite + tags | $0.20/1K user/bln | 3 | 🟡 High |
| B5 | **Time-of-day recommendations** | Heuristic + DeepSeek rerank | $0.10/1K user/bln | 3 | 🟢 Medium |
| B6 | **Cold-start recommendations** (new user, no history) | DeepSeek V4 Flash + onboarding quiz | $0.05/new user | 3 | 🟡 High |
| B7 | **Continue watching prediction** | DeepSeek-reasoner | $0.10/1K user/bln | 3 | 🟢 Medium |
| B8 | **Hidden gem surfacing** (low-popularity, high-rated) | Custom logic + DeepSeek explanation | $0.05/1K user/bln | 4 | 🟢 Medium |
| B9 | **Trending detection** (aksi grup user, viral signal) | Custom ML + alert | $0.05/1K user/bln | 4 | 🟢 Medium |
| B10 | **Group viewing suggestions** (family vs solo profile) | DeepSeek personalization | $0.10/1K user/bln | 4 | 🔵 Optional |
| B11 | **Collaborative filtering** (user-user similarity) | Custom (matrix factorization) | Free (compute only) | 3 | 🟡 High |
| B12 | **Cross-language recommendation** (diaspora) | DeepSeek + language pref | $0.05/1K user/bln | 4 | 🔵 Optional |

**Total ongoing cost B (semua aktif) di 10K MAU**: ~$15/bulan = Rp 240rb. Negligible.

### 10.3 Kategori C — User Experience / Engagement

Triggered oleh user action, real-time atau near-real-time.

| # | Fitur | Model | Cost Pattern | Phase | Priority |
|---|-------|-------|--------------|-------|----------|
| C1 | **AI Chatbot Customer Support** | Groq Llama 4 Maverick (low latency) | $0.30/1K conversation | 3 | 🟡 High |
| C2 | **Voice search** (mic input, "cari Suzzanna") | gpt-4o-mini-transcribe + DeepSeek match | $0.005/query | 4 | 🟢 Medium |
| C3 | **AI movie summary** (spoiler-free, 3 sentences) | Claude Haiku 4.5 | ~$0.05/film one-time | 2 | 🟡 High |
| C4 | **Personalized email/notification copy** | DeepSeek V4 Flash | $0.30/1K email | 3 | 🟡 High |
| C5 | **AI-generated review comparison** ("apa kata kritik vs user") | Claude Sonnet | $0.20/1K query | 4 | 🔵 Optional |
| C6 | **Mood playlist generation** ("jumat malam santai") | DeepSeek V4 Flash | $0.10/1K user/bln | 4 | 🟢 Medium |
| C7 | **Smart resume position** (predict next session interest) | DeepSeek-reasoner | $0.10/1K user/bln | 4 | 🔵 Optional |
| C8 | **Watch party AI host** (real-time commentary in chat) | Groq Llama 4 + Gemini vision | $0.50/session | 4 | 🔵 Optional |
| C9 | **Multi-language UI translation** (auto for Indonesian dialects) | DeepSeek (one-time) | $0.05/language one-time | 4 | 🔵 Optional |
| C10 | **AI movie discussion bot** (per-film comment thread bot) | Claude Haiku | $0.10/active thread | 4 | 🔵 Optional |
| C11 | **Personalized opening pitch** (saat film mulai, "anda mungkin suka karena...") | DeepSeek V4 Flash | $0.05/playback | 4 | 🟢 Medium |

### 10.4 Kategori D — Admin / Operations Intelligence

Untuk tim admin & marketing. Ini **revenue-protecting** — banyak insight ekonomi.

| # | Fitur | Model | Cost Pattern | Phase | Priority |
|---|-------|-------|--------------|-------|----------|
| D1 | **Comment moderation** (toxic/spam/off-topic auto-flag) | Gemini Flash-Lite batch | $0.50/10K comments | 3 | 🔴 Critical |
| D2 | **Review sentiment analysis** | Gemini Flash-Lite | $0.10/1K reviews | 3 | 🟡 High |
| D3 | **Comment summarization** (admin overview per-film) | DeepSeek V4 Flash | $0.05/film weekly | 3 | 🟢 Medium |
| D4 | **Churn prediction** (user at risk of leaving) | Custom ML model + DeepSeek | $0.20/1K user/bln | 4 | 🔴 Critical |
| D5 | **Pricing optimization suggestion** | DeepSeek-reasoner + analytics data | $1/month one-time analysis | 4 | 🟡 High |
| D6 | **Marketing copy generation** (ads, social, blog) | Claude Sonnet 4.6 | $1/copy variant | 4 | 🟡 High |
| D7 | **Customer support email auto-reply draft** | DeepSeek V4 Flash | $0.10/ticket | 3 | 🟡 High |
| D8 | **Fraud detection** (account sharing pattern) | Custom rule + DeepSeek anomaly | $0.20/1K user/bln | 4 | 🟡 High |
| D9 | **SEO meta auto-generation per movie** | Claude Haiku | $0.05/film one-time | 3 | 🟡 High |
| D10 | **Social media post auto-generation** (per film/event) | Claude Sonnet + Gemini vision | $0.30/post | 4 | 🟢 Medium |
| D11 | **Press release writer** (untuk launch/milestone) | Claude Sonnet 4.6 | $2/release | 4 | 🔵 Optional |
| D12 | **A/B test result analyzer** | DeepSeek-reasoner | $0.50/test | 4 | 🟢 Medium |
| D13 | **Cohort analysis insights** (auto narrative dari numbers) | DeepSeek-reasoner | $1/month | 4 | 🟢 Medium |
| D14 | **Daily admin report auto-summary** (kirim ke Telegram/email) | DeepSeek V4 Flash | $0.20/day | 3 | 🟡 High |
| D15 | **Content gap analysis** (genre/era under-represented) | DeepSeek-reasoner + metadata | $0.50/month | 4 | 🟢 Medium |
| D16 | **Pricing page A/B variant generator** | Claude Sonnet | $1/variant | 4 | 🔵 Optional |

### 10.5 Kategori F — Smart Subtitle & Translation (Deep)

Subtitle bukan cuma generate + translate. Ada banyak deep feature yang user rasakan sekali pakai langsung addicted.

| # | Fitur | Model | Cost Pattern | Phase | Priority |
|---|-------|-------|--------------|-------|----------|
| F1 | **Multi-bahasa output** (Indo, EN, Mandarin, Arab, Jepang, Korea) | DeepSeek V4 Flash batch | $0.50/film/lang | 3 | 🟡 High |
| F2 | **Dialect translation** (Jawa/Sunda/Batak/Minang → Bahasa Indo) | Claude Sonnet (cultural nuance) | $1/film | 3 | 🟡 High (target market diaspora) |
| F3 | **Bilingual subtitle overlay** (2 bahasa simultaneously, learner mode) | DeepSeek V4 Flash | $0.05/playback (cached) | 4 | 🟢 Medium |
| F4 | **Karaoke mode** (highlight kata yang sedang diucapkan) | Whisper word-level timestamp | One-time per subtitle | 4 | 🟢 Medium |
| F5 | **Cultural footnote subtitle** (catatan slang/historis untuk diaspora) | Claude Sonnet (research) | $1/film | 4 | 🔵 Optional |
| F6 | **Profanity filter subtitle** (kid-safe version) | DeepSeek V4 Flash | $0.10/film one-time | 3 | 🟡 High (family plan) |
| F7 | **Forced narrative** (translate hanya bahasa asing/tulisan, bukan all dialog) | Claude Haiku | $0.20/film | 4 | 🟢 Medium |
| F8 | **Live real-time translation** (untuk content baru tanpa subtitle) | Whisper streaming + DeepSeek | $0.01/min playback | 4 | 🔵 Optional (heavy compute) |
| F9 | **Subtitle styling per genre** (horror = ngeri font, romance = elegant) | CSS template auto-pick | Free | 4 | 🔵 Optional |
| F10 | **Speaker identification** (siapa bicara di multi-character scene) | gpt-4o-transcribe + diarization | $0.005/min vs mini | 3 | 🟡 High |
| F11 | **Lip-sync correction** (subtitle perfect timed dengan mulut) | Audio analysis ML | One-time per film | 4 | 🔵 Optional |
| F12 | **Auto-spoiler removal** (hide subtitle saat plot twist) | Custom NLP + Claude | $0.30/film | 4 | 🔵 Optional |

### 10.6 Kategori G — Audio Intelligence

| # | Fitur | Model | Cost Pattern | Phase | Priority |
|---|-------|-------|--------------|-------|----------|
| G1 | **Audio enhancement** (noise reduction film jadul jelek) | Adobe Podcast / Krisp API | $0.05/min | 3 | 🟡 High (film klasik biasanya audio jelek) |
| G2 | **Voice cloning untuk dub** (preserve actor voice timbre) | ElevenLabs Voice Clone | $5/film one-time | 4 | 🔵 Optional (premium) |
| G3 | **Auto-dub multi-bahasa** (TTS dengan timing match) | ElevenLabs + ffmpeg mux | $30/film/lang | 4 | 🔵 Optional |
| G4 | **Background music separation** (vocal-only / music-only track) | Demucs / Spleeter (open source) | Free (compute) | 4 | 🟢 Medium |
| G5 | **Surround sound upmixing** (stereo → 5.1) | DeepRemaster / commercial | $50/film | 5 | 🔵 Future |
| G6 | **Music ID & royalty alert** (untuk konten dengan musik 3rd party) | ACRCloud | $0.005/sec | 4 | 🟡 High (compliance) |
| G7 | **Audio fingerprint anti-piracy** (track leak sources) | AudioFingerprint custom | $0.10/film | 4 | 🟢 Medium |
| G8 | **AI narrator untuk synopsis** (TTS untuk audio preview) | ElevenLabs / Azure TTS | $0.01/synopsis | 4 | 🔵 Optional |

### 10.7 Kategori H — Video Restoration & Enhancement

Sangat relevant untuk film klasik (target FLiK).

| # | Fitur | Model | Cost Pattern | Phase | Priority |
|---|-------|-------|--------------|-------|----------|
| H1 | **AI upscaling** (480p → 1080p / 4K) | Topaz Video AI / Real-ESRGAN | $5-20/film one-time | 4 | 🟡 High (film klasik biasanya rendah res) |
| H2 | **Auto-colorization** (B&W → color) | DeepRemaster / DeOldify | $10/film one-time | 5 | 🔵 Future (specialty) |
| H3 | **Frame interpolation** (24fps → 60fps smooth) | RIFE / DAIN | $3/film one-time | 4 | 🔵 Optional |
| H4 | **Aspect ratio fix** (4:3 → 16:9 smart crop dengan AI) | Topaz / custom | $2/film one-time | 4 | 🟢 Medium |
| H5 | **Scene re-lighting** (perbaiki scene gelap) | Custom ML | $5/film | 5 | 🔵 Future |
| H6 | **Logo/watermark removal** (broadcast era logo TVRI/RCTI) | Inpainting AI | $3/film one-time | 4 | 🟡 High |
| H7 | **AI video stabilization** (untuk handheld atau scan film tua) | Adobe / DaVinci script | $2/film | 4 | 🟢 Medium |
| H8 | **Scratch & dust removal** (film fisik scan defect) | Custom restoration | $5/film | 5 | 🔵 Future |
| H9 | **Auto-restoration scoring** (recommend mana yang worth di-restore) | Custom + Gemini vision | $0.10/film | 3 | 🟡 High |

### 10.8 Kategori I — Personalized UX (Netflix-style Deep)

| # | Fitur | Model | Cost Pattern | Phase | Priority |
|---|-------|-------|--------------|-------|----------|
| I1 | **Personalized poster art** (different poster variant per user taste) | Pre-generated variants + ranker | $1/film for variants | 4 | 🟡 High (Netflix-style) |
| I2 | **Personalized homepage layout** (rearrange rows per pref) | Custom ranker + DeepSeek | $0.10/1K user/bln | 3 | 🟡 High |
| I3 | **Personalized notification timing** (best time per user) | Custom ML + heuristics | $0.05/1K user/bln | 4 | 🟢 Medium |
| I4 | **Adaptive video quality prediction** (predict bandwidth per user) | Custom ML | Free (heuristic) | 3 | 🟡 High |
| I5 | **Personalized trailer cut** (different highlight per user interest) | Pre-cut + DeepSeek pick | $2/film | 4 | 🔵 Optional |
| I6 | **Personalized opening recap** ("sebelumnya yang kamu suka...") | DeepSeek V4 Flash | $0.05/playback | 4 | 🟢 Medium |
| I7 | **Year-in-review** ("tahun 2026 kamu nonton 142 film genre X paling banyak") | DeepSeek + analytics | $0.20/user yearly | 4 | 🟡 High (engagement gold) |
| I8 | **Smart watchlist priority** (urutkan watchlist by relevance now) | DeepSeek-reasoner | $0.05/user/bln | 4 | 🟢 Medium |
| I9 | **Mood tracking** (kapan user suka horror vs romance) | Custom ML | $0.05/1K user/bln | 4 | 🔵 Optional |

### 10.9 Kategori J — Smart In-Player Features

Real player-level intelligence yang Netflix punya (X-Ray, smart skip).

| # | Fitur | Model | Cost Pattern | Phase | Priority |
|---|-------|-------|--------------|-------|----------|
| J1 | **X-Ray-style actor info overlay** (klik aktor saat playing → bio) | Face recog + DB lookup | $0.50/film one-time setup | 3 | 🟡 High (signature feature) |
| J2 | **Auto-skip intro / outro** (learn pattern dari user) | Custom timestamp ML | $0.05/film one-time | 3 | 🟡 High (TV series) |
| J3 | **Auto-skip recap** (untuk multi-episode) | Audio fingerprint detection | $0.10/episode | 4 | 🟢 Medium |
| J4 | **Smart fast-forward** (skip slow scenes via AI summary) | Scene importance scoring | $0.50/film | 4 | 🔵 Optional |
| J5 | **Memorable quote extractor** (quote-to-share) | Whisper + Claude rank | $0.30/film one-time | 3 | 🟡 High (social share) |
| J6 | **Cliffhanger / ending detector** ("Lanjut episode berikutnya?") | Audio energy + Claude | $0.10/film | 3 | 🟡 High (binge driver) |
| J7 | **Movie trivia feed during pause** (auto-popup random fact) | Pre-generated facts via Claude | $1/film one-time | 4 | 🟢 Medium |
| J8 | **Scene chapter auto-marker** (clickable scene navigator) | Gemini vision keyframe | $0.30/film | 3 | 🟡 High |
| J9 | **Smart pause** (detect user away via webcam — opt-in) | TFJS browser face detection | Free (browser ML) | 4 | 🔵 Optional (privacy concern) |
| J10 | **Highlight reel auto-generator** (3-min recap of full film) | Scene importance + ffmpeg | $1/film one-time | 4 | 🟡 High |
| J11 | **Behind-the-scene auto-generator** (production trivia) | Web search + Claude synthesis | $1/film one-time | 4 | 🔵 Optional |
| J12 | **Music identification per scene** ("lagu ini judulnya apa?") | ACRCloud + UI | $0.005/query | 4 | 🟢 Medium |

### 10.10 Kategori K — Search Innovation

| # | Fitur | Model | Cost Pattern | Phase | Priority |
|---|-------|-------|--------------|-------|----------|
| K1 | **Voice search** ("cari film Suzzanna jaman 80an") | gpt-4o-mini-transcribe + DeepSeek match | $0.005/query | 3 | 🟡 High |
| K2 | **Image search** (upload screenshot, find that scene) | Gemini vision + embedding match | $0.05/query | 4 | 🟢 Medium |
| K3 | **Quote search** ("film dengan dialog 'merdeka atau mati'") | Subtitle DB + full text + DeepSeek | $0.01/query | 3 | 🟡 High |
| K4 | **"Similar feeling" search** (vibe matching, no keywords) | Embedding similarity | $0.001/query | 3 | 🟡 High |
| K5 | **Decade-style search** ("film 70an seperti X") | Embedding + decade filter | $0.01/query | 4 | 🟢 Medium |
| K6 | **Director/aktor style discovery** | Embedding + metadata | Free (precomputed) | 4 | 🟢 Medium |
| K7 | **Visual search by poster style** | Image embedding | $0.05/query | 4 | 🔵 Optional |

### 10.11 Kategori L — Accessibility Plus

| # | Fitur | Model | Cost Pattern | Phase | Priority |
|---|-------|-------|--------------|-------|----------|
| L1 | **Audio description** (narrate untuk blind viewer) | Gemini vision describe + ElevenLabs TTS | $25/film one-time | 4 | 🟢 Medium (compliance) |
| L2 | **Closed caption with speaker tag** (multi-character) | gpt-4o-transcribe diarization | +$0.003/min vs mini | 3 | 🟡 High |
| L3 | **BISINDO sign language overlay** (untuk diaspora tuli) | Custom ML library lookup | $50/film one-time | 5 | 🔵 Future |
| L4 | **Color-blind friendly thumbnail** | Image color shift | Free (algorithm) | 4 | 🔵 Optional |
| L5 | **Voice command UI control** (no-touch) | Whisper + intent classifier | $0.01/command | 4 | 🔵 Optional |
| L6 | **Reading-level adjusted synopsis** (kid/adult/scholar) | Claude Haiku | $0.10/film one-time | 4 | 🔵 Optional |
| L7 | **Real-time speech-to-text di chat** (deaf user di watch party) | gpt-4o-mini-transcribe stream | $0.005/min | 4 | 🔵 Optional |
| L8 | **Font size adaptive per user vision** | Heuristic + user history | Free | 4 | 🔵 Optional |

### 10.12 Kategori M — Marketing & Content Production Intelligence

| # | Fitur | Model | Cost Pattern | Phase | Priority |
|---|-------|-------|--------------|-------|----------|
| M1 | **AI-generated meme dari film** (untuk social media) | Gemini vision frame pick + Claude caption | $0.50/meme | 4 | 🟢 Medium |
| M2 | **TikTok/Reels clip suggestion** (15-30 sec viral cut) | Scene scoring + ffmpeg | $0.50/film | 4 | 🟡 High |
| M3 | **Localized landing page generator** (per region/diaspora) | Claude Sonnet | $5/region one-time | 4 | 🟢 Medium |
| M4 | **Press release writer** (launch / milestone) | Claude Sonnet | $2/release | 4 | 🔵 Optional |
| M5 | **Social media post auto-generator** (per film/event) | Claude Sonnet + Gemini vision | $0.30/post | 3 | 🟡 High |
| M6 | **AI cover art alternative generator** (poster variant via Stable Diffusion) | Replicate API SDXL | $0.10/variant | 4 | 🟢 Medium |
| M7 | **Trailer voice-over generator** (narration auto-dub) | ElevenLabs + script Claude | $5/trailer | 4 | 🔵 Optional |
| M8 | **Promo banner auto-generator** | DALL-E / Stable Diffusion | $0.10/banner | 4 | 🟢 Medium |
| M9 | **AI title alternative generator** (SEO-friendly variants) | Claude Haiku | $0.05/film | 3 | 🟡 High |
| M10 | **Email subject line A/B generator** | Claude Haiku | $0.05/campaign | 4 | 🟡 High |

### 10.13 Kategori N — Family / Profile Intelligence

| # | Fitur | Model | Cost Pattern | Phase | Priority |
|---|-------|-------|--------------|-------|----------|
| N1 | **Multi-profile auto-detection** (siapa yang nonton sekarang) | Browser fingerprint + behavior | Free | 3 | 🟡 High |
| N2 | **Kids safe mode** (auto-blur violence/adult scene) | Gemini vision realtime moderation | $0.01/min playback | 4 | 🟡 High (family plan) |
| N3 | **Age-appropriate auto-filter** (rating-aware) | Pre-tagged + filter | Free (use A12 output) | 3 | 🟡 High |
| N4 | **Family movie night recommendation** (consensus algorithm) | DeepSeek-reasoner | $0.10/recommendation | 4 | 🟢 Medium |
| N5 | **Parental control AI** (alert orang tua kalau anak nonton di luar jadwal) | Custom rule engine | Free | 4 | 🟢 Medium |
| N6 | **Co-viewing detection** (deteksi ada banyak orang di depan layar) | TFJS browser ML | Free | 4 | 🔵 Optional |

### 10.14 Kategori O — AI Reviewer & Deep Analysis

Konten kritis untuk niche film klasik — user butuh konteks budaya, sejarah, analisis sinematik. Ini differentiator vs Netflix/Disney+.

| # | Fitur | Model | Cost Pattern | Phase | Priority |
|---|-------|-------|--------------|-------|----------|
| O1 | **AI movie reviewer** (auto-generate full review multi-perspective: kritik, casual, family) | Claude Sonnet 4.6 | $0.30/film/perspective | 3 | 🟡 High |
| O2 | **Review aggregator + summarizer** ("apa kata 50 user lain") | DeepSeek V4 Flash batch | $0.10/film weekly | 3 | 🟡 High |
| O3 | **AI critic style mimicry** ("kalau Roger Ebert review film ini...") | Claude Sonnet (style transfer) | $0.20/review | 4 | 🔵 Optional fun |
| O4 | **Cross-platform review aggregator** (RT, Letterboxd, Filmaffinity, Twitter) | Web scrape + Claude summarize | $0.50/film weekly | 4 | 🟢 Medium |
| O5 | **Spoiler detection in user reviews** | Claude Haiku | $0.05/review | 3 | 🟡 High |
| O6 | **Review credibility scorer** (real vs astroturf) | Custom ML + DeepSeek anomaly | $0.05/review | 4 | 🟡 High (anti-fraud) |
| O7 | **AI character analysis** (deep dive per karakter on-demand) | Claude Sonnet | $0.30/request | 4 | 🟢 Medium |
| O8 | **AI plot explainer** (untuk ending kompleks/twist) | Claude Sonnet | $0.50/request | 4 | 🟡 High (signature) |
| O9 | **AI trivia generator post-watch** (8 fakta menarik) | Claude Haiku + web research | $0.20/film one-time | 3 | 🟡 High |
| O10 | **Theme analyzer across watched** ("kamu suka film tema X") | DeepSeek-reasoner | $0.10/user/bln | 4 | 🟢 Medium |
| O11 | **AI movie comparison** (Film A vs Film B side-by-side analysis) | Claude Sonnet | $0.50/comparison | 4 | 🟢 Medium |
| O12 | **Director auteur analysis** (style breakdown per sutradara) | Claude Sonnet | $1/director one-time | 4 | 🟢 Medium |
| O13 | **Cinematography style breakdown** (kamera, framing, lighting) | Gemini vision + Claude | $0.50/film | 4 | 🔵 Optional film school |
| O14 | **Soundtrack analyzer** (mood, era, composer style) | Audio analysis + Claude | $0.30/film | 4 | 🟢 Medium |
| O15 | **Costume & era authenticity check** (untuk historical film) | Gemini vision | $0.30/film | 4 | 🔵 Optional |
| O16 | **Filming location identifier** (lokasi syuting Indonesia) | Gemini vision + DB lookup | $0.20/film | 4 | 🟢 Medium (heritage value) |
| O17 | **Cultural impact summary** (legacy & influence film) | Claude Sonnet (research) | $1/film | 4 | 🟡 High (untuk film klasik) |
| O18 | **Award prediction analyzer** | DeepSeek-reasoner | $0.20/film | 5 | 🔵 Future |
| O19 | **History fact-checker** (untuk historical film) | Claude Sonnet + web | $0.50/film | 4 | 🟢 Medium |
| O20 | **AI essay writer** (1000-word essay tentang film, untuk content blog) | Claude Sonnet | $1/essay | 4 | 🟡 High (SEO content) |
| O21 | **Reading list / film pairing** ("film ini cocok dengan buku X") | Claude Sonnet | $0.10/recommendation | 4 | 🔵 Optional |
| O22 | **AI debate generator** (pro/con discussion topics) | Claude Sonnet | $0.30/film | 4 | 🔵 Optional |
| O23 | **Editing style analysis** (cut frequency, montage technique) | Custom ML + Claude | $0.50/film | 5 | 🔵 Future |
| O24 | **Scene-by-scene commentary track** (audio commentary AI-generated) | Claude script + ElevenLabs TTS | $20/film one-time | 5 | 🔵 Future premium |
| O25 | **Reviewer reputation system** (rank user reviewer based quality) | Custom ranking | $0.05/user/bln | 4 | 🟢 Medium |
| O26 | **AI moderator review feed** (curate top reviews per film) | DeepSeek-reasoner | $0.10/film weekly | 3 | 🟡 High |
| O27 | **Review reply auto-suggestion** (untuk discussion thread) | DeepSeek V4 Flash | $0.05/reply suggested | 4 | 🔵 Optional |
| O28 | **Star rating aggregator weighted** (bobot per review credibility) | Custom algorithm | Free | 3 | 🟡 High |

**Insight kategori O**: untuk niche film klasik Indonesia, fitur ini punya value tinggi karena audience punya curiosity intelektual. Differentiator vs Netflix yang generic.

---

## 10.15 Cost Recap (Detail)

**One-time investment** untuk full catalog (400 film, semua kategori A-N kecuali video restoration H — H sangat mahal):

| Tier | Estimasi Total |
|------|----------------|
| **Critical only** (~15 fitur Wave 1) | ~$1.000 (Rp 16 jt) |
| **Critical + High** (~35 fitur Wave 1+2) | ~$5.000 (Rp 80 jt) |
| **Comprehensive** (~55 fitur, skip video restoration heavy) | ~$15.000 (Rp 240 jt) |
| **Full + restoration** (semua, termasuk H1-H8) | ~$30.000+ (Rp 480 jt+) |

**Ongoing per skala** (semua kategori B+C+D+I+J+K+L+M+N aktif):
| MAU | Ongoing AI/bln | % Revenue (@ Rp 49rb pricing, 40% paid) |
|-----|---------------|----------------------------------------|
| 1.000 | ~$25 (Rp 400rb) | 2% |
| 10.000 | ~$250 (Rp 4 jt) | 2% |
| 100.000 | ~$2.500 (Rp 40 jt) | 2% |
| 1.000.000 | ~$25.000 (Rp 400 jt) | 2% |

> **Even with ALL features**: AI tetap **~2% revenue**. CDN tetap dominan. AI BUKAN cost driver.

---

## 10.16 Architectural Reuse — Kunci Skalabilitas Fitur

Kabar baik: walau ada 65+ fitur, infrastructure-nya sama:

```
1× AiClient                    (multi-provider abstraction)
1× ProviderRouter              (pick provider per task)
1× UsageTracker                (cost & quota tracking)
1× ai_providers table          (env-driven config)
1× ai_jobs table               (async job tracking)
1× ai_usage_logs table         (audit & analytics)
1× movie_embeddings table      (pgvector semantic)
1× movie_subtitles table       (multi-language subtitle)
N× Task classes                (1 per fitur, ~50-200 LoC each)
N× Job classes                 (1 per async fitur)
```

Per fitur baru = **1 Task + 1 Job + 0-1 Migration**. Average 0.5-2 hari untuk add fitur baru ke production setelah Phase 2 selesai.

**Implication untuk client**: kamu commit 15 critical fitur di Phase 2-3 (3 bulan build). Sisanya **bertahap aktivasi tiap minggu/bulan post-launch** sesuai feedback user. Tidak perlu commit semua dari awal.

---

## 10.17 Wave Activation Plan (Updated dengan 65+ Fitur)

### Wave 1 — Phase 2 Build (Bulan 2 — Critical 16 fitur)
**Catalog**: A1, A2, A3, A4, C3, J5, J6, J8 (per-film one-time)
**Discovery**: B1, B2, B3, B11
**UX**: I4
**Subtitle**: F10
**Search**: K3, K4

### Wave 2 — Phase 3 Build (Bulan 3 — High 24 fitur)
**Catalog**: A5, A6, A7, A12, F1, F2, F6, G1, H9, J1, J2, J10, M9
**Discovery**: B4, B6
**UX**: I2, I7
**Admin**: D1, D2, D7, D9, D14, M5, M10
**Family**: N1, N3
**Search**: K1
**Audio**: G6
**Accessibility**: L2

### Wave 3 — Post-Launch Bulan 4-6 (Operational 18 fitur)
**Catalog**: A9, A10
**UX**: I1, I3, I5, I6, I8
**Admin**: D3, D4, D5, D6, D8, D10
**Marketing**: M1, M2, M3, M6, M8
**Family**: N2, N4
**Search**: K2, K5, K6
**Player**: J3, J7, J11, J12
**Subtitle**: F3, F4, F7

### Wave 4 — Year 2 (Premium 18 fitur)
**Restoration**: H1, H4, H6, H7
**Audio**: G2, G3, G4, G7, G8
**Subtitle**: F5, F8, F9, F11, F12
**Player**: J4, J9
**Search**: K7
**Admin**: D11, D12, D13, D15, D16
**Marketing**: M4, M7
**Accessibility**: L1, L4, L5, L6, L7, L8
**Family**: N5, N6

### Wave 5 — Future (Specialty / R&D)
**Restoration**: H2 (colorization), H3, H5, H8
**Accessibility**: L3 (BISINDO sign language)
**Audio**: G5 (5.1 upmixing)

---

## 10.18 Provider Mapping (DeepSeek-First Strategy)

**Default**: SEMUA text task → DeepSeek V4 Flash. Vision/audio/embedding pakai exception minimal.

| Task Type | Provider | Cost | Reason |
|-----------|---------|------|--------|
| **Text generation** (synopsis, tagging, copy, translate, summary) | **DeepSeek V4 Flash** | $0.14/$0.28 per MTok | DEFAULT |
| **Reasoning** (recommendation, churn predict, deep analysis) | **DeepSeek V4 Flash** | Same | DEFAULT |
| **Real-time chat** (chatbot, conversation) | **DeepSeek V4 Flash** | Same | DEFAULT (latency 1-2s acceptable; kalau butuh ultra-low latency optional add Groq Llama 4) |
| **Editorial copy** (review writer, marketing, essay) | **DeepSeek V4 Flash** | Same | DEFAULT (quality cukup, beda tipis vs Claude Sonnet untuk niche use) |
| **Translation** (Indo→EN/Mandarin/Arab/dialect) | **DeepSeek V4 Flash** | Same | DEFAULT |
| **Moderation** (toxic/spam/off-topic comment) | **DeepSeek V4 Flash** | Same | DEFAULT |
| **Audio transcription** (subtitle, voice search) | **OpenAI gpt-4o-mini-transcribe** | $0.003/min | EXCEPTION — DeepSeek no audio |
| **Vision** (thumbnail pick, NSFW check, X-Ray frame analysis) | **Google Gemini 2.5 Flash-Lite** | $0.10/$0.40 per MTok | EXCEPTION — DeepSeek no vision |
| **Embeddings** (semantic search vectors) | **OpenAI text-embedding-3-small** | $0.02 per 1M tok | EXCEPTION — DeepSeek no embedding API |
| _Optional premium (kalau perlu nanti)_ | _Claude Sonnet 4.6_ | _$3/$15_ | Untuk editorial premium banget (skip dulu di Wave 1-3) |
| _Optional ultra-low latency_ | _Groq Llama 4 Maverick_ | _$0.15/$0.60_ | Untuk real-time chat kalau DeepSeek dirasa lambat (skip dulu) |
| _Optional image generation_ | _Replicate SDXL_ | _$0.10/img_ | Untuk poster variant generation Wave 4 |
| _Optional TTS_ | _ElevenLabs / Azure TTS_ | _Per minute_ | Untuk audio description Wave 4-5 |
| _Optional music ID_ | _ACRCloud_ | _$0.005/sec_ | Specialty Wave 3-4 |

### Implementasi di ProviderRouter

```php
// App\Services\Ai\ProviderRouter.php
class ProviderRouter
{
    public function selectFor(string $task, array $hint = []): AiProvider
    {
        // Hard exception: tasks yang HARUS pakai non-DeepSeek
        return match ($task) {
            'transcribe', 'voice_search', 'audio'
                => $this->getProvider('openai', 'gpt-4o-mini-transcribe'),

            'vision', 'thumbnail', 'nsfw_check', 'frame_analyze', 'x_ray', 'scene_detect'
                => $this->getProvider('google', 'gemini-2.5-flash-lite'),

            'embed', 'semantic_search'
                => $this->getProvider('openai', 'text-embedding-3-small'),

            // Default: SEMUA text task pakai DeepSeek V4 Flash
            default => $this->getProvider('deepseek', 'deepseek-v4-flash'),
        };
    }

    private function getProvider(string $providerName, string $modelName): AiProvider
    {
        return AiProvider::active()
            ->where('provider', $providerName)
            ->where('model', $modelName)
            ->orderBy('priority')
            ->firstOrFail();
    }
}
```

### Setup Awal di Admin Panel

User config 4 provider via `/admin/ai-settings`:

| Name | Provider | Model | Priority | API Key |
|------|----------|-------|----------|---------|
| DeepSeek Production | deepseek | deepseek-v4-flash | 1 (default) | [from DeepSeek] |
| OpenAI Audio | openai | gpt-4o-mini-transcribe | 50 | [from OpenAI] |
| Gemini Vision | google | gemini-2.5-flash-lite | 50 | [from Google AI Studio] |
| OpenAI Embedding | openai | text-embedding-3-small | 50 | [from OpenAI, can share key dengan Audio] |

Total 3 API key (OpenAI shared antara audio + embedding) yang perlu di-manage.

---

## 10.19 Kategori E — Accessibility (Original)

Penting untuk inclusivity & compliance.

| # | Fitur | Model | Cost Pattern | Phase | Priority |
|---|-------|-------|--------------|-------|----------|
| E1 | **Audio description for blind viewers** (auto-generate narration) | Gemini vision + TTS (ElevenLabs $0.30/min) | ~$25/film one-time | 4 | 🟢 Medium (compliance) |
| E2 | **Reading-level adjusted synopsis** (kids/adult/scholar version) | Claude Haiku | $0.10/film one-time | 4 | 🔵 Optional |
| E3 | **Sign language overlay** (BISINDO untuk diaspora tuli) | Custom ML + lookup library | $50/film one-time | 5 (future) | 🔵 Optional |
| E4 | **Color-blind friendly thumbnail variant** | Image processing | Free | 4 | 🔵 Optional |
| E5 | **Closed caption with speaker tags** (multi-speaker) | gpt-4o-transcribe (vs mini) | +$0.003/min vs mini | 3 | 🟢 Medium |

### 10.20 Recap Total Fitur AI — 162 Fitur

| Kategori | Jumlah | Fokus |
|----------|--------|-------|
| **A** Content Intelligence | 14 | Catalog enrichment per film |
| **B** Discovery & Recommendation | 12 | Personalized homepage & search |
| **C** UX / Engagement | 11 | User-facing AI features |
| **D** Admin / Operations Intel | 16 | Business intelligence |
| **F** Subtitle & Translation Deep | 12 | Multi-bahasa + dialect + accessibility |
| **G** Audio Intelligence | 8 | Restorasi audio film klasik |
| **H** Video Restoration | 9 | Upscale, color, frame interpolation |
| **I** Personalized UX (Netflix-style) | 9 | Dynamic poster, year-in-review |
| **J** Smart In-Player | 12 | X-Ray, smart skip, highlight reel |
| **K** Search Innovation | 7 | Voice/image/quote/vibe search |
| **L** Accessibility Plus | 8 | Audio desc, sign language, voice cmd |
| **M** Marketing Intelligence | 10 | Auto-content untuk social/SEO |
| **N** Family / Profile | 6 | Multi-user, parental, kids safe |
| **O** AI Reviewer & Analysis | 28 | Differentiator untuk niche film klasik |
| **TOTAL** | **162 fitur** | (range realistic: 70-90 yang akan diimplement) |

(Catatan: Beberapa fitur overlap antar kategori — counter di atas adalah jumlah unique fitur potensial, bukan jumlah Task class.)

---

## 10.21 Total Cost Recap (DeepSeek V4 Flash Default)

Karena pakai DeepSeek V4 Flash sebagai default (bukan Claude Sonnet/Haiku yang lebih mahal), cost turun **30-50%** dari estimasi sebelumnya.

**Investasi one-time** (process 400 film katalog):

| Tier | Fitur Termasuk | Cost USD | Cost Rupiah |
|------|---------------|----------|-------------|
| **Minimal** (Wave 1 Critical 16 fitur) | A1-A4, C3, J5, J6, J8, B1-B3, B11, F10, K3, K4, I4 | **~$700** | **Rp 11 jt** |
| **Standard** (Wave 1+2 = 44 fitur) | + Wave 2 (subtitle multi-bahasa, vision tasks, marketing copy, reviewer, etc) | **~$3.500** | **Rp 56 jt** |
| **Comprehensive** (Wave 1+2+3 = 74 fitur) | + Wave 3 (operational intel, advanced player, deep search) | **~$7.000** | **Rp 112 jt** |
| **Premium** (Wave 1-4 = 104 fitur, skip video restoration H) | Hampir semua kecuali H1-H8 | **~$13.000** | **Rp 208 jt** |
| **Full + Restoration** | Semua 162 termasuk H1-H8 + audio cloning + commentary | **~$45.000+** | **Rp 720 jt+** (heavy karena video restoration H1-H8) |

**Ongoing per skala** (Comprehensive tier dengan DeepSeek default):

| MAU | Ongoing AI/bln (USD) | Rupiah | % Revenue (Rp 49rb, 40% paid) |
|-----|---------------------|--------|------------------------------|
| 1.000 | **~$15** | **Rp 240rb** | 1.2% |
| 10.000 | **~$150** | **Rp 2,4 jt** | 1.2% |
| 100.000 | **~$1.500** | **Rp 24 jt** | 1.2% |
| 1.000.000 | **~$15.000** | **Rp 240 jt** | 1.2% |

**Per-provider breakdown** (di skala 100K MAU dengan Comprehensive tier):

| Provider | Cost/bln | % AI Spend | Function |
|----------|----------|------------|----------|
| **DeepSeek V4 Flash** | $1.350 | **90%** | Semua text task |
| OpenAI gpt-4o-mini-transcribe | $80 | 5% | Subtitle generation |
| Google Gemini 2.5 Flash-Lite | $50 | 3% | Vision tasks |
| OpenAI text-embedding-3-small | $20 | 2% | Semantic search |
| **TOTAL** | **$1.500** | 100% | |

> **Insight**: 90% AI spend di DeepSeek. Multi-provider hanya untuk capability gap (audio/vision/embedding) — minimal cost. Single API key dominant = simple cost prediction & monitoring.

> **vs estimasi awal**: dulu pakai mix Claude Sonnet (mahal $3/$15) + Gemini + DeepSeek = $3.000/bln di 100K. Sekarang $1.500/bln. **Save 50%**.

---

## 10.22 Wave Activation Plan (Updated — 162 Fitur)

### Wave 1 — Phase 2 Build (Bulan 2 — 16 fitur Critical)
- **A1-A4** (subtitle, tag, synopsis, thumbnail one-time per film)
- **B1, B2, B3, B11** (recommendation core)
- **C3** (movie summary)
- **F10** (speaker identification subtitle)
- **I4** (adaptive video quality)
- **J5, J6, J8** (memorable quotes, cliffhanger, scene chapters)
- **K3, K4** (quote search, vibe search)

### Wave 2 — Phase 3 Build (Bulan 3 — 28 fitur High)
- **Catalog**: A5, A6, A7, A12, F1, F2, F6, G1, H9, M9
- **Player smart**: J1 (X-Ray), J2 (skip intro), J10 (highlight reel)
- **Discovery**: B4, B6
- **UX**: I2, I7 (year-in-review)
- **Admin Intel**: D1, D2, D7, D9, D14, M5, M10
- **Reviewer**: O1, O2, O5, O9, O26, O28
- **Family**: N1, N3
- **Search**: K1 (voice search)
- **Audio**: G6 (music ID compliance)
- **Accessibility**: L2 (speaker tags)

### Wave 3 — Post-Launch Bulan 4-6 (~30 fitur Operational)
- **Catalog**: A9, A10
- **UX**: I1 (personalized poster), I3, I5, I6, I8
- **Admin**: D3, D4, D5, D6, D8, D10
- **Marketing**: M1, M2, M3, M6, M8
- **Reviewer**: O3, O4, O6, O7, O8, O10, O11, O20, O25
- **Family**: N2, N4
- **Search**: K2, K5, K6
- **Player**: J3, J7, J11, J12
- **Subtitle**: F3, F4, F7

### Wave 4 — Year 2 (~30 fitur Premium)
- **Restoration**: H1, H4, H6, H7
- **Audio**: G2, G3, G4, G7, G8
- **Subtitle**: F5, F8, F9, F11, F12
- **Player**: J4, J9
- **Search**: K7
- **Admin**: D11, D12, D13, D15, D16
- **Marketing**: M4, M7
- **Accessibility**: L1, L4, L5, L6, L7, L8
- **Family**: N5, N6
- **Reviewer**: O12, O13, O14, O15, O16, O17, O19, O21, O22, O27

### Wave 5 — Future / R&D (Specialty)
- **Restoration**: H2 (colorization), H3, H5, H8
- **Accessibility**: L3 (BISINDO sign language)
- **Audio**: G5 (5.1 upmixing)
- **Reviewer**: O18, O23, O24

---

## 10.23 Architectural Reuse — 1 Infrastructure, 162 Features

```
SAMA UNTUK SEMUA FITUR:
├── 1× AiClient (multi-provider abstraction)
├── 1× ProviderRouter (pick per task)
├── 1× UsageTracker (log to ai_usage_logs)
├── 1× ai_providers table (env-driven config)
├── 1× ai_jobs table (async tracking)
├── 1× ai_usage_logs table (audit & analytics)
├── 1× movie_embeddings table (pgvector)
├── 1× movie_subtitles table (multi-language)
├── Queue (ai-realtime + ai-batch supervisors)
└── Redis cache (24-hr AI response cache + nonce)

PER FITUR BARU:
├── 1× Task class di App\Services\Ai\Tasks\<Feature>.php (~50-200 LoC)
├── 1× Job class kalau async (~30 LoC)
└── 0-1× Migration kalau perlu store output (rare)
```

**Effort per fitur baru post-Phase 2**: **0.5-2 hari**.

**Implication**: Kamu commit 16 fitur Wave 1 di Phase 2 (Bulan 2). 28 fitur Wave 2 di Phase 3 (Bulan 3). Sisanya **bertahap aktivasi tiap minggu/bulan** sesuai prioritas product. **Tidak perlu commit semua dari awal**.

### Aktivasi fitur baru post-launch:
1. Add 1 Task class di `App\Services\Ai\Tasks\<Feature>.php`
2. Add 1 Job class kalau async
3. Add migration kalau perlu store output (rare)
4. Add admin trigger di `/admin/movies/{id}/ai/process` atau scheduled command
5. Test cost di staging dengan 10 film sample
6. Roll out batch ke production

Tidak perlu redeploy infra. Tidak perlu architectural change.

---

## 11. Payment — Midtrans (Sudah Terintegrasi)

**Status**: ✅ Sudah jalan di codebase. Tidak ada perubahan untuk Phase 1-3.

### 11.1 Existing Implementation

| Komponen | File | Status |
|----------|------|--------|
| Controller | `app/Http/Controllers/PaymentController.php` | Working |
| Webhook | `Route::post('/payment/webhook', ...)` (`routes/web.php`) | Working |
| Subscription model | `app/Models/Subscription.php` | Working |
| Plan model | `app/Models/SubscriptionPlan.php` | Working |
| Plan migration | `subscription_plans`, `subscriptions` tables | Done |
| Payment columns migration | `add_payment_columns_to_subscriptions` | Done |
| Frontend checkout | `resources/views/payment/` | Working |

### 11.2 Env-Driven Activation

```env
MIDTRANS_SERVER_KEY=         # kalau kosong → button checkout disabled
MIDTRANS_CLIENT_KEY=
MIDTRANS_IS_PRODUCTION=false
MIDTRANS_IS_SANITIZED=true
MIDTRANS_IS_3DS=true
```

`PaymentController::isEnabled()` cek `config('services.midtrans.server_key')`. Kalau kosong, tombol jadi "Coming Soon" otomatis.

### 11.3 Plan Tier yang Sudah Disiapkan

Lihat `database/seeders/SubscriptionPlanSeeder.php`. Bisa adjust pricing tier sesuai pitch deck (Basic Rp 39rb / Premium Rp 79rb / Family Rp 129rb).

### 11.4 Yang Perlu Ditambahkan untuk Production

| Task | Effort |
|------|--------|
| Production Midtrans key + sandbox-to-prod testing | 1 hari |
| Annual plan tier (diskon 20%) | 0.5 hari |
| Family plan dengan multi-profile feature | 1 hari |
| Failed payment retry flow | 0.5 hari |
| Cancellation flow (pro-rata refund optional) | 1 hari |
| Subscription expiry email reminder (3 hari + H-1) | 0.5 hari |
| Receipt PDF generator (pakai mPDF atau Browsershot) | 1 hari |
| **TOTAL Payment polish** | **~5 hari** (bisa paralel di Phase 3) |

### 11.5 Backup Payment Gateway (Opsional)

Untuk redundansi kalau Midtrans down:
- **Xendit** — alternative Indonesia, similar API
- **DOKU** — alternative Indonesia
- **OY!** — bank transfer focus

Tidak urgent. Bisa add nanti dengan adapter pattern (`PaymentGatewayInterface`).

---

## 12. Deliverables Setiap Akhir Phase

### Akhir Phase 1
- [x] 400 film bisa di-upload admin (master file)
- [x] Auto-transcode jadi 4 rendition (360p/480p/720p/1080p)
- [x] Encrypted dengan AES-128
- [x] Upload ke Bunny CDN
- [x] User bisa play via Shaka Player
- [x] Geo-blocking & concurrent stream limit working
- [x] Audit log terisi

### Akhir Phase 2
- [x] AI provider settings full CRUD (sudah)
- [x] AiClient bisa pakai 5+ provider
- [x] 400 film auto-tagged & embedded
- [x] Auto-subtitle generated untuk semua film
- [x] Semantic search live di UI
- [x] Recommendation feed personalized per user
- [x] AI usage dashboard di admin

### Akhir Phase 3
- [x] AI chatbot CS live di footer
- [x] Comment moderation otomatis
- [x] EZDRM toggle bisa aktif per-film
- [x] Audit log dashboard
- [x] Soft launch ke 100 beta user
- [x] Load test passed @ 5K concurrent

---

**End of Rancangan**

> Untuk depth design pattern, ER diagram, sequence flow → [ARCHITECTURE.md](ARCHITECTURE.md)
> Untuk konteks bisnis & cost → [PITCH_DECK.md](PITCH_DECK.md)
> Untuk konteks codebase existing → [CLAUDE.md](CLAUDE.md)
