# PROGRESS REPORT — FLiK Implementation

> Last update: **2026-05-10** · Tracking against [RANCANGAN.md](RANCANGAN.md) + [ARCHITECTURE.md](ARCHITECTURE.md)
> Legend: ✅ Done · 🚧 In Progress · ⏳ Todo · ⏸ Blocked · 🔵 Optional/Future

---

## 📊 Overall Status

| Phase | Plan | Actual Status | % |
|-------|------|--------------|---|
| **Phase 0** (Pre-pitch foundation) | Web/admin/payment/gamification/PWA | ✅ Done | 100% |
| **Phase 1** (Distribution Layer) | Transcoding + DRM + CDN | ⏳ **Not started** | 0% |
| **Phase 2** (AI Core) | AiClient + RAG + subtitle + tagging + search | 🚧 **Partial** (chatbot+RAG done, others todo) | 40% |
| **Phase 3** (Polish + Features) | Chatbot + moderation + EZDRM + audit | 🚧 **Partial** (chatbot done) | 30% |
| **Bonus Tracks** (out of plan) | Roles, slug, multi-asset, hover UI, etc | ✅ Done | 100% |

**Summary**: kita sudah bangun **AI layer foundation lengkap (RAG + tool calling + chatbot)** + **UI overhaul yang besar**, tapi **distribution layer (transcoding, DRM, CDN) belum mulai sama sekali**. Tanpa Phase 1 → film tidak bisa di-stream proper di production.

---

## ✅ Phase 0 — Foundation (Pre-Pitch, Already Built)

| Item | Status | Notes |
|------|--------|-------|
| Laravel 12 + PHP 8.2 setup | ✅ | Upgraded from L9 |
| Auth (email/password + Google OAuth) | ✅ | Socialite |
| Admin panel CRUD (movies, genres, casts, users, banners) | ✅ | `AdminController` |
| Payment Midtrans integration | ✅ | Env-gated, Snap |
| Subscription plans + auto-activation | ✅ | Free plan auto-activate |
| Gamification (XP, level, coins, achievements) | ✅ | Daily check-in 7-day cycle |
| Watchlist + ratings + comments (nested) | ✅ | |
| Watch history + resume playback | ✅ | `last_watched_at` tracking |
| PWA + service worker | ✅ | manifest.json |
| NativePHP Mobile (Android wrapper) | ✅ | `./native` script |
| Newsletter (Mailchimp env-gated) | ✅ | |

---

## ⏳ Phase 1 — Distribution Layer (CRITICAL — Not Started)

**Tanpa ini, film TIDAK BISA di-stream secara aman di production.** Currently semua pakai YouTube embed atau direct video file (no DRM, no CDN, no encryption).

### 1.1 Database Migrations
- [ ] ⏳ `2026_05_xx_extend_movies_for_distribution` — encoding fields, drm_strategy, hls/dash manifest paths
- [ ] ⏳ `create_encoding_jobs_table` — track transcoding job status
- [ ] ⏳ `create_drm_sessions_table` — DRM session per user/movie
- [ ] ⏳ `create_audit_logs_table` — admin action audit trail

### 1.2 Storage Services
- [ ] ⏳ `App\Services\Storage\BunnyStorageService` — upload, signed URL, list
- [ ] ⏳ `App\Services\Storage\S3StorageService` — backup master files

### 1.3 Transcoding Pipeline
- [ ] ⏳ `App\Services\Transcoding\FfmpegTranscoder` — FFmpeg wrapper
- [ ] ⏳ `App\Services\Transcoding\AbrLadderBuilder` — generate 360p/480p/720p/1080p ladder
- [ ] ⏳ `App\Services\Transcoding\HlsSegmenter` — chunk to .ts segments
- [ ] ⏳ `App\Services\Transcoding\TranscodingPipeline` — orchestrator (probe → ladder → encrypt → upload)

### 1.4 DRM Stack (DIY — Hybrid with EZDRM Optional)
- [ ] ⏳ `App\Services\Drm\DrmKeyService` — generate/store/rotate AES-128 content keys
- [ ] ⏳ `App\Services\Drm\DrmTokenService` — JWT issue/validate untuk session
- [ ] ⏳ `App\Services\Drm\HlsEncryptor` — encrypt segments via FFmpeg
- [ ] ⏳ `App\Services\Drm\PlaybackManifestGenerator` — dynamic m3u8 with signed URLs
- [ ] ⏳ `App\Services\Drm\DeviceFingerprinter` — browser canvas+WebGL hash validation
- [ ] ⏳ `App\Services\Drm\ConcurrentStreamLimiter` — Redis counter per user
- [ ] 🔵 `App\Services\Drm\EzDrmAdapter` — optional toggle per-film (Widevine + PlayReady + FairPlay)
- [ ] 🔵 `App\Services\Drm\ForensicWatermarker` — burn-in user ID overlay

