# ARCHITECTURE — FLiK Technical Design

**Document untuk dev senior** yang akan eksekusi RANCANGAN.md. Berisi ER diagram, service class hierarchy, sequence diagram, security model, dan scaling strategy.

> Versi: 1.0 · Tanggal: 2026-05-10
> Companion: [RANCANGAN.md](RANCANGAN.md) (implementation plan) · [PITCH_DECK.md](PITCH_DECK.md) (business context) · [CLAUDE.md](CLAUDE.md) (codebase guide)

---

## Daftar Isi

1. [System Context](#1-system-context)
2. [Component Architecture](#2-component-architecture)
3. [Database Schema (ER)](#3-database-schema-er)
4. [Service Layer Hierarchy](#4-service-layer-hierarchy)
5. [Sequence Diagrams](#5-sequence-diagrams)
6. [Security Model](#6-security-model)
7. [Queue & Job Architecture](#7-queue--job-architecture)
8. [Caching Strategy](#8-caching-strategy)
9. [Performance & Scaling](#9-performance--scaling)
10. [Error Handling & Observability](#10-error-handling--observability)
11. [Testing Strategy](#11-testing-strategy)
12. [Deployment Architecture](#12-deployment-architecture)

---

## 1. System Context

```
┌─────────────────────────────────────────────────────────────────────┐
│                          EXTERNAL ACTORS                             │
│                                                                       │
│  [End User]    [Admin]    [Content Manager]    [Customer Support]   │
└──────────────────────────────┬──────────────────────────────────────┘
                               │ HTTPS
                               ▼
┌─────────────────────────────────────────────────────────────────────┐
│                       FLiK PLATFORM (Laravel)                        │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌────────┐ │
│  │  Public  │  │   Auth   │  │  Admin   │  │ Playback │  │  DRM   │ │
│  │  Routes  │  │  Routes  │  │  Panel   │  │  Engine  │  │ Server │ │
│  └──────────┘  └──────────┘  └──────────┘  └──────────┘  └────────┘ │
│                                                                       │
│  ┌──────────────────────────────────────────────────────────────┐   │
│  │                     SERVICE LAYER                              │   │
│  │  Storage · Transcoding · DRM · Geo · Audit · AI · Embedding   │   │
│  └──────────────────────────────────────────────────────────────┘   │
│                                                                       │
│  ┌──────────────────────────────────────────────────────────────┐   │
│  │                     QUEUE / JOBS                               │   │
│  │  Transcode · Encrypt · Upload · AI Tasks · Batch Recompute    │   │
│  └──────────────────────────────────────────────────────────────┘   │
└─────────────────┬─────────────────┬─────────────────┬───────────────┘
                  │                 │                 │
       ┌──────────▼──────┐  ┌───────▼──────┐  ┌──────▼─────────┐
       │ MySQL (RDS)     │  │ PostgreSQL   │  │  Redis         │
       │ - Core data     │  │ + pgvector   │  │ - Cache        │
       │ - User, Movie   │  │ - Embeddings │  │ - Queue        │
       │ - Subscription  │  │ - Search     │  │ - DRM session  │
       └─────────────────┘  └──────────────┘  └────────────────┘
                                                       │
       ┌─────────────────────────────────────────────┐ │
       │            EXTERNAL SERVICES                 │ │
       │                                              │ │
       │  [Bunny CDN]  [AWS S3]  [AI Providers]      │ │
       │  - Stream     - Master  - DeepSeek           │ │
       │  - Storage    - Backup  - OpenAI/Whisper     │ │
       │  - Pull Zone            - Anthropic/Gemini   │ │
       │                          - Groq              │ │
       │  [EZDRM*]    [Midtrans]  [MaxMind GeoLite2] │ │
       │  *optional   - Payment   - Geo lookup        │ │
       └──────────────────────────────────────────────┘ │
                                                       │
       ┌──────────────────────────────────────────────┐│
       │      MONITORING / OBSERVABILITY               ││
       │  Sentry · CloudWatch · AI Usage Tracker       ││
       └──────────────────────────────────────────────┘
```

---

## 2. Component Architecture

### 2.1 Bounded Contexts (DDD-lite)

| Context | Responsibility | Owns Tables |
|---------|---------------|-------------|
| **Catalog** | Movies, genres, casts, banners | `movies`, `genres`, `casts`, `genre_movie`, `cast_movie`, `banners` |
| **Identity** | Users, auth, profiles | `users`, `password_resets` |
| **Subscription** | Plans, billing, entitlement | `subscription_plans`, `subscriptions` |
| **Engagement** | Watchlist, ratings, comments, history | `watchlists`, `ratings`, `comments`, `watch_histories` |
| **Gamification** | XP, coins, achievements, levels | `user_levels`, `coins`, `achievements`, `user_achievements`, `notifications` |
| **Distribution** | Encoding, CDN, packaging | `encoding_jobs`, extends `movies` (encoding fields) |
| **DRM** | Key management, sessions, geo-block | `drm_sessions`, extends `movies` (drm fields) |
| **AI** | Provider config, usage, embeddings, AI jobs | `ai_providers`, `ai_jobs`, `ai_usage_logs`, `movie_embeddings`, `movie_subtitles` |
| **Audit** | Compliance trail | `audit_logs` |

### 2.2 Layer Responsibilities

```
┌─────────────────────────────────────────────────────┐
│  HTTP Layer                                          │
│  - Controllers (thin, validation + auth + delegate) │
│  - Form Requests (validation rules)                 │
│  - Resources (response shaping)                     │
└─────────────────────┬───────────────────────────────┘
                      │
┌─────────────────────▼───────────────────────────────┐
│  Service Layer                                       │
│  - Business logic                                    │
│  - Orchestration                                     │
│  - Idempotent (where possible)                       │
└─────────────────────┬───────────────────────────────┘
                      │
┌─────────────────────▼───────────────────────────────┐
│  Domain Layer                                        │
│  - Eloquent models (with scopes, relations)         │
│  - Value objects (e.g., DrmTokenPayload)            │
└─────────────────────┬───────────────────────────────┘
                      │
┌─────────────────────▼───────────────────────────────┐
│  Infrastructure Layer                                │
│  - Storage adapters (Bunny, S3)                     │
│  - HTTP clients (Bunny API, AI providers)           │
│  - Queue / Cache / DB                                │
└──────────────────────────────────────────────────────┘
```

---

## 3. Database Schema (ER)

### 3.1 Distribution + DRM Schema

```
┌──────────────────────┐
│ movies               │
├──────────────────────┤
│ id PK                │
│ title                │
│ original_title       │
│ overview             │
│ poster_path          │
│ backdrop_path        │
│ release_date         │
│ duration_seconds     │ ← NEW
│ master_file_path     │ ← NEW
│ master_file_disk     │ ← NEW
│ encoding_status      │ ← NEW (enum)
│ encoding_renditions  │ ← NEW (JSON)
│ hls_manifest_path    │ ← NEW
│ dash_manifest_path   │ ← NEW
│ cdn_disk             │ ← NEW
│ drm_strategy         │ ← NEW (enum)
│ drm_config           │ ← NEW (JSON)
│ geo_allow            │ ← NEW (JSON array)
│ ai_tags              │ ← NEW (JSON)
│ ai_metadata          │ ← NEW (JSON)
│ ai_processed_at      │ ← NEW
│ created_at           │
│ updated_at           │
└──────┬───────────────┘
       │ 1
       │
       │ ∞
┌──────▼───────────────┐         ┌──────────────────────┐
│ encoding_jobs        │         │ drm_sessions         │
├──────────────────────┤         ├──────────────────────┤
│ id PK                │         │ id PK                │
│ movie_id FK          │         │ user_id FK           │
│ status (enum)        │         │ movie_id FK          │
│ rendition_specs JSON │         │ session_token UQ     │
│ output_paths JSON    │         │ device_fingerprint   │
│ error_message TEXT   │         │ client_ip            │
│ progress_percent     │         │ country_code         │
│ started_at           │         │ content_key BLOB ENC │
│ completed_at         │         │ last_key_request_at  │
│ created_at/updated_at│         │ key_request_count    │
└──────────────────────┘         │ expires_at           │
                                  │ revoked_at           │
                                  │ created_at/updated_at│
                                  └──────────────────────┘
                                         │
                                         │ many sessions per (user, movie)
                                         │
                                  ┌──────▼─────────┐
                                  │ users          │
                                  └────────────────┘
```

### 3.2 AI Schema

```
┌──────────────────────┐         ┌──────────────────────┐
│ ai_providers         │         │ ai_usage_logs        │
├──────────────────────┤         ├──────────────────────┤
│ id PK                │ 1     ∞ │ id PK                │
│ name                 │─────────│ ai_provider_id FK    │
│ provider             │         │ task_type            │
│ model                │         │ subject_type         │
│ api_key (encrypted)  │         │ subject_id           │
│ base_url             │         │ input_tokens         │
│ settings JSON        │         │ output_tokens        │
│ is_active/is_default │         │ cost_usd DECIMAL     │
│ priority             │         │ latency_ms           │
│ last_used_at         │         │ cache_hit BOOL       │
│ total_tokens_used    │         │ success BOOL         │
│ total_cost_usd       │         │ error_message        │
│ created_at/updated_at│         │ created_at/updated_at│
└──────────────────────┘         └──────────────────────┘

┌──────────────────────┐         ┌──────────────────────┐
│ ai_jobs              │         │ movie_embeddings     │
├──────────────────────┤         ├──────────────────────┤
│ id PK                │         │ id PK                │
│ job_type             │         │ movie_id FK          │
│ subject_type         │         │ embedding_type       │
│ subject_id           │         │ model_name           │
│ status               │         │ dimensions           │
│ input_data JSON      │         │ embedding VECTOR(1536)│ ← pgvector
│ output_data JSON     │         │ created_at/updated_at│
│ error_message        │         └──────────────────────┘
│ started_at           │              UQ: (movie_id, embedding_type)
│ completed_at         │              IDX: ivfflat cosine_ops
│ created_at/updated_at│
└──────────────────────┘

┌──────────────────────┐
│ movie_subtitles      │
├──────────────────────┤
│ id PK                │
│ movie_id FK          │
│ language_code        │
│ label                │
│ webvtt_path          │
│ is_auto_generated    │
│ is_translated        │
│ source_language      │
│ created_at/updated_at│
└──────────────────────┘
       UQ: (movie_id, language_code)
```

### 3.3 Audit & Cross-Cutting

```
┌──────────────────────┐
│ audit_logs           │
├──────────────────────┤
│ id PK                │
│ user_id FK (NULL)    │
│ action (string)      │
│ subject_type         │
│ subject_id           │
│ client_ip            │
│ meta JSON            │
│ created_at/updated_at│
└──────────────────────┘
   IDX: (user_id, created_at)
   IDX: (subject_type, subject_id)
   IDX: action
```

### 3.4 Database Topology

| DB | Engine | Role | Tables |
|----|--------|------|--------|
| **`flik`** | MySQL 8 (RDS Multi-AZ) | Primary OLTP | All except embeddings |
| **`flik_vec`** | PostgreSQL 15 + pgvector | Vector search | `movie_embeddings` only |
| **`redis`** | ElastiCache | Cache + queue + DRM session counter | (no tables, key/value) |

### 3.5 Migration Order

Critical: pgvector migration HARUS dijalankan terhadap PostgreSQL connection, bukan default MySQL.

```php
// config/database.php — add pg connection
'pgsql_vec' => [
    'driver' => 'pgsql',
    'host' => env('DB_PG_HOST'),
    'database' => env('DB_PG_DATABASE'),
    // ...
]

// In migration
public function up()
{
    Schema::connection('pgsql_vec')->create('movie_embeddings', function ...);
}
```

---

## 4. Service Layer Hierarchy

### 4.1 Distribution Services

```
App\Services\Storage\
├── interface StorageContract { put, get, delete, presignedUrl }
├── BunnyStorageService implements StorageContract
│   - put(string $path, string $contents, array $headers = [])
│   - putStream(string $path, $resource)  // for large file
│   - delete(string $path)
│   - signedUrl(string $path, int $ttl)   // Bunny token auth
│   - publicUrl(string $path)
│   - listFiles(string $prefix)
└── S3StorageService implements StorageContract
    - similar API, wraps aws-sdk-php

App\Services\Transcoding\
├── FfmpegTranscoder
│   - transcode(string $inputPath, RenditionSpec $spec, string $outputPath): array
│   - probe(string $path): MediaInfo  // duration, codec, resolution
│   - extractKeyframe(string $path, int $second, string $outputPath)
├── AbrLadderBuilder
│   - build(MediaInfo $source): array<RenditionSpec>
│   // 360p/480p/720p/1080p with bitrate ladder optimal
├── HlsSegmenter
│   - segment(string $inputPath, int $segmentDuration, string $outputDir): array
└── TranscodingPipeline
    - run(Movie $movie, EncodingJob $job): void
    // Orchestrate: probe → ladder → transcode each → HLS segment → encrypt → upload

App\Services\Drm\
├── DrmKeyService
│   - generateContentKey(): string  // 16 bytes random
│   - storeForSession(DrmSession $session, string $key): void  // encrypt at rest
│   - retrieveForSession(string $sessionToken, string $keyId): ?string
│   - rotateKey(DrmSession $session): void
├── DrmTokenService (uses firebase/php-jwt)
│   - issuePlaybackToken(User $user, Movie $movie): PlaybackToken
│   - validatePlaybackToken(string $jwt): ?PlaybackTokenPayload
│   - issueKeyRequestToken(DrmSession $session, string $keyId): string
│   - validateKeyRequestToken(string $jwt): ?KeyTokenPayload
├── HlsEncryptor
│   - encryptSegments(string $hlsDir, string $contentKey, string $keyUrl): string
│   // Generate enc.keyinfo file, run FFmpeg encrypt pass
├── PlaybackManifestGenerator
│   - generateMaster(Movie $movie, PlaybackToken $token): string
│   - generateMedia(Movie $movie, RenditionSpec $rendition, PlaybackToken $token): string
│   // Inject signed segment URLs + signed key URL
├── DeviceFingerprinter
│   - validateClaim(array $clientFingerprint, DrmSession $session): bool
│   - hash(array $components): string
└── ConcurrentStreamLimiter (Redis-backed)
    - acquire(User $user, int $maxStreams): bool
    - release(User $user, string $sessionToken): void
    - currentCount(User $user): int

App\Services\Geo\
└── GeoIpResolver
    - country(string $ip): ?string
    - isAllowed(string $ip, array $allowList): bool

App\Services\Audit\
└── AuditLogger
    - log(string $action, ?Model $subject = null, array $meta = []): AuditLog
    // Auto-fill user_id from auth() and ip from request()
```

### 4.2 AI Services

```
App\Services\Ai\
├── AiClient (facade)
│   - chat(array $messages, ?string $task = null, array $options = []): ChatResponse
│   - embed(string $text, string $model = 'text-embedding-3-small'): array<float>
│   - transcribe(string $audioPath, string $language = 'id'): TranscribeResponse
│   - vision(string $imagePath, string $prompt): ChatResponse
├── ProviderRouter
│   - selectProvider(string $task, array $hint = []): AiProvider
│   // Logic: task → preferred provider → fallback by priority
├── UsageTracker
│   - track(AiProvider $p, string $task, int $inTok, int $outTok, ?int $latencyMs, bool $success, ?string $err = null): void
│   // Insert ai_usage_logs + update ai_providers totals
├── Providers/
│   ├── interface ProviderInterface { chat, embed, transcribe, vision, calculateCost }
│   ├── OpenAiCompatibleProvider
│   │   // Used by: OpenAI, DeepSeek, Groq, Mistral, OpenRouter, custom
│   │   - chat(): wraps POST /v1/chat/completions
│   │   - embed(): wraps POST /v1/embeddings
│   ├── AnthropicProvider extends BaseProvider
│   │   - chat(): wraps POST /v1/messages (Anthropic format)
│   ├── GeminiProvider extends BaseProvider
│   │   - chat(): wraps Gemini /v1beta/models/{model}:generateContent
│   │   - vision(): same with inline_data parts
│   └── OpenAiTranscriber
│       - transcribe(): POST /v1/audio/transcriptions (model gpt-4o-mini-transcribe)
└── Tasks/
    ├── SubtitleGenerator
    │   - generate(Movie $movie, string $lang = 'id'): MovieSubtitle
    │   // 1. Extract audio with FFmpeg → 2. Send to Whisper → 3. Save WebVTT to Bunny → 4. DB record
    ├── MovieTagger
    │   - tag(Movie $movie): array<tag>
    │   // Combine: poster vision (Gemini) + overview text (Claude Haiku) → merge tags
    ├── SynopsisGenerator
    │   - generate(Movie $movie, int $maxWords = 150): string
    │   // Claude Sonnet, polished editorial copy
    ├── ThumbnailPicker
    │   - pickBest(Movie $movie, int $candidateCount = 10): string
    │   // Extract 10 keyframes → Gemini vision rank → pick highest scoring
    ├── TrailerSuggester
    │   - suggest(Movie $movie): array<TimeRange>
    │   // Identify dramatic 30-sec windows from keyframe analysis
    ├── ContentClassifier
    │   - classify(Movie $movie): array
    │   // Rate: audio_sync, visual_quality, restoration_priority
    ├── SubtitleTranslator
    │   - translate(MovieSubtitle $source, string $targetLang): MovieSubtitle
    │   // DeepSeek for translation, preserve WebVTT timestamps
    ├── ChatbotResponder
    │   - respond(string $userMessage, array $conversationHistory, ?User $user = null): string
    │   // Groq Llama 4 for low latency, RAG with knowledge base
    └── CommentModerator
        - moderate(Comment $comment): ModerationResult
        // Gemini Flash-Lite batch, return: ok | spam | toxic | offtopic

App\Services\Ai\Embeddings\
├── EmbeddingService
│   - embedMovie(Movie $movie, string $type = 'combined'): MovieEmbedding
│   // Combine title + overview + ai_tags → embed via OpenAI text-embedding-3-small
│   - embedQuery(string $text): array<float>
└── SemanticSearchService
    - search(string $query, int $limit = 20): Collection<Movie>
    // 1. Embed query → 2. pgvector cosine search → 3. join Movie

App\Services\Ai\Recommendations\
├── RecommendationEngine
│   - computeFor(User $user): Collection<Movie>
│   // Hybrid: collaborative (user-user similarity) + content (vector similarity to watched)
│   // Output cached in Redis for 6 hours
└── SimilarityRanker
    - rerank(Collection $candidates, User $user): Collection<Movie>
```

---

## 5. Sequence Diagrams

### 5.1 Movie Upload → Encoding → Publish

```
Admin             AdminController        Storage         Job Queue        Pipeline           Bunny
  │                     │                  │               │                │                  │
  │── upload master ───>│                  │               │                │                  │
  │                     │── put master ────>│               │                │                  │
  │                     │                  │── PUT S3 ─────────────────────────────────────────│
  │                     │<─ ok ────────────│               │                │                  │
  │                     │                  │               │                │                  │
  │                     │── create EncodingJob (status=queued) ──> [DB]    │                  │
  │                     │── dispatch TranscodeMovie ──>│                   │                  │
  │<─── 202 Accepted ───│                  │               │                │                  │
  │                     │                  │               │                │                  │
  │                     │                  │               │── pop job ────>│                  │
  │                     │                  │               │                │── probe master ─>│
  │                     │                  │               │                │                  │
  │                     │                  │               │                │── transcode 4×  │
  │                     │                  │               │                │  (FFmpeg shell) │
  │                     │                  │               │                │                  │
  │                     │                  │               │                │── encrypt segs ─│
  │                     │                  │               │                │  (AES-128)      │
  │                     │                  │               │                │                  │
  │                     │                  │               │                │── upload renditions ──> Bunny
  │                     │                  │               │                │── upload manifest ────> Bunny
  │                     │                  │               │                │                  │
  │                     │                  │               │                │── update Movie.encoding_status='ready'
  │                     │                  │               │                │── audit log
  │                     │                  │               │                │                  │
  │── poll status ─────>│                  │               │                │                  │
  │                     │── read EncodingJob ──>│ [DB]                                          │
  │<─ status: ready ────│                  │                                                    │
```

### 5.2 User Playback Flow (Custom DIY DRM)

```
User Player          PlaybackCtrl       DrmTokenSvc    DrmKeyService   ConcurrentLimiter   Bunny CDN
    │                     │                 │              │                │                  │
    │── GET /playback/{movie}/config ──────>│              │                │                  │
    │   (with auth cookie)                  │              │                │                  │
    │                     │                 │              │                │                  │
    │                     │── check sub active ──> [Sub]   │                │                  │
    │                     │                 │              │                │                  │
    │                     │── acquire ─────────────────────────────────────>│                  │
    │                     │<─ ok (count=2) ─────────────────────────────────│                  │
    │                     │                 │              │                │                  │
    │                     │── createSession DrmSession (token, fingerprint, IP, geo)            │
    │                     │── DrmKeyService.generate ─────>│                                    │
    │                     │<─ contentKey (16 bytes) ──────│                                    │
    │                     │── store encrypted key ────────>│ [DB]                               │
    │                     │                 │              │                │                  │
    │                     │── DrmTokenService.issue ──────>│                                    │
    │                     │<─ JWT (sub=user, mov=id, exp=10min, sess=token) ────────────────────│
    │                     │                 │              │                │                  │
    │<─ 200 {manifestUrl, keyUrlTemplate, jwt, fingerprint} ─                                   │
    │                                                                                            │
    │── GET /playback/{movie}/manifest.m3u8?token=<jwt> ──>│                                   │
    │                     │── validate JWT ─>│                                                  │
    │                     │── generate dynamic m3u8 with signed segment URLs + key URL ─        │
    │<─ m3u8 ─────────────│                                                                     │
    │                                                                                            │
    │── parse m3u8, GET segments via signed URL ──────────────────────────────────────────────>│
    │<─ encrypted .ts segments (cached at edge) ───────────────────────────────────────────────│
    │                                                                                            │
    │── GET /drm/key/{sessionToken}/{keyId}?token=<keyJwt> ──>                                  │
    │                     │── validate keyJwt ──>│                                              │
    │                     │── DrmKeyService.retrieve ───>│                                      │
    │                     │<─ contentKey ──────────────│                                        │
    │                     │── update last_key_request_at, increment counter                     │
    │                     │── audit log                                                         │
    │<─ 200 binary AES key (16 bytes) ──────│                                                  │
    │                                                                                            │
    │── decrypt segments locally → playback                                                      │
    │                                                                                            │
    │── POST /playback/{movie}/heartbeat (every 30s)─>│                                         │
    │                     │── validate JWT ──>│        │                                         │
    │                     │── update session activity, check device fingerprint match          │
    │                     │   if mismatch → revoke session, increment limit                    │
    │<─ 200 ──────────────│                                                                     │
```

### 5.3 AI Subtitle Generation (Background Job)

```
Trigger          AiTaskCtrl       Job Queue      SubtitleGenerator     FFmpeg     OpenAI Whisper     Bunny
   │                 │                │                  │                │              │              │
   │── POST /admin/movies/{m}/ai/process?task=subtitle ─>│                │              │              │
   │                 │                │                  │                │              │              │
   │                 │── create AiJob (job_type=subtitle.generate, status=queued)        │              │
   │                 │── dispatch GenerateMovieSubtitle ──>                                              │
   │<─ 202 ──────────│                │                  │                │              │              │
   │                 │                │                  │                │              │              │
   │                 │                │── pop ──────────>│                │              │              │
   │                 │                │                  │── update AiJob status=processing             │
   │                 │                │                  │                │              │              │
   │                 │                │                  │── extract audio ──>│ ffmpeg -i master.mp4    │
   │                 │                │                  │                  │            -vn -acodec libmp3lame
   │                 │                │                  │<─ audio.mp3 ─────│              │              │
   │                 │                │                  │                │              │              │
   │                 │                │                  │── upload audio temp ────────────────────────>│
   │                 │                │                  │<─ presigned URL (Bunny temp) ────────────────│
   │                 │                │                  │                │              │              │
   │                 │                │                  │── transcribe (gpt-4o-mini-transcribe, lang=id) ──>│
   │                 │                │                  │                │              │── HTTP request
   │                 │                │                  │                │              │   POST /audio/transcriptions
   │                 │                │                  │<─────────────────────────────│              │
   │                 │                │                  │     {text, segments[]}        │              │
   │                 │                │                  │                                              │
   │                 │                │                  │── format to WebVTT ───────────│              │
   │                 │                │                  │── upload .vtt to Bunny ───────────────────────>│
   │                 │                │                  │── insert MovieSubtitle row                   │
   │                 │                │                  │── update AiJob status=completed              │
   │                 │                │                  │── UsageTracker.track (cost = $0.27)         │
   │                 │                │                  │── audit log                                  │
   │                                                                                                     │
   │── poll /admin/movies/{m}/ai/status ─>│                                                              │
   │<─ {subtitle: ready, cost: $0.27} ────│                                                              │
```

---

## 6. Security Model

### 6.1 Authentication & Authorization

| Layer | Mechanism |
|-------|-----------|
| **Web auth** | Laravel session (cookie, encrypted) |
| **Admin gate** | `Gate::define('admin', fn $u => $u->is_admin)` |
| **DRM key endpoint** | Dual-token: session JWT + key request JWT |
| **AI provider API key** | `encrypted` cast in DB (AES-256-CBC via APP_KEY) |
| **Bunny upload** | API key in env, server-side only |
| **DRM content key at rest** | Encrypted via `Crypt::encrypt()` before DB insert |

### 6.2 DRM Token Structure (JWT)

**Playback Token** (issued at /playback/{movie}/config):
```json
{
  "iss": "flik",
  "aud": "playback",
  "sub": "user_id",
  "movie_id": 123,
  "session_id": "drm_session_token",
  "fp": "device_fingerprint_hash",
  "exp": 1715260800,
  "iat": 1715260200,
  "jti": "unique_nonce"
}
```
- TTL: 10 menit
- Signed: HS256 dengan `APP_KEY`
- Used for: manifest request, heartbeat

**Key Request Token** (signed inside manifest URL):
```json
{
  "iss": "flik",
  "aud": "drm-key",
  "sub": "user_id",
  "session_id": "drm_session_token",
  "key_id": "key_uuid",
  "exp": 1715260800,    ← short, 5 menit
  "iat": 1715260500,
  "jti": "unique_nonce"
}
```

### 6.3 Defense Layers

```
1. Network Layer
   - HTTPS only (HSTS)
   - WAF: rate limit aggressive (60 req/min per IP)
   - Cloudflare/Bunny edge: DDoS protection

2. Application Layer
   - Auth middleware on all playback routes
   - Rate limiter per user on /drm/key (120 req/min per user)
   - CSP headers (script-src self, no inline eval)
   - CSRF on all state-changing forms

3. Token Layer
   - Short TTL JWTs (5-10 min)
   - Nonce-based anti-replay (jti tracked in Redis 1hr)
   - Device fingerprint binding

4. Session Layer
   - Concurrent stream limit (Redis counter)
   - Geo-block check before key issue
   - Session revocation on suspicious activity

5. Data Layer
   - All sensitive at-rest encryption (api_key, content_key)
   - Audit log for every key issuance
   - APP_KEY rotation procedure (custom artisan)

6. Player Layer
   - DevTools detector (anti-debug)
   - HTTPS-only fetch
   - No key caching in localStorage
```

### 6.4 Threat Model

| Threat | Mitigasi |
|--------|----------|
| Token theft (XSS) | HttpOnly cookie session, JWT in Authorization header (not localStorage) |
| Token replay | Nonce (jti) tracked in Redis, single-use |
| Key extraction from memory | Short key TTL (5 min rotation), session-bound |
| Account sharing | Concurrent limit + device fingerprint mismatch detection |
| VPN bypass geo | Combine GeoIP + ASN check + behavioral signals |
| Bulk download | Rate limit per session, watermark forensik |
| Replay segments offline | Segment URL signed with short TTL (15 min), key required to decrypt |
| Inside attacker (admin) | Audit log every action, separate read/write admin role |

---

## 7. Queue & Job Architecture

### 7.1 Queue Topology

```
Redis (Default Queue Driver)
├── default              (sync, low priority)
├── transcoding          (heavy CPU, dedicated worker)
├── ai-realtime          (low latency, fast worker pool)
├── ai-batch             (cheap, daily/nightly cron)
├── notifications        (email, push)
└── audit                (fire-and-forget)
```

### 7.2 Job Catalog

| Job | Queue | Avg Duration | Frequency |
|-----|-------|--------------|-----------|
| `TranscodeMovie` | transcoding | 30-90 min/film | On admin upload |
| `EncryptHlsSegments` | transcoding | 5-15 min | Chained after Transcode |
| `UploadToBunny` | transcoding | 2-10 min | Chained after Encrypt |
| `GenerateMovieSubtitle` | ai-realtime | 1-5 min | On admin trigger |
| `TagMovie` | ai-realtime | 30s | On admin trigger |
| `EmbedMovie` | ai-realtime | 5s | On movie create/update |
| `BatchRecomputeRecommendations` | ai-batch | 30 min | Daily 02:00 WIB |
| `TranslateSubtitles` | ai-realtime | 2-5 min/lang | After SubtitleGenerator |
| `ModerateComment` | ai-realtime | 1-2s | On Comment::created |
| `RotateDrmKey` | default | <1s | Hourly |
| `CleanupExpiredDrmSessions` | default | <30s | Hourly |

### 7.3 Worker Configuration

```ini
# Laravel Horizon config (config/horizon.php)
'environments' => [
    'production' => [
        'transcoding-supervisor' => [
            'connection' => 'redis',
            'queue' => ['transcoding'],
            'maxProcesses' => 2,        // CPU heavy, limit
            'memory' => 1024,
            'timeout' => 7200,          // 2 hours per job
            'tries' => 2,
        ],
        'ai-realtime-supervisor' => [
            'connection' => 'redis',
            'queue' => ['ai-realtime'],
            'maxProcesses' => 8,
            'memory' => 256,
            'timeout' => 600,
            'tries' => 3,
        ],
        'ai-batch-supervisor' => [
            'connection' => 'redis',
            'queue' => ['ai-batch'],
            'maxProcesses' => 1,
            'memory' => 512,
            'timeout' => 3600,
            'tries' => 1,
        ],
        'default-supervisor' => [
            'queue' => ['default', 'notifications', 'audit'],
            'maxProcesses' => 4,
            'memory' => 256,
            'timeout' => 60,
            'tries' => 3,
        ],
    ],
],
```

### 7.4 Scheduled Tasks

**File**: `app/Console/Kernel.php`
```php
protected function schedule(Schedule $schedule)
{
    // Daily AI recompute
    $schedule->job(new BatchRecomputeRecommendations())
        ->dailyAt('02:00')->onQueue('ai-batch');

    // Hourly DRM cleanup
    $schedule->job(new CleanupExpiredDrmSessions())->hourly();

    // Hourly key rotation
    $schedule->job(new RotateDrmKey())->hourly();

    // Daily AI cost alert (if > 80% budget)
    $schedule->command('flik:ai:cost-check')->dailyAt('08:00');

    // Weekly MaxMind GeoLite2 update
    $schedule->command('flik:geo:update')->weeklyOn(1, '03:00');
}
```

---

## 8. Caching Strategy

### 8.1 Cache Layers

| Layer | Engine | TTL | Use Case |
|-------|--------|-----|----------|
| **Edge cache** | Bunny CDN | Permanent (versioned URL) | Video segments, posters, static assets |
| **Manifest cache** | Bunny CDN | 30s | HLS/DASH manifests |
| **Session cache** | Redis | Session lifetime | User profile, subscription status |
| **Recommendation cache** | Redis | 6 jam | Per-user recommendations |
| **Search embedding cache** | Redis | Permanent (until movie update) | Pre-computed query embeddings |
| **AI response cache** | Redis | 24 jam | LLM responses for identical prompts |
| **Geo lookup cache** | Redis | 24 jam | IP → country mapping |
| **DRM session counter** | Redis | Session TTL | Concurrent stream tracking |

### 8.2 Cache Keys Convention

```
flik:user:{user_id}:profile               TTL session
flik:user:{user_id}:subscription          TTL 5min
flik:user:{user_id}:recommendations       TTL 6hr
flik:movie:{movie_id}:manifest            TTL 30s
flik:movie:{movie_id}:embeddings          TTL until updated
flik:drm:session:{token}:counter          TTL 10min (heartbeat extends)
flik:drm:user:{user_id}:concurrent_count  TTL session
flik:geo:{ip}                             TTL 24hr
flik:ai:response:{hash(prompt)}           TTL 24hr
flik:ai:nonce:{jti}                       TTL 1hr  (anti-replay)
```

### 8.3 Cache Invalidation

```php
// On movie update → invalidate caches
Movie::updated(function ($movie) {
    Cache::forget("flik:movie:{$movie->id}:manifest");
    Cache::forget("flik:movie:{$movie->id}:embeddings");

    // Invalidate recommendations for users who watched this
    $watcherIds = $movie->watchHistories()->pluck('user_id')->unique();
    foreach ($watcherIds as $uid) {
        Cache::forget("flik:user:{$uid}:recommendations");
    }
});
```

---

## 9. Performance & Scaling

### 9.1 Bottleneck Analysis per Skala

| Skala | Bottleneck | Solusi |
|-------|-----------|--------|
| 1K MAU | Initial transcoding setup | One-time pain, automate with queue |
| 10K MAU | DB connection pool | RDS `max_connections` tuning, PgBouncer for PostgreSQL |
| 100K MAU | DRM key endpoint hot path | Add Redis caching layer for content keys |
| 100K MAU | Recommendation computation | Pre-compute nightly, serve from Redis |
| 1M MAU | Single-region latency | Multi-region read replica, edge functions for auth |
| 1M MAU | CDN egress cost | Multi-CDN + Indonesia local peering |

### 9.2 Database Indexes (Critical)

```sql
-- DRM key endpoint (hottest)
CREATE INDEX idx_drm_sessions_token_active ON drm_sessions(session_token, expires_at) WHERE revoked_at IS NULL;

-- Concurrent limit query
CREATE INDEX idx_drm_sessions_user_active ON drm_sessions(user_id, expires_at) WHERE revoked_at IS NULL;

-- Audit log queries
CREATE INDEX idx_audit_user_action_time ON audit_logs(user_id, action, created_at);
CREATE INDEX idx_audit_subject ON audit_logs(subject_type, subject_id);

-- Encoding job polling (admin status check)
CREATE INDEX idx_encoding_movie_status ON encoding_jobs(movie_id, status);

-- AI job dashboard
CREATE INDEX idx_ai_jobs_status_time ON ai_jobs(status, created_at);
CREATE INDEX idx_ai_usage_provider_time ON ai_usage_logs(ai_provider_id, created_at);

-- Vector search (pgvector)
CREATE INDEX movie_embeddings_vector_idx ON movie_embeddings
  USING ivfflat (embedding vector_cosine_ops) WITH (lists = 100);
-- Tune lists = sqrt(rows). For 100K embeddings: lists = 316
```

### 9.3 Horizontal Scaling Path

```
Skala: 1K MAU
   1× Web app (t3.medium)
   1× DB (db.t3.small Multi-AZ)
   1× Redis (t3.micro)
   1× Worker (sama instance)

Skala: 10K MAU
   2× Web app (t3.large) + ALB
   1× DB (db.t3.medium Multi-AZ + read replica)
   1× Redis (t3.small)
   2× Worker (dedicated EC2 t3.large)

Skala: 100K MAU
   8× Web app (t3.xlarge auto-scale) + ALB
   1× DB (db.r5.2xlarge Multi-AZ + 2 read replicas)
   1× pgvector (db.r5.large)
   3× Redis cluster
   8× Worker auto-scale (transcode pool 4, AI pool 4)
   3× OpenSearch nodes

Skala: 1M MAU
   30+× Web app (auto-scale 10-60)
   1× Aurora MySQL cluster (writer + 5 readers)
   1× Aurora PostgreSQL (writer + 1 reader)
   10+× Redis cluster (sharded)
   20+× Worker auto-scale
   6× OpenSearch
   Edge: multi-region read replicas
```

---

## 10. Error Handling & Observability

### 10.1 Error Boundaries

| Layer | Strategy |
|-------|----------|
| Controller | Laravel exception handler → JSON with proper status, Sentry capture |
| Service | Bubble up domain exceptions, let controller decide HTTP code |
| Job | Retry with exponential backoff (3 attempts), failed jobs to `failed_jobs` table, Sentry alert |
| External API (AI provider) | Provider router auto-fallback, log to ai_usage_logs |
| FFmpeg | Capture stderr, save to encoding_jobs.error_message, mark failed for retry |

### 10.2 Logging Strategy

```php
// Structured logging via Laravel logger
Log::channel('drm')->info('Key issued', [
    'user_id' => $user->id,
    'movie_id' => $movie->id,
    'session_token' => $session->session_token,
    'ip' => request()->ip(),
    'country' => $country,
]);

// channels: drm, encoding, ai, security, audit
```

### 10.3 Metrics to Track

| Metric | Frequency | Alert if |
|--------|-----------|----------|
| Encoding queue depth | 1 min | > 10 jobs pending |
| DRM key endpoint latency p95 | 1 min | > 200ms |
| AI provider error rate | 5 min | > 5% |
| AI daily spend | 1 hr | > 80% budget |
| Concurrent active streams | 1 min | > capacity threshold |
| DB connection pool usage | 1 min | > 80% |
| Bunny CDN error rate | 5 min | > 1% |
| Failed jobs count | 5 min | > 5 in last hour |

### 10.4 Sentry Integration

- Web errors: all exceptions in production
- Job failures: capture failed job exceptions
- Performance: trace slow controllers (> 1s)
- Custom breadcrumbs: DRM session activities

---

## 11. Testing Strategy

### 11.1 Test Pyramid

```
            ┌──────────────┐
            │   E2E (5%)   │  Dusk + Pest
            │  Happy paths │  Upload → play
            └──────────────┘
          ┌──────────────────┐
          │ Integration (25%) │  PHPUnit Feature
          │  Controller tests │  Job execution
          └──────────────────┘
       ┌────────────────────────┐
       │      Unit (70%)         │  PHPUnit Unit
       │  Services, helpers      │  Pure logic
       └────────────────────────┘
```

### 11.2 Critical Test Cases

```
tests/Feature/Distribution/
├── PlaybackFlowTest.php
│   - test_user_with_active_subscription_can_get_playback_config
│   - test_user_without_subscription_gets_402
│   - test_geo_blocked_user_gets_451
│   - test_concurrent_limit_enforced
│   - test_drm_key_endpoint_validates_jwt
│   - test_expired_session_revoked
├── TranscodingPipelineTest.php
│   - test_master_upload_creates_encoding_job
│   - test_pipeline_generates_4_renditions
│   - test_failed_transcode_marks_job_failed
└── DrmTokenTest.php
    - test_token_validates_signature
    - test_token_rejects_expired
    - test_nonce_prevents_replay

tests/Feature/Ai/
├── AiClientTest.php
│   - test_provider_router_picks_default_for_unknown_task
│   - test_fallback_to_secondary_when_primary_fails
│   - test_usage_tracker_writes_log
├── SubtitleGeneratorTest.php
│   - test_subtitle_creates_webvtt_and_db_record
│   - test_subtitle_handles_provider_error
└── SemanticSearchTest.php
    - test_search_returns_top_k_by_cosine

tests/Unit/Services/Drm/
├── DrmKeyServiceTest.php
├── DrmTokenServiceTest.php
├── DeviceFingerprinterTest.php
└── ConcurrentStreamLimiterTest.php
```

### 11.3 Mocking Strategy

```php
// Mock external services in tests
$this->instance(BunnyStorageService::class, Mockery::mock(BunnyStorageService::class)
    ->shouldReceive('put')->andReturn(true)
    ->getMock());

// AI provider mock for cost-free tests
$this->instance(AiClient::class, new AiClient([
    'mock' => true,
    'mockResponses' => ['chat' => 'mocked response'],
]));
```

---

## 12. Deployment Architecture

### 12.1 Environments

| Env | Purpose | Infra |
|-----|---------|-------|
| `local` | Dev workstation | Laravel Sail (Docker) |
| `staging` | Pre-prod testing | Single EC2 + RDS small |
| `production` | Live | Full stack per skala (lihat §9.3) |

### 12.2 CI/CD Pipeline (GitHub Actions)

```yaml
.github/workflows/deploy.yml
on:
  push:
    branches: [main]

jobs:
  test:
    - Composer install
    - npm install
    - npm run build
    - vendor/bin/pint --test
    - vendor/bin/phpstan analyse
    - vendor/bin/phpunit
    - vendor/bin/pest

  build:
    needs: test
    - docker build -t flik:${{ github.sha }} .
    - push to ECR

  deploy-staging:
    needs: build
    - aws ecs update-service --cluster staging --service flik
    - run migrations
    - smoke test

  deploy-prod:
    needs: deploy-staging
    if: manual approval
    - aws ecs update-service --cluster prod --service flik
    - run migrations
    - cloudfront invalidate
```

### 12.3 Secret Management

```
AWS Secrets Manager (or .env in EC2 SSM Parameter Store)
├── /flik/prod/app/key                        → APP_KEY
├── /flik/prod/db/password                    → DB password
├── /flik/prod/bunny/storage_key              → Bunny upload key
├── /flik/prod/midtrans/server_key            → Midtrans
├── /flik/prod/ai/openai/key                  → OpenAI
├── /flik/prod/ai/deepseek/key                → DeepSeek (override DB)
├── /flik/prod/ezdrm/credentials              → EZDRM (optional)
└── /flik/prod/maxmind/license                → MaxMind
```

Laravel reads via `aws-secrets-manager` package or boots from SSM into env on container start.

### 12.4 Backup Strategy

| Asset | Frequency | Retention |
|-------|-----------|-----------|
| RDS automated snapshots | Daily | 7 days |
| RDS manual snapshots | Weekly | 30 days |
| Master video files (S3) | Versioning enabled | Indefinite |
| Bunny encoded outputs | Re-generatable from master | N/A |
| App secrets | Versioned in Secrets Manager | Indefinite |
| Audit logs | Daily export to S3 Glacier | 7 years (compliance) |

### 12.5 Disaster Recovery

| Scenario | RTO | RPO | Recovery |
|----------|-----|-----|----------|
| Single EC2 fail | 5 min | 0 (Multi-AZ) | ALB health check + auto-scale |
| RDS fail | 5 min | 5 min | Multi-AZ failover automatic |
| Region fail | 4 hr | 1 hr | Restore from cross-region snapshot, DNS swap |
| Bunny CDN outage | 0 | 0 | Failover to AWS S3 + CloudFront (degraded UX) |
| EZDRM outage (if used) | N/A | N/A | Auto-fallback to DIY DRM (degradation only on iOS Safari) |

---

## End of Architecture

> Implementation plan & timeline: [RANCANGAN.md](RANCANGAN.md)
> Business context & cost: [PITCH_DECK.md](PITCH_DECK.md)
> Codebase orientation: [CLAUDE.md](CLAUDE.md)
