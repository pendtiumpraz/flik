# FLiK — Rumah Sinema Indonesia

**Pitch Deck — Technical Proposal**

> Platform OTT premium untuk film klasik & jadul Indonesia. Audience: investor, calon partner rights holder, dan tim teknis.
> Versi: 1.0 · Tanggal: 2026-05-09 · Disiapkan untuk: presentasi internal & external pitching.

---

## Daftar Isi

1. [Executive Summary](#1-executive-summary)
2. [Asumsi & Skenario](#2-asumsi--skenario)
3. [Digital Rights Management (DRM)](#3-digital-rights-management-drm)
4. [AI Layer — Fitur, Provider, & Cost](#4-ai-layer--fitur-provider--cost)
5. [Arsitektur Platform End-to-End](#5-arsitektur-platform-end-to-end)
6. [CDN & Streaming Strategy](#6-cdn--streaming-strategy)
7. [AWS Cost Matrix (1K → 1M Users)](#7-aws-cost-matrix)
8. [Total Cost of Ownership Summary](#8-total-cost-of-ownership-summary)
9. [Roadmap Implementasi](#9-roadmap-implementasi)
10. [Risk Register & Mitigasi](#10-risk-register--mitigasi)
11. [Appendix: Catatan Vendor & Referensi](#11-appendix-catatan-vendor--referensi)

---

## 1. Executive Summary

**FLiK** adalah platform streaming premium untuk **film klasik Indonesia** (300–400 judul, target 1.000 di tahun ke-3). Platform ini menggabungkan tiga pilar:

| Pilar | Value |
|-------|-------|
| **Konten** | Library film jadul yang sudah didigitalkan & di-restore. Niche tapi underserved. |
| **Distribusi** | Adaptive streaming HLS/DASH dengan multi-DRM, CDN edge di Indonesia. |
| **AI** | Personalisasi rekomendasi, auto-subtitle, semantic search, content tagging — semua via API provider (OpenAI/Anthropic/DeepSeek/Gemini), pay-per-use. |

Built on **Laravel 12 + Livewire 3**, deploy ke **AWS** (ECS + RDS + S3 + MediaConvert + CloudFront), dengan opsi **NativePHP Mobile** untuk Android app. Sudah ada panel admin lengkap, payment via Midtrans, gamifikasi (XP/coins/achievements), dan PWA.

**Status saat ini**: MVP web jalan, admin panel jalan, payment terintegrasi, ~50 film di-seed. Yang masih perlu dibangun untuk produksi: **DRM proper**, **transcoding pipeline**, **CDN setup**, dan **AI integration layer** (sudah disiapkan tabel `ai_providers` dengan API key terenkripsi).

---

## 2. Asumsi & Skenario

Semua angka di dokumen ini menggunakan asumsi **"Standar OTT Indonesia"** kecuali disebutkan lain:

| Parameter | Nilai | Catatan |
|-----------|-------|---------|
| Jumlah film | 350 | Target tahun-1 |
| Ukuran source per film | 2 GB (master) | Setelah ABR transcode jadi ~3.5 GB total per film (5 rendition) |
| Total storage source | ~700 GB | Master file |
| Total storage transcoded | ~1.2 TB | HLS + DASH segments + DRM-packaged |
| Average watch time | 10 jam/user/bulan | Indonesian OTT benchmark |
| Bitrate average | 5 Mbps (1080p adaptive) | ABR ladder: 360p / 480p / 720p / 1080p / 4K |
| Egress per user/bulan | ~22 GB | (10h × 3600s × 5Mbps / 8 / 1024) |
| CDN cache hit ratio | 30% origin / 70% edge | Konservatif untuk konten katalog (long-tail) |
| Region utama | Asia Pacific (Jakarta + Singapore) | CloudFront ap-southeast-1, ap-southeast-3 |
| Peak/avg ratio | 3.5× | Prime time 19:00–23:00 + akhir pekan |
| Kurs USD | Rp 16.000 | Hitung kasar untuk benchmark Rupiah |

**Skenario user yang dihitung**: 1.000 / 10.000 / 100.000 / 1.000.000 monthly active users (MAU). Asumsi **40% MAU = paid subscriber**.

---

## 3. Digital Rights Management (DRM)

### 3.1 Pertanyaan Kunci yang Dijawab

> *"Apakah DRM bisa di-implement langsung di Laravel? Atau harus pakai 3rd party?"*

**Jawaban singkat**: tergantung level proteksi yang diminta rights holder.

- **Level Basic (signed URLs + AES-128)**: ✅ **Bisa 100% di Laravel**, tanpa 3rd party.
- **Level Open-source (ClearKey + Shaka)**: ✅ Bisa di Laravel, tapi butuh tools eksternal untuk packaging (Bento4/Shaka Packager — gratis, open source).
- **Level Studio-grade (Widevine/PlayReady/FairPlay)**: ❌ **Wajib 3rd party DRM service**. Tidak bisa pure Laravel — license server harus disertifikasi Google (Widevine) dan Apple (FairPlay), sertifikat FairPlay hanya diberikan kalau perusahaan kamu sudah enterprise developer Apple yang approved.

### 3.2 Tier Comparison Matrix

| Tier | Tech | Proteksi | Cost (1K user) | Cost (100K user) | Compatibility | Cocok untuk |
|------|------|----------|---------------|------------------|---------------|-------------|
| **DIY Tier 0** | Signed URLs + Token | ⭐ | ~$0/bln | ~$0/bln | Semua browser | MVP, demo, free trial |
| **DIY Tier 1** | HLS AES-128 + signed URLs | ⭐⭐ | ~$0/bln | ~$0/bln | Semua HLS player | Film katalog jadul, low piracy risk |
| **Open-source** | Shaka + ClearKey + Widevine L3 | ⭐⭐⭐ | ~$50/bln | ~$200/bln | Browser modern + Android | Mid-tier, partial protection |
| **Studio L3** | Multi-DRM via 3rd party (Widevine L3 + PlayReady SL150 + FairPlay) | ⭐⭐⭐⭐ | $300–$800/bln | $2K–$5K/bln | Semua device modern | Konten premium, rights holder demand DRM |
| **Studio L1** | Multi-DRM hardware (Widevine L1 + PlayReady SL3000 + FairPlay) | ⭐⭐⭐⭐⭐ | $500–$1.500/bln | $5K–$15K/bln | Hardware-backed only | Disney+/Netflix tier — wajib untuk 4K HDR & studio besar |

### 3.3 Tier 0 — Signed URLs (Pure Laravel)

**Konsep**: video file disimpan di S3/storage private, akses via temporary signed URL yang expired dalam menit.

**Implementasi di Laravel**:
```php
// In WatchHistoryController or VelflixController
$url = Storage::disk('s3')->temporaryUrl(
    $movie->video_path,
    now()->addMinutes(5),
    ['ResponseContentType' => 'video/mp4']
);
```

**Pro**: Zero cost, simple, sudah supported native Laravel.
**Con**: Sekali file di-download lokal, bisa dishare bebas. Tidak ada enkripsi konten.
**Kapan dipakai**: trailer, behind-the-scene, konten free.

### 3.4 Tier 1 — HLS AES-128 (Pure Laravel + FFmpeg)

**Konsep**: file di-segment jadi HLS chunks (.ts), tiap chunk di-encrypt dengan AES-128. Player butuh request key dari endpoint Laravel yang validate token user.

**Setup**:
1. Transcode film ke HLS dengan FFmpeg + AES-128:
   ```bash
   ffmpeg -i input.mp4 \
     -hls_time 6 -hls_key_info_file enc.keyinfo \
     -hls_playlist_type vod output.m3u8
   ```
2. `enc.keyinfo` berisi key URL & IV. Key URL pointing ke endpoint Laravel:
   ```php
   Route::get('/drm/key/{movie}/{token}', [DrmController::class, 'serveKey'])
       ->middleware('auth');
   ```
3. Controller validate: user login? subscription aktif? token valid? geo OK? — baru serve binary key.

**Pro**: Tetap zero ongoing cost (selain compute transcode), key rotation bisa dilakukan kapan saja, geo-blocking gampang.
**Con**: Key bisa di-extract dari memory player. Tidak melindungi dari user yang technical & determined.
**Kapan dipakai**: Sebagian besar konten katalog film jadul Indonesia — rights holder umumnya tidak demand DRM enterprise.

### 3.5 Tier 2 — ClearKey + Open-source Widevine L3

**Konsep**: pakai DASH dengan multi-DRM tapi key server-nya kita host sendiri pakai open source (mis. `eyevinn/keyos-clearkey-server` atau implementasi sendiri di Laravel).

**Stack**:
- **Packager**: [Shaka Packager](https://github.com/shaka-project/shaka-packager) (gratis, by Google)
- **Player**: Shaka Player (web), ExoPlayer (Android)
- **Key server**: custom Laravel endpoint
- **Widevine L3 license server**: open source seperti [pywidevine](https://github.com/devine-dl/pywidevine) — tapi **secara legal Widevine butuh Google certification**, tidak bisa production tanpa NDA.

**Pro**: Lebih kuat dari AES-128, support beberapa device.
**Con**: Widevine L3 tanpa certification = legal grey area. iOS tidak support tanpa FairPlay.
**Kapan dipakai**: Internal testing, edukasi. **Tidak direkomendasikan untuk production**.

### 3.6 Tier 3 — Studio-grade Multi-DRM via 3rd Party

**Wajib 3rd party DRM provider**. Tidak ada alternatif legal untuk implement Widevine/PlayReady/FairPlay sendiri. Provider akan handle:
- Hosting license server bersertifikat
- Manajemen device certificate (FairPlay)
- Anti-piracy monitoring
- Rotational keys

**Vendor comparison** (per stream / per device pricing, harga benchmark publik 2025):

| Vendor | Pricing Model | Estimasi 100K active | Notable |
|--------|---------------|---------------------|---------|
| **AWS Elemental MediaPackage** | $0.01/min packaged + $0.005/license request | ~$3.000/bln | Native AWS, integrated dengan MediaConvert |
| **EZDRM** | $0.0024/license (volume) | ~$1.500/bln | Low cost, simple, US-based |
| **BuyDRM KeyOS** | Tiered: $500/mo flat → $0.005/license | ~$2.000–$4.000/bln | Used by Disney+ HotStar |
| **Verimatrix MultiDRM** | Enterprise (kontrak) | $5K+/bln | Heavy enterprise, anti-piracy strong |
| **Axinom DRM** | Per active device, ~$0.10/device/mo | ~$5.000/bln @ 50K active devices | Eropa, GDPR-friendly |
| **DRMtoday (Castlabs)** | Tiered enterprise | $2K–$8K/bln | Used by ProSiebenSat.1, banyak EU broadcaster |

**Rekomendasi untuk FLiK**:
- **Tahun 1 (1K–10K users)**: **EZDRM** — paling murah, paling cepat onboard, multi-DRM bundled.
- **Tahun 2–3 (10K–100K)**: tetap **EZDRM** atau switch ke **AWS MediaPackage** kalau sudah heavy AWS.
- **Tahun 3+ atau kalau deal sama studio besar**: **BuyDRM** atau **DRMtoday** untuk credibility.

### 3.7 Implementasi Studio-grade di Laravel — Architecture

```
┌─────────────────────────────────────────────────────────────┐
│ FLiK Laravel App                                             │
│  ├── Admin upload film (master MP4)                         │
│  └── Trigger AWS MediaConvert job via SDK                   │
└───────────────────────┬─────────────────────────────────────┘
                        │
                        ▼
┌─────────────────────────────────────────────────────────────┐
│ AWS MediaConvert                                             │
│  - Transcode ke ABR ladder (360p–4K)                        │
│  - Output ke S3 bucket "flik-encoded"                        │
└───────────────────────┬─────────────────────────────────────┘
                        │
                        ▼
┌─────────────────────────────────────────────────────────────┐
│ Shaka Packager (atau MediaPackage VOD)                       │
│  - Encrypt segments with CPIX                                │
│  - Generate HLS (FairPlay) + DASH (Widevine + PlayReady)    │
│  - Upload ke S3 "flik-protected"                             │
└───────────────────────┬─────────────────────────────────────┘
                        │
                        ▼
┌─────────────────────────────────────────────────────────────┐
│ CloudFront CDN                                               │
│  - Signed URLs / Signed Cookies                              │
│  - Geo-restriction                                           │
└───────────────────────┬─────────────────────────────────────┘
                        │
                        ▼
┌─────────────────────────────────────────────────────────────┐
│ User Player (Shaka / Video.js / ExoPlayer / Safari)         │
│  - Detect DRM capability                                     │
│  - Request license:                                          │
│    Widevine → license.ezdrm.com  ────┐                       │
│    PlayReady → license.ezdrm.com  ────┤                      │
│    FairPlay → license.ezdrm.com  ─────┘                      │
└─────────────────────────────────────────────────────────────┘
                                      │
                                      ▼
┌─────────────────────────────────────────────────────────────┐
│ DRM Provider (EZDRM / BuyDRM)                                │
│  - Validate token (signed by FLiK Laravel JWT)               │
│  - Issue license with device-bound restrictions              │
└─────────────────────────────────────────────────────────────┘
```

**Code touchpoint di Laravel (yang perlu ditambahkan):**

1. `App\Services\Drm\LicenseTokenService` — generate JWT untuk DRM provider.
2. `App\Services\Transcoding\MediaConvertJobBuilder` — build & dispatch job ke MediaConvert.
3. `App\Http\Controllers\PlaybackController::getStreamConfig($movie)` — return HLS/DASH manifest URL + license server URL + JWT token.
4. Migration tambahan: `movies` table butuh kolom `dash_manifest_url`, `hls_manifest_url`, `drm_status`, `drm_provider_id`.

### 3.8 Watermarking (forensic)

Untuk konten premium, tambahkan **per-session forensic watermarking** (mis. NexGuard, EZDRM Watermark, Castlabs Forensic). Cost: ~$0.001/stream. Berguna untuk track siapa yang leak konten.

---

## 4. AI Layer — Fitur, Provider, & Cost

### 4.1 Arsitektur AI di FLiK

Sudah ada tabel `ai_providers` di database (migration `2026_05_09_000001_create_ai_providers_table`). Admin bisa add multiple providers via `/admin/ai-settings`. API key dienkripsi (`encrypted` cast Laravel).

**Strategi multi-provider**:
- 1 provider sebagai **default** (mis. DeepSeek untuk cost-efficient operations).
- Provider lain sebagai **fallback** atau **specialty** (mis. OpenAI Whisper untuk transcription, Claude untuk reasoning, Gemini untuk multimodal/image).
- Service layer di Laravel akan pilih provider berdasarkan task type, dengan auto-fallback kalau primary down.

### 4.2 Provider Comparison (per Mei 2026)

| Provider | Model | Input ($/1M tok) | Output ($/1M tok) | Use Case |
|----------|-------|------------------|--------------------|----------|
| **DeepSeek** | deepseek-chat (V3) | $0.27 | $1.10 | Default — generation, summarization, tagging. Ratio cost/quality terbaik. |
| **DeepSeek** | deepseek-reasoner | $0.55 | $2.19 | Complex reasoning, recommendation logic |
| **OpenAI** | gpt-4o-mini | $0.15 | $0.60 | Fast UI features (chat support, quick tagging) |
| **OpenAI** | gpt-4o | $2.50 | $10.00 | Premium tasks, content moderation |
| **OpenAI** | Whisper (audio) | $0.006/menit | — | Auto-subtitle generation |
| **Anthropic** | Claude Sonnet 4.6 | $3.00 | $15.00 | Long-form synopsis, editorial copy |
| **Anthropic** | Claude Haiku 4.5 | $1.00 | $5.00 | Comments moderation, fast classification |
| **Google** | Gemini 2.0 Flash | $0.075 | $0.30 | Cheapest option, multimodal (image+text) |
| **Groq** | Llama 3.3 70B | $0.59 | $0.79 | Ultra-low latency (real-time chat) |
| **OpenRouter** | Multi-vendor | varies | varies | Single API key, multi provider routing |

### 4.3 AI Feature Catalog (15 Fitur)

#### 🎯 User-Facing Features

| # | Fitur | Model Pilihan | Cost Estimate per 1K User/Bulan |
|---|-------|--------------|--------------------------------|
| 1 | **Personalized Recommendations** — "Karena kamu suka X, coba Y" | DeepSeek-chat (batched) | ~$5 |
| 2 | **Semantic Search** — "film tentang kemerdekaan dari sudut pandang petani" | Embeddings (OpenAI text-embedding-3-small) + DeepSeek | ~$3 |
| 3 | **Auto-generated Movie Synopsis** (saat admin upload) | Claude Haiku | ~$0.10 per movie |
| 4 | **AI Chatbot Customer Support** (resolve common issues) | Groq Llama 3.3 (low-latency) | ~$8 (assuming 30% users use it) |
| 5 | **Smart Continue Watching** — predict next episode/film | DeepSeek-reasoner | ~$2 |
| 6 | **Mood-based Discovery** — "Hari ini lagi mellow" → recommend | Gemini Flash + tags | ~$1 |
| 7 | **AI Subtitle Generation** (Indonesian/English/regional) | OpenAI Whisper | $0.006 × 90 min = $0.54 per film (one-time) |
| 8 | **Auto-translation Subtitles** (Indo → Mandarin/Arabic for diaspora) | DeepSeek + post-edit | ~$2 per film (one-time) |

#### 🛠️ Backend / Admin Features

| # | Fitur | Model Pilihan | Cost Estimate |
|---|-------|--------------|---------------|
| 9 | **Auto-tagging** (genre, mood, themes, era, era cinematography style) | Claude Haiku (text+poster vision) | ~$0.20 per film (one-time) |
| 10 | **Comment/Review Moderation** (toxicity, spam, off-topic) | Gemini Flash | ~$0.50 per 10K comments |
| 11 | **Trailer Auto-generation Suggestion** — pick best 30s clips | Multimodal vision (Gemini/GPT-4o vision) | ~$2 per film (one-time) |
| 12 | **Thumbnail Selection** — pick best frame for poster grid | Gemini vision | ~$0.30 per film |
| 13 | **Content Quality Classification** (audio sync, visual issues) | Custom + GPT-4o vision | ~$1 per film (QC) |
| 14 | **Restoration Priority Ranking** — film mana yang worth di-restore | DeepSeek-reasoner + metadata | ~$0.10 per film |
| 15 | **Email/Notification Personalization** — tailor copy per user segment | DeepSeek-chat | ~$3 per 1K users/bulan |

### 4.4 Total AI Cost Projection

**One-time cost (350 film katalog awal)**:
| Task | Per Film | Total |
|------|---------|-------|
| Subtitle (Whisper) | $0.54 | $189 |
| Auto-tagging | $0.20 | $70 |
| Translation (3 bahasa) | $6.00 | $2.100 |
| Trailer suggest | $2.00 | $700 |
| Thumbnail pick | $0.30 | $105 |
| QC | $1.00 | $350 |
| Synopsis | $0.10 | $35 |
| **One-time total** | | **~$3.550** (≈ Rp 57 juta) |

**Ongoing monthly cost** (per skala user):

| Users | Monthly AI Cost (USD) | Monthly AI Cost (Rp) |
|-------|----------------------|----------------------|
| 1.000 | ~$25 | ~Rp 400.000 |
| 10.000 | ~$250 | ~Rp 4 juta |
| 100.000 | ~$2.500 | ~Rp 40 juta |
| 1.000.000 | ~$25.000 | ~Rp 400 juta |

> AI cost scales **linearly** dengan user count, tapi sangat **manageable** karena DeepSeek/Gemini Flash murah & banyak tugas bisa dibatch (recommendation di-precompute harian).

### 4.5 Tools Tambahan yang Perlu Diinstall (Selain AI Provider API)

Untuk fitur AI bekerja, butuh komponen non-AI:

1. **Vector database** — untuk semantic search & recommendation:
   - Opsi murah: **pgvector** extension di PostgreSQL (gratis, single AWS RDS)
   - Opsi managed: **Pinecone** (~$70/bln starter), **Weaviate Cloud**, **Qdrant Cloud**
   - **Rekomendasi**: pgvector — sudah include di RDS PostgreSQL, no extra cost.
2. **Background job queue** — untuk batch AI processing:
   - Sudah ada di Laravel, tinggal switch dari `sync` ke **Redis queue** atau **AWS SQS**.
   - Worker: Laravel Horizon (dashboard) atau plain `php artisan queue:work`.
3. **Caching** — AI responses di-cache (Redis) untuk hemat call:
   - Recommendations cached per user 6 jam.
   - Search embeddings di-cache permanent.
4. **Rate limiting** — protect cost dari abuse (Laravel built-in `throttle`).
5. **Monitoring** — track per-provider spend (sudah ada kolom `total_tokens_used`, `total_cost_usd` di tabel `ai_providers`).

### 4.6 Flow Implementasi di Laravel

```php
// app/Services/Ai/AiClient.php (to be built)
class AiClient
{
    public function chat(array $messages, ?string $task = null): string
    {
        $provider = AiProvider::default(); // or pick by task
        $client = match ($provider->provider) {
            'openai', 'deepseek', 'groq', 'mistral', 'openrouter', 'custom'
                => $this->openAiCompatible($provider, $messages),
            'anthropic' => $this->anthropic($provider, $messages),
            'google'    => $this->gemini($provider, $messages),
        };
        $this->trackUsage($provider, $client);
        return $client['content'];
    }
}
```

**Karena 80%+ provider OpenAI-compatible** (DeepSeek, Groq, Mistral, OpenRouter, custom), satu HTTP client bisa handle banyak provider. Hanya Anthropic & Google yang punya format request berbeda.

---

## 5. Arsitektur Platform End-to-End

```
                          ┌──────────────────────────┐
                          │  CloudFront CDN          │
                          │  (id-jakarta-edge)       │
                          └────────────┬─────────────┘
                                       │
            ┌──────────────────────────┼──────────────────────────┐
            ▼                          ▼                          ▼
    ┌──────────────┐         ┌─────────────────┐         ┌──────────────┐
    │ Static       │         │ Video Manifests │         │ Web App      │
    │ Assets (S3)  │         │ + Segments (S3) │         │ (Laravel ECS)│
    └──────────────┘         └─────────────────┘         └──────┬───────┘
                                                                │
              ┌─────────────────────────────────────────────────┤
              ▼                ▼                ▼                ▼
        ┌──────────┐    ┌──────────┐    ┌─────────────┐  ┌────────────┐
        │ RDS      │    │ Redis    │    │ AI Providers│  │ DRM Server │
        │ MySQL +  │    │ (cache + │    │ (DeepSeek,  │  │ (EZDRM)    │
        │ pgvector │    │  queue)  │    │  OpenAI,...)│  │            │
        └──────────┘    └──────────┘    └─────────────┘  └────────────┘
              ▲
              │ admin upload
              │
        ┌─────┴─────────┐
        │ MediaConvert  │
        │ (transcode)   │
        └───────┬───────┘
                │
        ┌───────▼────────┐
        │ Shaka Packager │
        │ + DRM encrypt  │
        └────────────────┘
```

### 5.1 Komponen & Tanggung Jawab

| Layer | Komponen | Notes |
|-------|----------|-------|
| **Edge** | CloudFront | 1 distribusi untuk web app, 1 untuk video. Origin Access Identity untuk S3. |
| **App** | ECS Fargate (Laravel) | Auto-scale 2–20 task. `nginx-unit` atau `roadrunner` untuk performance. |
| **DB** | RDS for MySQL 8 (Multi-AZ) + RDS for PostgreSQL (pgvector untuk AI) | Bisa juga single MySQL kalau hindari split. PostgreSQL untuk vector search lebih solid. |
| **Cache/Queue** | ElastiCache Redis | Queue, session, AI response cache. |
| **Video Storage** | S3 (Standard untuk hot, IA setelah 30 hari) | Lifecycle rules. |
| **Encoding** | AWS Elemental MediaConvert | Pay per minute encoded. |
| **Packaging/DRM** | Shaka Packager (run di ECS task) atau MediaPackage VOD + EZDRM | EZDRM untuk license server. |
| **Search** | OpenSearch Service (atau Algolia) | Untuk text search; semantic via pgvector. |
| **Monitoring** | CloudWatch + Sentry | Application errors via Sentry. |
| **Email** | SES | Transactional + newsletter via Mailchimp. |
| **Auth** | Laravel built-in + Socialite (Google) | Sudah jalan. |
| **Payment** | Midtrans Snap | Sudah jalan. |

### 5.2 Why Laravel Sudah Cukup

- Sudah ada admin panel, auth, payment, gamification.
- Livewire bisa scale untuk dashboard real-time tanpa SPA framework tambahan.
- NativePHP Mobile bisa wrap jadi Android app — sudah ada di project (`/native` script & `nativephp/` folder).
- PHP-FPM atau Octane bisa handle 10K+ RPS per ECS task dengan tuning.

### 5.3 Yang Belum Ada & Perlu Dibangun

| Komponen | Effort | Priority |
|----------|--------|----------|
| `App\Services\Ai\AiClient` (multi-provider abstraction) | 3 hari | Tinggi |
| `App\Services\Drm\*` (license token, key rotation) | 5 hari | Tinggi (kalau target Studio DRM) |
| `App\Services\Transcoding\MediaConvertService` | 4 hari | Tinggi |
| `App\Jobs\TranscodeMovie`, `EncryptAndPackage`, `GenerateSubtitles`, `GenerateTags` | 4 hari | Tinggi |
| `PlaybackController` dengan signed manifest URLs | 2 hari | Tinggi |
| Player wrapper (Shaka.js) di Blade view | 3 hari | Tinggi |
| Vector embedding pipeline (pgvector) | 4 hari | Medium |
| Admin upload UI dengan progress + resumable (TUS protocol) | 5 hari | Medium |
| Audit log untuk admin actions | 1 hari | Medium |
| **Total estimate** | **~6 minggu (1 senior dev)** | |

---

## 6. CDN & Streaming Strategy

### 6.1 Pilihan CDN

| CDN | Pricing Asia Pacific | Pro | Con |
|-----|---------------------|-----|-----|
| **CloudFront** | $0.085/GB (10TB tier) → $0.020/GB (5PB tier) | Native AWS, bundled signed URLs | Mahal di low volume |
| **Cloudflare Stream** | $1/1K minutes delivered (~$0.40/GB) | Bundled video + CDN + DRM, mudah | Lock-in |
| **Bunny CDN** | $0.01–$0.04/GB Asia | Murah, simple | Less features |
| **Akamai** | Enterprise contract | Best quality, biggest network | Paling mahal |
| **Indonesia local CDN** (Indosat, BizNet) | Negotiable, ~Rp 1.000/GB ($0.06) | Latency Indonesia paling rendah | Limited tooling |

**Rekomendasi**: 
- **Tahun 1**: **CloudFront** (1 ekosistem dengan AWS).
- **Tahun 2 (kalau egress > 100 TB/bln)**: dual-CDN dengan **Bunny** atau **Cloudflare** sebagai secondary, route via **NS1** atau **DNSimple** untuk biaya lebih rendah.
- **Tahun 3 (kalau scale > 1M user)**: tambah **Indonesia local CDN** untuk peering dengan ISP lokal (Telkom, Indihome, Indosat).

### 6.2 Optimasi Cost CDN

1. **Lifecycle**: video segment > 30 hari rendah viewership → S3 IA (cost 50% lebih rendah).
2. **Cache headers**: video segments cache `max-age=31536000, immutable`. Manifest cache 30 detik.
3. **Adaptive bitrate ladder yang efisien**:
   - Skip 4K untuk semua film (kecuali request rights holder).
   - Default ladder: 240p (300 kbps), 480p (1 Mbps), 720p (3 Mbps), 1080p (5 Mbps).
   - Save ~40% bandwidth vs ladder Netflix (yang punya 7+ rendition).
4. **Open Connect equivalent**: long-term, deploy origin shield di SG region untuk reduce origin egress.
5. **Compression**: HEVC/H.265 untuk file baru → 40% lebih kecil dari H.264 dengan kualitas sama. Trade-off: lebih lama encode + Safari lama tidak support.

---

## 7. AWS Cost Matrix

**Asumsi standar OTT** (lihat §2). Semua harga dalam **USD/bulan**, region **ap-southeast-1** (Singapore — Indonesia traffic biasanya routing kesini).

### 7.1 Skenario 1.000 MAU (400 paid)

| Komponen | Spec | Volume | Unit Cost | Monthly |
|----------|------|--------|-----------|---------|
| **CloudFront egress** | Asia Pacific | 22 TB | $0.080/GB | **$1.760** |
| **S3 storage** | Standard, transcoded | 1.2 TB | $0.025/GB | $30 |
| **S3 GET requests** | Manifest + segments | 50M | $0.0004/1K | $20 |
| **MediaConvert** | One-time + monthly new (5 film/bln avg) | 750 menit | $0.0075/menit (HD) | $6 |
| **ECS Fargate** | 2 task × 2 vCPU + 4 GB | 730 jam | $0.04/jam | $58 |
| **RDS MySQL db.t3.medium Multi-AZ** | 50 GB SSD | — | — | $130 |
| **RDS PostgreSQL db.t3.small** (pgvector) | 20 GB | — | — | $40 |
| **ElastiCache Redis cache.t3.small** | — | — | — | $25 |
| **ALB + data transfer internal** | — | — | — | $20 |
| **CloudWatch logs + metrics** | — | — | — | $15 |
| **SES** | 100K emails | — | $0.10/1K | $10 |
| **DRM (EZDRM)** | ~80K licenses | — | $0.0024/lic | $192 |
| **AI APIs** | DeepSeek + others | — | — | $25 |
| **Backup, secrets, misc** | — | — | — | $40 |
| **Subtotal infrastructure** | | | | **$2.371** |
| **+ 15% buffer (egress spikes, support)** | | | | $356 |
| ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ | | | | ━━━━━━ |
| **TOTAL** | | | | **~$2.730/bln (Rp 43,7 juta)** |
| Per paid user | | | | **$6,8 (Rp 110K)** |

### 7.2 Skenario 10.000 MAU (4.000 paid)

| Komponen | Spec | Volume | Monthly |
|----------|------|--------|---------|
| **CloudFront egress** | 220 TB | tier $0.060/GB rata-rata | **$13.200** |
| **S3 storage** | 1.5 TB (more variants) | — | $40 |
| **S3 requests** | 500M | — | $200 |
| **MediaConvert** | 2K menit/bln | — | $15 |
| **ECS Fargate** | 4 task × 2 vCPU | — | $230 |
| **RDS MySQL db.r5.large Multi-AZ** | 200 GB | — | $420 |
| **RDS PostgreSQL db.t3.medium** | 50 GB | — | $130 |
| **ElastiCache Redis cache.m5.large** | — | — | $180 |
| **OpenSearch t3.medium 2 nodes** | — | — | $200 |
| **ALB + WAF** | + WAF rules | — | $80 |
| **CloudWatch + Sentry** | — | — | $80 |
| **SES** | 1M emails | — | $100 |
| **DRM (EZDRM)** | ~800K licenses | $0.0020 (volume) | $1.600 |
| **AI APIs** | — | — | $250 |
| **Backup, KMS, misc** | — | — | $150 |
| **Subtotal** | | | **$16.875** |
| **+ 15% buffer** | | | $2.531 |
| ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ | | | ━━━━━━ |
| **TOTAL** | | | **~$19.400/bln (Rp 310 juta)** |
| Per paid user | | | **$4,85 (Rp 78K)** |

### 7.3 Skenario 100.000 MAU (40.000 paid)

| Komponen | Spec | Volume | Monthly |
|----------|------|--------|---------|
| **CloudFront egress** | 2.2 PB | tier $0.040/GB avg (committed use) | **$88.000** |
| **S3 storage + transitions** | 2 TB hot + 3 TB IA | — | $200 |
| **S3 requests** | 5B | — | $1.500 |
| **MediaConvert** | 10K menit | — | $75 |
| **ECS Fargate** | 12 task × 4 vCPU + 8 GB | — | $1.500 |
| **RDS MySQL db.r5.2xlarge Multi-AZ** | 1 TB SSD | — | $2.200 |
| **RDS PostgreSQL db.r5.large + read replica** | 200 GB | — | $700 |
| **ElastiCache Redis cluster (3 node)** | — | — | $900 |
| **OpenSearch r5.large × 3** | — | — | $1.800 |
| **ALB + WAF + Shield Standard** | — | — | $400 |
| **CloudWatch + Sentry + Datadog** | — | — | $1.200 |
| **SES** | 10M emails | — | $1.000 |
| **DRM (EZDRM enterprise)** | ~8M licenses | $0.0010 (negotiated) | $8.000 |
| **AI APIs** | — | — | $2.500 |
| **Misc (KMS, backups, secrets, NAT)** | — | — | $1.000 |
| **Subtotal** | | | **$110.975** |
| **+ 15% buffer** | | | $16.646 |
| ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ | | | ━━━━━━ |
| **TOTAL** | | | **~$127.600/bln (Rp 2,04 miliar)** |
| Per paid user | | | **$3,19 (Rp 51K)** |

> **Catatan optimasi**: di skala ini wajib **Reserved Instances** untuk RDS (save 30-40%) dan **CloudFront Savings Bundle** (save 20%). Realistic optimized: **~$95K/bln**.

### 7.4 Skenario 1.000.000 MAU (400.000 paid)

| Komponen | Spec | Volume | Monthly |
|----------|------|--------|---------|
| **CloudFront egress** | 22 PB | tier $0.020/GB (custom contract) | **$440.000** |
| **S3 storage + IA + Glacier** | 5 TB hot + 10 TB IA + 20 TB archive | — | $1.500 |
| **S3 requests** | 50B | — | $15.000 |
| **MediaConvert** | 40K menit | — | $300 |
| **ECS Fargate** | 60+ task auto-scale | — | $12.000 |
| **RDS Aurora MySQL cluster (writer + 5 reader)** | 5 TB | — | $18.000 |
| **RDS Aurora PostgreSQL** | 1 TB | — | $4.500 |
| **ElastiCache Redis cluster (10+ shards)** | — | — | $6.000 |
| **OpenSearch r5.xlarge × 6** | — | — | $7.500 |
| **ALB + WAF + Shield Advanced** | — | — | $4.000 |
| **Monitoring (Datadog, Sentry, NewRelic)** | — | — | $5.000 |
| **SES** | 100M emails | — | $10.000 |
| **DRM enterprise contract** | ~80M licenses | $0.0005 negotiated | $40.000 |
| **AI APIs** | — | — | $25.000 |
| **VPC, NAT, transit gateway, secrets** | — | — | $5.000 |
| **24/7 AWS Enterprise Support** | — | — | $15.000 |
| **Subtotal** | | | **$608.800** |
| **+ 15% buffer (Lebaran spike, prime sport release)** | | | $91.320 |
| ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ | | | ━━━━━━ |
| **TOTAL (worst case AWS-only)** | | | **~$700.000/bln (Rp 11,2 miliar)** |
| Per paid user | | | **$1,75 (Rp 28K)** |

> **Optimasi wajib di skala ini**:
> - **Multi-CDN** (CloudFront + Bunny/Cloudflare) — save 30–40% bandwidth cost.
> - **Reserved Instances 3-year** — save 40–60% compute.
> - **Indonesia local peering** dengan Telkom/IM2/Biznet — bypass CDN egress untuk traffic lokal.
> - **Hybrid: AWS untuk control plane + bare metal/colo (EquinixID/IDC) untuk video origin** — bisa save 50% storage+egress.
>
> Realistic optimized: **~$400.000/bln (Rp 6,4 miliar)**.

### 7.5 Cost Per User Trend

| MAU | Total Monthly (USD) | Per Paid User | Per MAU |
|-----|---------------------|--------------|---------|
| 1.000 | $2.730 | $6,80 | $2,73 |
| 10.000 | $19.400 | $4,85 | $1,94 |
| 100.000 | $127.600 | $3,19 | $1,28 |
| 1.000.000 | $700.000 (worst) / $400.000 (optimized) | $1,75 / $1,00 | $0,70 / $0,40 |

**Trend**: makin besar skala, makin efisien per-user. Dari $6.80 turun jadi $1.00 — **economies of scale 6.8×**.

---

## 8. Total Cost of Ownership Summary

### 8.1 Year-1 Budget (Tahun Operasional Pertama)

Asumsi growth: 1K → 10K MAU dalam 12 bulan.

| Item | One-time | Monthly avg | Year-1 Total |
|------|---------|-------------|--------------|
| **Initial AWS setup + arsitektur** | $5.000 | — | $5.000 |
| **One-time AI processing 350 film** | $3.550 | — | $3.550 |
| **Konten ingestion & restoration coordination** | $20.000 | — | $20.000 |
| **DRM onboarding (EZDRM setup)** | $2.000 | — | $2.000 |
| **Development team (6 minggu remaining + hardening)** | $30.000 | — | $30.000 |
| **AWS infra (avg 1K → 10K)** | — | $8.000 | $96.000 |
| **DRM licenses ongoing** | — | $400 | $4.800 |
| **AI ongoing** | — | $80 | $960 |
| **Domain, SSL wildcards, monitoring SaaS** | — | $200 | $2.400 |
| ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ | | | ━━━━━━ |
| **TOTAL Year-1** | | | **~$165.000 (Rp 2,64 miliar)** |

### 8.2 Year-3 Steady State (100K MAU)

| Item | Monthly | Year-3 Annual |
|------|---------|---------------|
| AWS Infra (optimized) | $95.000 | $1.140.000 |
| DRM | $8.000 | $96.000 |
| AI | $2.500 | $30.000 |
| 3rd party SaaS (monitoring, error tracking, support tools) | $3.000 | $36.000 |
| Engineering team (5 senior dev avg blended Indonesia) | $30.000 | $360.000 |
| **TOTAL Year-3** | **~$138.500/bln** | **~$1.660.000/year (Rp 26,6 miliar)** |

### 8.3 Revenue Required (Sanity Check)

Jika pricing **Rp 49.000/bln** (~$3) untuk paid subscriber:

| Skenario | Paid Users | Revenue/bln | Cost/bln | Margin |
|---------|-----------|-------------|----------|--------|
| 1K MAU | 400 | $1.200 | $2.730 | **(-127%)** ❌ Burn |
| 10K MAU | 4K | $12.000 | $19.400 | **(-62%)** ❌ Burn |
| 100K MAU | 40K | $120.000 | $127.600 | **(-6%)** ⚠️ Break-even |
| 100K (optimized + Rp 79K plan) | 40K | $200.000 | $95.000 | **+52%** ✅ |
| 1M MAU | 400K | $1.200.000 | $400.000–$700.000 | **+40–67%** ✅ |

**Insight**: pricing Rp 49K terlalu murah untuk skala awal. Strategi:
1. Pricing tier: **Basic Rp 39K** (480p, 2 device), **Premium Rp 79K** (1080p, 4 device, no ad), **Family Rp 129K** (4K future, 6 device).
2. **Iklan gratis** untuk free tier — Google Ad Manager / Magnite untuk monetize non-paying.
3. **B2B**: lisensi institusi (sekolah, kampus, hotel, lembaga budaya) — Rp 5–20 juta/tahun per institusi.
4. **Sponsorship klasik** — partnership dengan Sinematek, KemenParekraf, brand heritage (Bentoel, Garuda).

---

## 9. Roadmap Implementasi

### Fase 0 — MVP (sudah ada) ✅
- Web app, admin panel, payment Midtrans, gamification, PWA.

### Fase 1 — Production Ready (Bulan 1–2)
- [ ] AWS environment setup (VPC, ECS, RDS, S3, CloudFront)
- [ ] CI/CD pipeline (GitHub Actions → ECR → ECS)
- [ ] MediaConvert pipeline + Shaka Packager
- [ ] Tier 1 DRM (HLS AES-128) — minimum viable protection
- [ ] Migrasi dari `local` storage ke S3
- [ ] Domain, SSL, WAF setup

### Fase 2 — DRM & AI Foundation (Bulan 3–4)
- [ ] EZDRM onboarding & integration (Tier 3 Studio DRM)
- [ ] `App\Services\Ai\AiClient` multi-provider abstraction
- [ ] Whisper subtitle generation pipeline
- [ ] Auto-tagging via Claude Haiku + Gemini vision
- [ ] pgvector setup, embedding pipeline untuk semantic search

### Fase 3 — Growth Features (Bulan 5–6)
- [ ] Personalized recommendation system (DeepSeek + embeddings)
- [ ] AI chatbot customer support
- [ ] Auto-translation subtitle (3 bahasa)
- [ ] Trailer auto-suggestion
- [ ] Comment moderation pipeline

### Fase 4 — Mobile & Native (Bulan 6–8)
- [ ] Polish NativePHP Android build
- [ ] iOS app (Flutter atau native — separate project)
- [ ] TV apps (Android TV, Apple TV, Samsung Tizen — partnership 3rd party)

### Fase 5 — Scale (Bulan 8–12)
- [ ] Multi-CDN setup
- [ ] Indonesia local CDN peering
- [ ] Reserved instance procurement
- [ ] Forensic watermarking (kalau partnership studio besar)
- [ ] B2B portal untuk institusi

---

## 10. Risk Register & Mitigasi

| Risk | Likelihood | Impact | Mitigasi |
|------|-----------|--------|----------|
| **CDN egress cost meledak saat viral** | Tinggi | Tinggi | Multi-CDN, Indonesia peering, alert spend, throttle bitrate dynamic |
| **DRM key compromise / leak** | Rendah | Tinggi | Forensic watermarking, monthly key rotation, EZDRM audit |
| **AI provider down (single point)** | Medium | Medium | Multi-provider fallback (sudah didesain di tabel `ai_providers`) |
| **Rights holder tarik konten** | Medium | Tinggi | Kontrak multi-year, escalator clause, library diversification |
| **Pembajakan / capture screen** | Tinggi | Medium | DRM hardware (L1) untuk premium, watermarking forensic, takedown ops |
| **Lebaran/holiday traffic 5× spike** | Tinggi | Medium | Auto-scaling ECS + buffer 15%, pre-warm CDN, capacity test 2× per tahun |
| **Compliance UU PDP (Perlindungan Data Pribadi)** | Tinggi | Tinggi | Data residency Indonesia (RDS region jakarta jika tersedia, atau AWS Local Zone), audit trail, consent UI |
| **Payment gateway down (Midtrans)** | Rendah | Medium | Add Xendit / DOKU / OVO sebagai backup |
| **Cost untuk encode 4K explode** | Medium | Medium | Default H.265, hindari 4K kecuali request, batch encode off-peak |
| **AI hallucination di synopsis publik** | Medium | Medium | Editorial review workflow, human-in-the-loop sebelum publish |

---

## 11. Appendix: Catatan Vendor & Referensi

### A. DRM Providers (Contact)

| Vendor | Region Sales | Notes |
|--------|-------------|-------|
| EZDRM | US (24/7 support) | https://ezdrm.com — paling cepat onboarding |
| BuyDRM | US/EU | https://buydrm.com — enterprise focus |
| AWS MediaPackage | AWS partner | Lewat AWS account manager |
| Verimatrix | Global | https://verimatrix.com |
| Axinom | EU | https://axinom.com |

### B. CDN Vendor Indonesia

- **Telkom CDN** (Indihome peering) — kontak: corporate sales Telkom
- **Biznet CDN** — https://biznetnetworks.com
- **Lintasarta CDN**

### C. Restorasi Film Indonesia (Partnership)

- **Sinematek Indonesia** (PPHUI Kuningan) — arsip negara, bisa partnership digitalisasi
- **Kineforum DKJ** — kurasi & pemutaran
- **ANRI (Arsip Nasional)** — koleksi film negara

### D. Referensi Teknis

- AWS Pricing Calculator: https://calculator.aws
- Shaka Packager: https://github.com/shaka-project/shaka-packager
- Bento4: https://www.bento4.com
- Pgvector: https://github.com/pgvector/pgvector
- DeepSeek API docs: https://api-docs.deepseek.com
- OpenAI pricing: https://openai.com/api/pricing
- Anthropic pricing: https://www.anthropic.com/pricing

### E. Catatan Internal — API Key Security

⚠️ **API key tidak boleh di-commit, di-paste di chat tools, atau di-share via Slack/Email tanpa enkripsi**.

Best practice:
1. API key disimpan di tabel `ai_providers` dengan `encrypted` cast (Laravel AES-256-CBC pakai `APP_KEY`).
2. `APP_KEY` rotate per kuartal — semua key di re-encrypt saat rotate (`php artisan app:rotate-keys`).
3. Untuk staging, pakai test key dengan budget cap di provider dashboard (mis. DeepSeek allow per-key spend limit).
4. Webhook DRM provider validate signature sebelum diproses.

---

**End of Pitch Deck**

> Dokumen ini bisa dibuka dalam mode presentasi via halaman admin: **`/admin/pitch-deck`**. AI Provider settings tersedia di **`/admin/ai-settings`**.