### 1.5 Geo & Audit
- [ ] ⏳ `App\Services\Geo\GeoIpResolver` — MaxMind GeoLite2 lookup
- [ ] ⏳ `App\Services\Audit\AuditLogger` — log to audit_logs table

### 1.6 Playback HTTP
- [ ] ⏳ `PlaybackController::config(Movie)` — return manifest URL + JWT token
- [ ] ⏳ `PlaybackController::manifest(Movie)` — generate dynamic m3u8
- [ ] ⏳ `PlaybackController::key(token, keyId)` — serve binary AES key
- [ ] ⏳ `PlaybackController::heartbeat(Movie)` — keep session alive
- [ ] ⏳ `MovieUploadController` — chunked upload + start transcode

### 1.7 Queue Jobs
- [ ] ⏳ `App\Jobs\TranscodeMovie` — dispatch pipeline
- [ ] ⏳ `App\Jobs\EncryptHlsSegments` — chained after transcode
- [ ] ⏳ `App\Jobs\UploadToBunny` — chained after encrypt
- [ ] ⏳ `App\Jobs\RotateDrmKey` — scheduled hourly
- [ ] ⏳ `App\Jobs\CleanupExpiredDrmSessions` — scheduled hourly

### 1.8 CDN — Bunny.net
- [ ] ⏳ Sign up Bunny account (Stream Library + Pull Zone + Storage Zone)
- [ ] ⏳ Add ENV: `BUNNY_STORAGE_ZONE`, `BUNNY_STORAGE_KEY`, `BUNNY_PULL_ZONE_URL`, etc
- [ ] ⏳ Test upload → CDN delivery flow

### 1.9 Frontend Player
- [ ] ⏳ Replace Video.js with **Shaka Player** (DRM-aware)
- [ ] ⏳ `resources/js/player/flik-player.js` — wrapper class with heartbeat, fingerprint, DevTools detector
- [ ] ⏳ Integrate ke `movies/show.blade.php`

### 1.10 Admin Upload UI
- [ ] ⏳ Admin form upload master (chunked, resumable optional)
- [ ] ⏳ Encoding status polling UI (progress bar)

### 1.11 Microservices (Future Optional)
- [ ] 🔵 Separate transcoding worker (EC2 c5.xlarge dedicated, scale independent)
- [ ] 🔵 Separate DRM key server (high availability, low latency)
- [ ] 🔵 Separate AI worker pool (GPU-backed kalau pakai vision/audio heavy)

**Estimated effort Phase 1**: ~22 hari kerja (1 senior dev FT). Required untuk production launch.

---

## 🚧 Phase 2 — AI Core Layer (40% Done)

### 2.1 AI Foundation ✅ DONE
- [x] ✅ `App\Models\AiProvider` — multi-provider config dengan encrypted API key
- [x] ✅ `App\Services\Ai\AiClient` — multi-provider abstraction (OpenAI/Anthropic/DeepSeek/Gemini/Groq compatible)
- [x] ✅ Function calling support (OpenAI-compatible tool use)
- [x] ✅ Usage tracking (last_used_at, total_tokens_used)
- [x] ✅ Admin UI `/admin/ai-settings` (CRUD, encrypted at rest)

### 2.2 RAG Infrastructure 🚧 PARTIAL
- [x] ✅ `App\Services\Ai\FilmKnowledgeService` — keyword-based RAG, search relevant films
- [x] ✅ Full catalog whitelist (slug→title) untuk anti-hallucination
- [x] ✅ Closest-match film finder (untuk fix invalid AI links)
- [ ] ⏳ Migration: `create_movie_embeddings_table` (pgvector) untuk semantic RAG
- [ ] ⏳ `App\Services\Ai\Embeddings\EmbeddingService` — OpenAI text-embedding-3-small
- [ ] ⏳ `App\Services\Ai\Embeddings\SemanticSearchService` — pgvector cosine similarity
- [ ] ⏳ Backfill embeddings untuk 35 existing film

### 2.3 Web Search Tool ✅ DONE
- [x] ✅ `App\Services\Ai\WebSearchService` — Wikipedia + DuckDuckGo (no API key)
- [x] ✅ Cached 1hr per query
- [x] ✅ DeepSeek agentic tool calling integration

### 2.4 Chatbot ✅ DONE
- [x] ✅ `ChatController::respond()` — RAG + agentic loop + post-flight validation
- [x] ✅ Strict scope guardrails (system prompt + post-flight)
- [x] ✅ Link validator (anti-404 from AI hallucination)
- [x] ✅ Floating chatbot widget UI (Alpine, markdown render, typing indicator)
- [x] ✅ Conversation history (last 10 turns)
- [x] ✅ Rate limit 20 msg/min/user

### 2.5 Subtitle Pipeline ⏳ TODO (User specifically requested)
- [ ] ⏳ `App\Services\Ai\Tasks\SubtitleGenerator` — extract audio with FFmpeg → gpt-4o-mini-transcribe → save WebVTT
- [ ] ⏳ `App\Services\Ai\Tasks\SubtitleTranslator` — DeepSeek translate Indo→EN/Mandarin/Arab/Jepang
- [ ] ⏳ Migration: `create_movie_subtitles_table` (movie_id, lang_code, webvtt_path, is_translated, source_lang)
- [ ] ⏳ Job `App\Jobs\GenerateMovieSubtitle`
- [ ] ⏳ Job `App\Jobs\TranslateSubtitles` (per language)
- [ ] ⏳ Admin trigger: button "Generate Subtitle" + "Translate to..." per movie
- [ ] ⏳ Player: subtitle selector dropdown (multi-language)

### 2.6 Auto-Tagging ⏳ TODO
- [ ] ⏳ `App\Services\Ai\Tasks\MovieTagger` — Claude Haiku + Gemini vision (poster + overview)
- [ ] ⏳ Migration: extend `movies` with `ai_tags` JSON column
- [ ] ⏳ Job `App\Jobs\TagMovie` + bulk console command

### 2.7 Synopsis & Thumbnail ⏳ TODO
- [ ] ⏳ `App\Services\Ai\Tasks\SynopsisGenerator` — Claude Sonnet polished editorial copy
- [ ] ⏳ `App\Services\Ai\Tasks\ThumbnailPicker` — Gemini vision rank keyframes
- [ ] ⏳ `App\Services\Ai\Tasks\TrailerSuggester` — identify dramatic 30s windows
- [ ] ⏳ Console command `flik:ai:process-catalog` untuk bulk all 350 film

### 2.8 Recommendations ⏳ TODO
- [ ] ⏳ `App\Services\Ai\Recommendations\RecommendationEngine` — DeepSeek batch nightly
- [ ] ⏳ Job `App\Jobs\BatchRecomputeRecommendations` (scheduled 02:00 daily)
- [ ] ⏳ Cache results in Redis 6hr per user
- [ ] ⏳ `RecommendationController::forUser` endpoint

### 2.9 AI Usage Dashboard ⏳ TODO
- [ ] ⏳ Migration: `create_ai_usage_logs_table` + `create_ai_jobs_table`
- [ ] ⏳ `App\Services\Ai\UsageTracker` — write detailed logs per call
- [ ] ⏳ Admin page `/admin/ai-usage` — chart spend by provider/task, table of recent calls

**Estimated remaining Phase 2**: ~14 hari kerja.

---

## 🚧 Phase 3 — Polish & Features (30% Done)

### 3.1 AI Chatbot Customer Support ✅ DONE
- [x] ✅ Sudah di Phase 2 — chatbot floating widget dengan RAG + scope strict

### 3.2 Comment Moderation ⏳ TODO
- [ ] ⏳ `App\Services\Ai\Tasks\CommentModerator` — Gemini Flash-Lite batch (toxic/spam/off-topic)
- [ ] ⏳ Job `App\Jobs\ModerateComment` triggered on `Comment::created`
- [ ] ⏳ Auto-hide flagged + admin review queue

### 3.3 EZDRM Optional Toggle ⏳ TODO (Hybrid DRM)
- [ ] ⏳ Sign up EZDRM account (atau trial)
- [ ] ⏳ `App\Services\Drm\EzDrmAdapter`
- [ ] ⏳ Per-movie toggle UI di admin (DIY vs EZDRM)
- [ ] ⏳ Manifest packaging dengan multi-DRM (Widevine + PlayReady + FairPlay)

### 3.4 Audit Log Dashboard ⏳ TODO
- [ ] ⏳ `App\Http\Controllers\Admin\AuditLogController`
- [ ] ⏳ Admin UI `/admin/audit-logs` dengan filter by user/action/date

### 3.5 Resumable Upload (TUS) ⏳ TODO
- [ ] ⏳ Install `ankitpokhrel/tus-php`
- [ ] ⏳ Frontend `tus-js-client`
- [ ] ⏳ Integrate ke MovieUploadController

### 3.6 Email Personalization ⏳ TODO
- [ ] ⏳ `App\Services\Ai\Tasks\EmailPersonalizer` — DeepSeek tailor copy per user segment
- [ ] ⏳ Integrate ke Mailchimp campaigns

### 3.7 Fraud Detection ⏳ TODO
- [ ] ⏳ Account sharing pattern detector (geo, IP, device)
- [ ] ⏳ Auto-flag + notify user

### 3.8 Backup Payment Gateway 🔵 Optional
- [ ] 🔵 `PaymentGatewayInterface` adapter pattern
- [ ] 🔵 Add Xendit / DOKU / OY! sebagai fallback

**Estimated remaining Phase 3**: ~16 hari kerja.

---

## 🎉 Bonus Tracks — Out of Original Plan (DONE)

Selesai walaupun tidak ada di [RANCANGAN.md](RANCANGAN.md) original — request user ad-hoc:

| Item | Commit |
|------|--------|
| ✅ Multi-role auth system (5 roles) | `d9f2e66` |
| ✅ Movie slug routing (`/movie/the-avengers`) | `08b7094` |
| ✅ Multi-thumbnail rotation system (`movie_assets` table) | `08b7094` |
| ✅ Slider_path column (cinematic 2.4:1) | `08b7094` |
| ✅ Gold UI overhaul + reusable `<x-icon>` library | `a4a3b8a` |
| ✅ Header navbar redesign (gold elegant, no emoji) | `a4a3b8a` |
| ✅ Layout auto-include `<x-header />` | `a4a3b8a` |
| ✅ Gamification UI revamp | `a4a3b8a` |
| ✅ Plans page redesign + Bulanan/Tahunan toggle | `a4a3b8a` |
| ✅ 9anime-hub home layout (3/4 + 1/4 split) | `5dd30cf` |
| ✅ Hero slider auto-rotate carousel | `5dd30cf` |
| ✅ Quick filter sidebar (collapsible mobile) | `5dd30cf` |
| ✅ Top 10 sidebar (Today/Week/Month tabs) | `5dd30cf` |
| ✅ A-Z navigation | `5dd30cf` |
| ✅ Hover preview cards (Netflix "Bob" style) | `5dd30cf` |
| ✅ Edge detection JS (smart popover position) | `5dd30cf` |
| ✅ AI chatbot widget + RAG + tool calling | `ddc2669` |
| ✅ DeepSeek V4 Flash agentic web search | `ddc2669` |
| ✅ Anti-hallucination link validator | `ddc2669` |
| ✅ Strict scope guardrails (7 few-shot examples) | `ddc2669` |
| ✅ Comprehensive README.md | `8cfb590` |
| ✅ deploy.sh script | `293a027` |
| ✅ Tailwind safelist (failsafe grid classes) | `293a027` |
| ✅ Global image fallback (data URI SVG) | `a4a3b8a` |
| ✅ Fix Alpine double-init | `a4a3b8a` |
| ✅ Fix double-bcrypt seeder bug | `8a54718` |

---

## 🎯 New Scope Items (User Requested)

Yang kamu sebut perlu ditambah:

### A. Subtitle Translator ⭐ HIGH PRIORITY
**Goal**: Film bisa punya multiple subtitle languages, AI generate & translate.

- [ ] ⏳ Migration: `create_movie_subtitles_table` (lihat 2.5 di atas)
- [ ] ⏳ `SubtitleGenerator` (Whisper/gpt-4o-mini-transcribe extract audio → WebVTT Indonesia)
- [ ] ⏳ `SubtitleTranslator` (DeepSeek V4 Flash translate ke target lang, preserve VTT timestamps)
- [ ] ⏳ Languages: Indonesia (default), English, Mandarin, Arabic, Japanese, Korean
- [ ] ⏳ Admin UI: button "Generate Subtitle" + dropdown "Translate to → [Indonesia/English/...]" per film
- [ ] ⏳ Player: subtitle track selector di Video.js / Shaka
- [ ] ⏳ Cost: ~$0.27 generate + $0.50/lang translate per film. 350 film × 3 lang = ~$700 one-time

### B. Dialect Translator 🔵 NICE-TO-HAVE
- [ ] 🔵 Bahasa daerah → Bahasa Indonesia (Jawa, Sunda, Batak, Minang)
- [ ] 🔵 Cultural footnote (slang, historical reference)

### C. Profanity Filter Subtitle (Kid-Safe) 🔵 (Family plan feature)
- [ ] 🔵 `SubtitleProfanityFilter` — DeepSeek V4 Flash strip/replace profanity
- [ ] 🔵 Toggle per-user "Kid-safe mode"

### D. Live Subtitle Translation 🔵 FUTURE
- [ ] 🔵 Real-time translation untuk content tanpa subtitle (heavy compute)

### E. AI Dub (Voice Cloning) 🔵 FUTURE / EXPENSIVE
- [ ] 🔵 ElevenLabs voice clone preserve actor timbre
- [ ] 🔵 TTS multi-language dubbing (~$30/film/lang)
- [ ] 🔵 **User confirmed**: "yang teks dulu aja yang mungkin di implement, untuk gambar2 bisa nanti aja" → SKIP for now

---

## 🔥 What to Build Next — Recommended Priority

### Sprint 1 (Bulan ini — 2 minggu)
1. ✅ ~~AI chatbot dengan RAG~~ DONE
2. **Subtitle Generator + Translator** (yang baru kamu request) — 3-4 hari
3. Migration foundations untuk Phase 1 distribution (encoding_jobs, drm_sessions, audit_logs) — 1 hari
4. AI Usage tracking dashboard di admin — 2 hari

### Sprint 2 (2 minggu berikutnya) — Distribution Foundation
5. Bunny CDN integration + storage service — 2 hari
6. FFmpeg transcoding pipeline (basic ABR ladder) — 3 hari
7. DIY DRM Tier 1 (HLS AES-128 + key server) — 4 hari
8. Shaka Player frontend integration — 2 hari

### Sprint 3 — AI Bulk Processing
9. Auto-tagging pipeline + bulk run 350 film
10. Auto-thumbnail picker (vision)
11. pgvector embeddings + semantic search

### Sprint 4 — Polish + Compliance
12. Comment moderation pipeline
13. Audit log dashboard
14. EZDRM optional toggle (kalau perlu konten premium)
15. Recommendation engine batch (DeepSeek nightly)

---

## 📈 AI Feature Wave Status

Per [RANCANGAN.md §10.22](RANCANGAN.md), 162 features dalam 5 wave:

### Wave 1 — Critical (16 features)
| # | Feature | Status |
|---|---------|--------|
| A1 | Auto-subtitle generation | ⏳ Todo (user requested!) |
| A2 | Auto-tagging | ⏳ Todo |
| A3 | Auto-synopsis | ⏳ Todo |
| A4 | Thumbnail picker | ⏳ Todo |
| C3 | Movie summary | ⏳ Todo |
| J5 | Memorable quote extractor | ⏳ Todo |
| J6 | Cliffhanger detector | ⏳ Todo |
| J8 | Scene chapter auto-marker | ⏳ Todo |
| B1 | Personalized recommendations | ⏳ Todo |
| B2 | Semantic search | 🚧 Partial (basic keyword done via FilmKnowledgeService) |
| B3 | Similar movies | 🚧 Partial (genre overlap done in `findSimilar()`) |
| B11 | Collaborative filtering | ⏳ Todo |
| F10 | Speaker identification subtitle | ⏳ Todo |
| I4 | Adaptive video quality | ⏳ Todo |
| K3 | Quote search | ⏳ Todo |
| K4 | Vibe search | 🚧 Partial (keyword done, embedding upgrade needed) |

**Wave 1 progress**: 0/16 fully done (basic infrastructure exists). Subtitle (A1) jadi prioritas user.

### Wave 2 — High Impact (28 features)
- Status: 1/28 done — **C1 AI Chatbot CS** ✅ (sudah di Phase 2)

### Wave 3-5
Belum dimulai (sesuai plan, post-launch atau Year-2).

---

## 🚧 Microservices Roadmap (Future)

Saat user count > 100K, monolith Laravel mungkin perlu split:

| Service | When to Split | Estimated User Count |
|---------|--------------|---------------------|
| Web app (Laravel) | Stay monolith | 1K-1M |
| **Transcoding worker** (separate FFmpeg pool) | Saat upload > 5 film/day | 10K+ |
| **DRM key server** (low-latency, HA) | Saat playback latency critical | 50K+ |
| **AI processing worker** (GPU-backed kalau vision/audio heavy) | Saat batch job > 1000/day | 100K+ |
| **CDN edge functions** (auth, geo) | Saat global expansion | 500K+ |
| **Recommendation service** (precomputed cache) | Saat homepage personalisasi heavy | 100K+ |

Saat ini (1K user atau bahkan 10K), **monolith cukup**. Microservices = premature optimization.

---

## 🔌 Integration Status

| Integration | Status | Notes |
|-------------|--------|-------|
| Midtrans Payment | ✅ Live | Snap, env-gated |
| Google OAuth | ✅ Live | Socialite |
| Mailchimp Newsletter | ✅ Live | Env-gated |
| TMDB API | ⏳ Setup needed | TMDB_TOKEN in .env, untuk fetch poster |
| **Bunny CDN** | ⏳ Phase 1 | Critical, no setup yet |
| **MaxMind GeoIP2** | ⏳ Phase 1 | Untuk geo-blocking |
| **EZDRM** (optional) | ⏳ Phase 3 | Trial account perlu daftar |
| AWS (S3 backup) | 🔵 Optional | Untuk master file backup |
| ElevenLabs (voice) | 🔵 Future | Untuk dubbing Wave 4 |
| ACRCloud (music ID) | 🔵 Future | Untuk music license alert |
| Sentry | ⏳ Recommended | Error tracking — production |
| Datadog/NewRelic | 🔵 Optional | APM monitoring |

---

## 📊 Quick Health Check

| Check | Status |
|-------|--------|
| Database migrations: 22 total, all run | ✅ |
| Models: 17 (Movie, MovieAsset, AiProvider, etc) | ✅ |
| Routes: 73 registered | ✅ |
| AI provider configured? | ✅ DeepSeek V4 Flash active |
| Admin login works? | ✅ admin@gmail.com/password |
| Production assets built? | ✅ commit dd49807 |
| Live streaming pipeline? | ❌ Phase 1 not started |
| Multi-language subtitle? | ❌ User requested, not built |

---

## 🎬 Yang Sebenarnya Bisa Di-Demo Sekarang

**Working features** untuk demo ke client:
- ✅ Browse 35 film (real catalog)
- ✅ Filter by genre/year/letter/sort
- ✅ Hover preview cards (Netflix-style)
- ✅ Hero slider auto-rotate
- ✅ Top 10 leaderboard
- ✅ Movie detail (YouTube embed playback)
- ✅ Comments + ratings + watchlist
- ✅ Gamification (XP, coins, achievements, daily check-in)
- ✅ AI Chatbot dengan RAG (kasih rekomendasi, jawab pertanyaan film)
- ✅ Subscription plans (Bulanan/Tahunan toggle)
- ✅ Admin panel (CRUD + AI settings + Pitch Deck)
- ✅ Multi-role login (Super Admin / User)

**Not demo-ready**:
- ❌ Real video streaming dengan DRM (cuma YouTube embed)
- ❌ Multi-language subtitle
- ❌ Production deployment di Bunny CDN

---

## 🎯 Decision Points (Butuh konfirmasi user)

1. **Phase 1 priority**: Bunny CDN setup (kalau client mau real streaming demo) atau focus AI features dulu?
2. **Subtitle scope**: Cuma generate (Indonesia) atau langsung translate ke beberapa lang?
3. **EZDRM**: Sign up trial sekarang? Atau tunggu sampai konten premium ada?
4. **Embeddings**: Install pgvector atau cukup keyword RAG untuk MVP?
5. **Microservices split**: Tunggu skala 10K+ MAU? Atau prep architecture sekarang?

---

**Total commits since pitch deck**: 13
**Lines of code added**: ~7.500+
**Files added**: 27
**Test coverage**: ⚠️ Belum ada (perlu add tests)
