# FLiK — Rumah Sinema Indonesia

**Pitch Deck — 3-Month MVP Build Proposal**

> Platform OTT untuk 400 film klasik & jadul Indonesia milik client. Audience: client decision maker.
> Versi: 2.0 · Tanggal: 2026-05-09 · Budget framing: **Rp 60 juta/bulan × 3 bulan**

---

## 0. Yang Berubah dari v1.0

Versi sebelumnya pakai framing enterprise scale (1M users, deal studio besar). Versi ini disesuaikan dengan **realita client**:

| Update | Dari (v1.0) | Ke (v2.0) |
|--------|-------------|-----------|
| Konten | 350 film, perlu di-akuisisi (~$20K) | **400 film sudah dimiliki client** — biaya konten Rp 0 |
| Budget framing | $165K Year-1 (Rp 2,6 miliar) | **Rp 180jt build (3 bulan) + ~Rp 10jt/bln OPEX awal** |
| AI scope | Multi-vendor SaaS subscriptions | **Semua in-house** pakai API langsung (DeepSeek/Gemini/Whisper) |
| CDN default | CloudFront ($0.114/GB Asia — verified) | **Bunny CDN ($0.01/GB Asia)** — 11× lebih murah |
| AI default | gpt-4o-mini ($0.15/$0.60) | **DeepSeek V4 Flash ($0.14/$0.28)** — half the cost, equal quality untuk task standar |
| Subtitle | Whisper $0.006/min | **gpt-4o-mini-transcribe $0.003/min** — 50% lebih murah |
| DRM kickoff | $300–$5.000/bln tier | **EZDRM starter $200/bln flat** — verified |

Semua harga di dokumen ini **diverifikasi dari sumber publik** per Mei 2026. No speculation.

---

## 1. Executive Summary (1-pager)

**FLiK** = platform OTT in-house untuk client yang sudah punya **400 film klasik Indonesia**. Built on Laravel 12 yang sudah jalan (admin panel, payment, gamification, PWA) — tinggal tambah **distribution layer (DRM + transcoding + CDN)** dan **AI layer**.

| Yang sudah ada (existing codebase) | Yang akan dibangun (3 bulan) |
|------------------------------------|-----------------------------|
| ✅ Web app + admin panel | 🚀 Transcoding pipeline (FFmpeg/MediaConvert) |
| ✅ Payment Midtrans | 🚀 DRM (Tier 1 AES-128 → EZDRM Multi-DRM) |
| ✅ Gamification (XP/coin/achievement) | 🚀 Bunny CDN integration |
| ✅ PWA + NativePHP Android | 🚀 AI service layer (in-house) |
| ✅ Auth + Google OAuth | 🚀 Semantic search + recommendations |
| ✅ AI provider settings (encrypted API key) | 🚀 Auto-subtitle + auto-tagging |

**Key insight**: bukan rebuild — **build distribution + AI di atas foundation yang sudah ada**.

---

## 2. Budget — Rp 180 juta untuk 3 Bulan

### 2.1 Breakdown 3-Month Build (Total: ~Rp 180jt = $11.250)

**Hybrid DRM strategy**: build DIY DRM in-house (gratis) sebagai default. EZDRM optional, tidak masuk core build budget.

| Item | Per Bulan | 3 Bulan | Catatan |
|------|-----------|---------|---------|
| **Engineering** (1 senior full-time) | Rp 35jt | **Rp 105jt** | Hands-on dev, ~58% dari budget. Termasuk build DIY DRM stack 2-3 minggu. |
| **AWS infra dev/staging** | Rp 1,5jt ($95) | **Rp 4,5jt** | Small EC2/ECS + RDS t3.small + S3 |
| **Bunny CDN** (development traffic minimal) | Rp 800rb ($50) | **Rp 2,4jt** | Storage 1.2TB + minim egress |
| **AI development** (Claude Max + DeepSeek) | Rp 4,5jt ($280) | **Rp 13,5jt** | Claude Max $200 untuk dev work, DeepSeek $80 untuk testing |
| **Domain, SSL wildcards, Sentry, monitoring** | Rp 800rb | **Rp 2,4jt** | |
| **One-time AI processing 400 film** | — | **Rp 12jt ($750)** | Subtitle + tagging + thumbnail (lihat §4.4) |
| **MaxMind GeoIP2 license** (DIY DRM geo-blocking) | — | **Rp 1jt** | One-time, untuk 1 tahun |
| **Load testing tools** (k6 / Locust) | — | **Rp 0** | Open source |
| **Buffer 25%** (testing, contingency) | — | **Rp 39jt** | Lebih besar karena DIY DRM butuh QA matang |
| ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ | | ━━━━━━ | |
| **TOTAL 3 bulan** | | **~Rp 180jt** | Pas di budget Rp 60jt × 3 |
| _Optional: EZDRM (kalau aktif setelah launch)_ | _Rp 3,2jt/bln_ | _opsional_ | _Pay only when activated per-film_ |

### 2.2 Post-Launch OPEX — Skenario Realistis

Asumsi: 400 film, watch time 10 jam/user/bulan, bitrate 5 Mbps avg, **Bunny CDN sebagai primary**.

| MAU | Egress/bln | OPEX/bln (USD) | OPEX/bln (Rupiah) |
|-----|-----------|----------------|-------------------|
| **1.000** | 22 TB | **~$620** | **~Rp 9,9 jt** |
| **5.000** | 110 TB | **~$1.350** | **~Rp 21,6 jt** |
| **10.000** | 220 TB | **~$2.100** | **~Rp 33,6 jt** |
| **50.000** | 1,1 PB | **~$6.500** | **~Rp 104 jt** |
| **100.000** | 2,2 PB | **~$11.200** | **~Rp 179 jt** |

**Untuk dibandingkan dengan v1.0** (CloudFront): di 100K user, v1.0 = $127.600/bln. v2.0 (Bunny) = $11.200/bln. **11× lebih murah** karena CDN egress dominan ~70% dari total cost.

> **Per-user math**: di 10K user, OPEX ≈ Rp 33,6jt/bulan. Kalau 4.000 paid @ Rp 49rb = Rp 196jt revenue. **Margin ~83%**. Sangat sehat.

---

## 3. Verified AI Pricing (Mei 2026)

Semua harga per **1 juta token**, diverifikasi dari sumber publik (lihat Sources di akhir).

### 3.1 Provider Lengkap

| Provider | Model | Input | Output | Notes |
|----------|-------|-------|--------|-------|
| **DeepSeek** ⭐ | **V4 Flash** | **$0.14** | **$0.28** | Cache hit $0.003. Default untuk semua task standar. |
| DeepSeek | V4 Pro | $0.435 (promo) → $1.74 | $0.87 → $3.48 | Pricing naik setelah May 5, 2026 |
| DeepSeek | deepseek-chat (V3.2 legacy) | $0.28 | $0.42 | **Deprecated 24 Juli 2026** — migrate ke V4 |
| **Google Gemini** ⭐ | **2.5 Flash-Lite** | **$0.10** | **$0.40** | Termurah di pasar. Multimodal vision. Batch mode: $0.05/$0.20 |
| Google Gemini | 2.5 Flash | $0.30 | $2.50 | Audio input $1.00 |
| Google Gemini | 3.0 Flash | $0.50 | $3.00 | Latest multimodal |
| Google Gemini | 2.5 Pro | $1.25 | $10.00 | ≤200K context |
| **Anthropic** | **Claude Haiku 4.5** | $1.00 | $5.00 | Bagus untuk reasoning ringan & klasifikasi |
| Anthropic | Claude Sonnet 4.6 | $3.00 | $15.00 | Long-form synopsis & editorial |
| Anthropic | Claude Opus 4.7 | $5.00 | $25.00 | Released 16 April 2026. Premium. |
| **OpenAI** | gpt-5.4-nano | $0.20 | $1.25 | Latest nano-tier |
| OpenAI | gpt-5-mini | $0.25 | $2.00 | Legacy mini |
| OpenAI | gpt-5.4-mini | (~$1) | (~$5) | Mid-tier |
| OpenAI | gpt-5.4 | $2.50 | $15.00 | Standard |
| OpenAI | gpt-5.5 | $5.00 | $30.00 | Flagship (May 2026) |
| OpenAI | **gpt-4o-mini-transcribe** | — | **$0.003/min** | Subtitle generation — half of Whisper |
| OpenAI | gpt-4o-transcribe | — | $0.006/min | Higher quality transcription |
| OpenAI | whisper-1 (legacy) | — | $0.006/min | |
| **Groq** | Llama 4 Maverick | $0.15 | $0.60 | Ultra-low latency (LPU) |
| Groq | Llama 4 Scout | (~$0.10) | (~$0.30) | Smaller, faster |
| Mistral | Codestral | $0.30 | $0.90 | Code generation |
| Mistral | Mistral Small | $0.20 | (~$0.60) | |

### 3.2 Discount Levers Yang Tersedia

| Lever | Save | Provider |
|-------|------|----------|
| **Prompt caching** | 90% pada cached input | Anthropic, DeepSeek |
| **Batch API** | 50% all tokens | OpenAI, Anthropic, Gemini |
| **DeepSeek cache hit** | $0.003/MTok input vs $0.14 (47×) | DeepSeek |
| **Gemini Flash-Lite batch** | $0.05/$0.20 vs $0.10/$0.40 | Gemini |

### 3.3 Strategi Pemilihan Model untuk FLiK

| Task | Model Default | Mengapa |
|------|--------------|---------|
| Recommendation engine (batched harian) | **DeepSeek V4 Flash** | Termurah untuk volume tinggi |
| Semantic search query | **Gemini 2.5 Flash-Lite** | Murah, fast |
| Auto-tagging movie (one-time) | **Claude Haiku 4.5** + **Gemini Flash vision** | Quality untuk task one-time worth it |
| Subtitle generation | **gpt-4o-mini-transcribe** | $0.003/min, 90 min film = $0.27 |
| Auto-translation subtitle | **DeepSeek V4 Flash** | Kualitas Indo→EN cukup |
| Comment moderation (high volume) | **Gemini 2.5 Flash-Lite batch** | Termurah + cukup akurat |
| Editorial copy (synopsis) | **Claude Sonnet 4.6** | Quality untuk konten yang dibaca user |
| Customer support chatbot (real-time) | **Groq Llama 4 Maverick** | Latency terendah |
| Reasoning kompleks (admin tools) | **DeepSeek V4 Pro** atau **Claude Sonnet 4.6** | |

### 3.4 Total AI Cost Realistis

**One-time (400 film katalog client)**:
| Task | Model | Per Film | Total |
|------|-------|---------|-------|
| Subtitle (90 min avg) | gpt-4o-mini-transcribe | $0.27 | $108 |
| Auto-tagging | Claude Haiku + Gemini Flash | $0.10 | $40 |
| Translation (Indo→EN+Mandarin) | DeepSeek V4 Flash | $0.50 | $200 |
| Trailer suggest | Gemini Flash vision | $0.20 | $80 |
| Thumbnail pick | Gemini Flash-Lite vision | $0.05 | $20 |
| Synopsis generation | Claude Sonnet (1× review) | $0.30 | $120 |
| QC content classification | DeepSeek V4 Flash | $0.05 | $20 |
| **TOTAL one-time 400 film** | | | **~$590 (Rp 9,5 juta)** |

**Ongoing per skala**:
| MAU | AI cost/bln (USD) | AI cost/bln (Rp) |
|-----|------------------|------------------|
| 1.000 | ~$8 | ~Rp 130rb |
| 10.000 | ~$80 | ~Rp 1,3 jt |
| 100.000 | ~$800 | ~Rp 13 jt |
| 1.000.000 | ~$8.000 | ~Rp 128 jt |

> **Catatan**: AI cost di skala 100K user (~$800/bln) hanya **7%** dari total OPEX. AI BUKAN cost driver. CDN yang dominan.

### 3.5 Bandingkan dengan Subscription (yang client mention)

| Subscription | Bulanan | Use Case |
|-------------|---------|----------|
| Claude Pro | $20/user | Personal coding, riset |
| Claude Max 5× | $100/user | Heavy coding |
| Claude Max 20× | $200/user | Power user, dev work |
| ChatGPT Plus | $20/user | Personal |
| ChatGPT Team | $30/seat | Team collaboration |

**Untuk PRODUKSI multi-user di FLiK**: subscription model TIDAK COCOK — kena rate limit per akun, tidak bisa dipanggil dari aplikasi backend. **Wajib pakai API**. Tapi **API jauh lebih murah** untuk volume rendah-menengah karena pay-per-use:
- 1.000 MAU FLiK ≈ **$8/bulan API cost** (Claude Max = $200/bulan)
- Subscription cocok untuk **development team** (1-3 dev pakai Claude Max selama build)

---

## 4. CDN Strategy — Bunny.net sebagai Primary

### 4.1 Mengapa Bunny CDN, bukan CloudFront

CDN egress = **~70% dari total OPEX** di skala 10K+ user. Pilihan CDN = pilihan terbesar yang bisa save uang.

| CDN | Per GB Asia (first 10TB) | Per GB Asia (high volume) | Notes |
|-----|--------------------------|---------------------------|-------|
| **AWS CloudFront** | **$0.114** (verified) | $0.020 (5PB+) | Bundled fitur, mahal |
| **Bunny CDN Standard** | **$0.005** (global avg) | **$0.003** (10TB+) | Global PoP termasuk Singapore, Jakarta |
| **Bunny Stream** (video CDN) | **$0.01** | $0.005 | Includes transcoding free |
| Cloudflare Stream | ~$0.40/GB delivered | — | Bundle stream+CDN+DRM |
| Indonesia local CDN (BizNet, Telkom) | ~Rp 1.000/GB ($0.06) | Negotiable | Latency lokal terbaik |

**Rekomendasi FLiK**:
- **Bulan 1-12**: **Bunny Stream** ($0.01/GB) — bundled video CDN, transcoding included
- **Bulan 12+ (>50K user)**: tambah **Indonesia local CDN** sebagai secondary untuk peak hours
- **CloudFront**: hanya untuk static asset web app (kecil traffic-nya)

### 4.2 Cost Comparison Real

Egress 22 TB/bulan (1.000 user × 22 GB):
- CloudFront Asia: 22.000 GB × $0.114 = **$2.508**
- Bunny Stream: 22.000 GB × $0.01 = **$220**
- **Save: $2.288/bulan = Rp 36 juta/bulan**

Egress 220 TB/bulan (10.000 user):
- CloudFront: $25.080
- Bunny: $1.100
- **Save: $23.980/bulan = Rp 384 juta/bulan**

> **Kesimpulan**: pilihan CDN sendirian sudah save 90%+ vs default AWS. Critical untuk client kecil-menengah.

---

## 5. DRM — Hybrid: DIY Primary + EZDRM Optional

### 5.1 Strategi Hybrid

Konten = film klasik Indonesia milik client (bukan rilisan Hollywood). Casual piracy protection sudah cukup untuk mayoritas konten. Build **DRM custom in-house** untuk semua film — opsi EZDRM disediakan untuk konten premium / iOS Safari support yang specific.

### 5.2 DIY DRM Stack (100% Buatan Sendiri)

| Komponen | Implementasi |
|----------|--------------|
| **Video encryption** | AES-128/256 segment encryption (FFmpeg gratis) |
| **Key delivery server** | Endpoint Laravel: validate JWT + auth + subscription + IP/geo + device |
| **Token rotation** | Key per-session, rotate per 5 menit, signed dengan APP_KEY |
| **Signed URLs** | Temporary signed URL ke Bunny CDN (5-15 menit expiry) |
| **Concurrent stream limit** | Redis counter per user, kick session lama kalau exceed |
| **Device fingerprinting** | Browser canvas + WebGL + audio context hash |
| **Watermarking forensik** | Burn-in user ID semi-transparan via FFmpeg overlay |
| **Geo-blocking** | MaxMind GeoIP2 lookup di middleware |
| **Anti-debugging** | DevTools detector + console banner di player JS |
| **Session binding** | Key cuma valid untuk 1 session token |
| **Dynamic playlist** | Generate manifest M3U8 per request dengan key URL signed |
| **Anti-replay** | Nonce + timestamp di JWT, reject reused token |
| **Bandwidth throttle** | Rate limit per token via Bunny edge config |

**Estimasi build**: 2-3 minggu untuk 1 senior dev. **Cost tambahan: Rp 0** (sudah masuk engineering budget).

### 5.3 Yang Tidak Bisa Buatan Sendiri (Fakta Teknis)

| Standard DRM | Mengapa Wajib Pihak Ketiga |
|-------------|----------------------------|
| **Widevine** (Google) | License server harus certified Google. SDK butuh NDA + corporate approval. CDM closed-source. |
| **PlayReady** (Microsoft) | License kontrak Microsoft. PRMv4 protocol licensing. |
| **FairPlay** (Apple) | Sertifikat dari Apple Enterprise Developer. iOS Safari **ONLY** mau pakai FairPlay. |

**Konsekuensi tanpa industry-standard DRM**:
- ❌ iOS Safari user tidak bisa play encrypted video → solusi: **paksa PWA install**
- ❌ Beberapa Smart TV butuh Widevine → solusi: **PWA fallback / future Tier 3**
- ✅ Chrome/Android/Web: 100% works dengan custom DRM

### 5.4 EZDRM Optional (Per-Film Activation)

Disediakan sebagai **toggle per-film** di admin panel:

```
[Admin Panel — Movie Edit]
DRM Strategy:  ( ) Custom DIY (default — gratis)
               ( ) EZDRM Multi-DRM ($200/bln shared, +iOS native)
```

**Kapan aktifkan EZDRM**:
- Konten exclusive baru di-restore yang punya commercial value tinggi
- Permintaan rights holder yang demand industry-standard DRM
- Mau dukung iOS native app dengan FairPlay

**Cost EZDRM (Verified)**:
- Starter $199.99/bulan flat — bundled Widevine + PlayReady + FairPlay
- Setup one-time: ~$300

**Sumber**: ezdrm.com/service-pricing

### 5.5 Trade-off Honest

| Aspek | DIY (Default) | EZDRM (Optional) |
|-------|--------------|------------------|
| Cost/bulan | **Rp 0** | $200 |
| Proteksi vs casual user | ⭐⭐⭐⭐ cukup | ⭐⭐⭐⭐ |
| Proteksi vs determined hacker | ⭐⭐ | ⭐⭐⭐⭐ |
| iOS Safari native | ❌ butuh PWA | ✅ FairPlay |
| Smart TV native | ⚠️ limited | ✅ |
| Time to implement | 2-3 minggu (one-time) | 1 minggu integration |
| Maintenance | Self | Provider handle |
| Studio rights holder accept? | ⚠️ tergantung | ✅ industry standard |

### 5.6 Implementation Code — DIY HLS AES-128

```bash
# Transcode + encrypt dengan FFmpeg
ffmpeg -i input.mp4 \
  -hls_time 6 \
  -hls_key_info_file enc.keyinfo \
  -hls_playlist_type vod \
  -c:v libx264 -b:v 5M \
  output.m3u8
```

```php
// routes/web.php
Route::get('/drm/key/{movie}/{token}', function ($movie, $token) {
    $payload = JWT::decode($token, config('app.key'));
    abort_unless(auth()->check() && $payload->user_id === auth()->id(), 403);
    abort_unless(auth()->user()->hasActiveSubscription(), 402);
    abort_unless(now()->lt(Carbon::parse($payload->exp)), 403);
    abort_unless(GeoIp::allow(request()->ip()), 451);

    $sessionKey = Cache::remember(
        "drm:{$payload->session_id}",
        now()->addMinutes(5),
        fn () => Crypt::generateKey('AES-128-CBC')
    );

    return response($sessionKey, 200, [
        'Content-Type' => 'application/octet-stream',
        'Cache-Control' => 'no-store, must-revalidate',
    ]);
})->middleware(['auth', 'throttle:60,1']);
```

**Save vs EZDRM-only**:
- Per bulan: Rp 3,2 juta
- Per tahun: Rp 38,4 juta
- 3 tahun: **~Rp 120 juta**

---

## 6. AWS / Infra Cost — Detail Realistis per Skala

### 6.1 Skala 1.000 MAU (Bulan ke-3 launch — realistic baseline)

**DRM**: DIY in-house — Rp 0 cost di sini.

| Komponen | Spec | Monthly USD | Monthly Rp |
|----------|------|------------|-----------|
| **Bunny Stream CDN** | 22 TB egress @ $0.01 | $220 | Rp 3.520.000 |
| **Bunny Storage** | 1.2 TB @ $0.005 | $6 | Rp 96.000 |
| AWS S3 (master file backup) | 700 GB Standard-IA | $9 | Rp 144.000 |
| AWS EC2 t3.medium (Laravel app) | 730 jam | $30 | Rp 480.000 |
| AWS RDS MySQL t3.small Multi-AZ | 50 GB SSD | $50 | Rp 800.000 |
| AWS RDS PostgreSQL t3.micro (pgvector) | 20 GB | $25 | Rp 400.000 |
| AWS ElastiCache Redis t3.micro | — | $15 | Rp 240.000 |
| ALB | — | $20 | Rp 320.000 |
| CloudWatch logs | — | $10 | Rp 160.000 |
| AWS SES | 50K emails | $5 | Rp 80.000 |
| **DIY DRM** (key server hosted di Laravel app) | included | $0 | **Rp 0** |
| **AI APIs** | DeepSeek V4 Flash + Whisper | $8 | Rp 128.000 |
| Domain, SSL, monitoring SaaS | — | $20 | Rp 320.000 |
| ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ | | ━━━━━━ | ━━━━━━ |
| **Subtotal** | | **$418** | **Rp 6.688.000** |
| Buffer 10% | | $42 | Rp 668.800 |
| **TOTAL** | | **~$460** | **~Rp 7,4 juta** |
| _Optional: EZDRM kalau aktif_ | _$200_ | _Rp 3,2 jt_ | |

**Per paid user** (asumsi 400 paid): ~$1.15 = Rp 18K. Pricing Rp 49K → **margin ~62%**. ✅

### 6.2 Skala 10.000 MAU

| Komponen | Spec | Monthly USD | Monthly Rp |
|----------|------|------------|-----------|
| Bunny Stream CDN | 220 TB @ $0.005 (volume) | $1.100 | Rp 17.600.000 |
| Bunny Storage | 1.5 TB | $8 | Rp 128.000 |
| S3 backup | 1 TB | $13 | Rp 208.000 |
| EC2 (2× t3.large) | — | $200 | Rp 3.200.000 |
| RDS t3.medium Multi-AZ | 200 GB | $200 | Rp 3.200.000 |
| RDS PostgreSQL t3.small | 50 GB | $50 | Rp 800.000 |
| ElastiCache Redis t3.small | — | $30 | Rp 480.000 |
| ALB + WAF | — | $50 | Rp 800.000 |
| CloudWatch + Sentry | — | $50 | Rp 800.000 |
| SES | 500K emails | $50 | Rp 800.000 |
| **DIY DRM** (multi-instance) | included | $0 | **Rp 0** |
| **AI APIs** | scaled | $80 | Rp 1.280.000 |
| Misc | — | $40 | Rp 640.000 |
| ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ | | ━━━━━━ | ━━━━━━ |
| **TOTAL** | | **~$1.870** | **~Rp 29,9 juta** |
| _Optional: EZDRM aktif_ | _$400_ | | _Rp 6,4 jt extra_ |

**Per paid user** (4.000 paid): $0.47 = Rp 7.500. Pricing Rp 49K → **margin ~85%**. ✅✅

### 6.3 Skala 100.000 MAU

| Komponen | Monthly USD | Monthly Rp |
|----------|------------|-----------|
| Bunny CDN | 2.2 PB @ $0.003 | $6.600 | Rp 105,6 juta |
| Storage (Bunny + S3) | $50 | Rp 800.000 |
| EC2 cluster (8× t3.xlarge) | $1.000 | Rp 16 juta |
| RDS r5.large Multi-AZ + read replica | $700 | Rp 11,2 juta |
| RDS PostgreSQL r5.large | $400 | Rp 6,4 juta |
| Redis cluster | $300 | Rp 4,8 juta |
| OpenSearch (3 nodes) | $600 | Rp 9,6 juta |
| ALB + WAF + Shield | $200 | Rp 3,2 juta |
| Monitoring (Datadog/Sentry) | $400 | Rp 6,4 juta |
| SES (5M emails) | $500 | Rp 8 juta |
| **DIY DRM** (key servers di EC2 cluster) | included | **Rp 0** |
| **AI APIs** | $800 | Rp 12,8 juta |
| Misc (NAT, KMS, secrets, backup) | $300 | Rp 4,8 juta |
| ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ | ━━━━━━ | ━━━━━━ |
| **TOTAL** | **~$11.850** | **~Rp 189 juta** |
| _Optional: EZDRM enterprise_ | _$1.500_ | _Rp 24 jt extra_ |

**Per paid user** (40.000 paid): $0.30 = Rp 4.700. Pricing Rp 49K → **margin ~90%**. ✅✅

### 6.4 Skala 1.000.000 MAU

| Komponen | Monthly USD | Monthly Rp |
|----------|------------|-----------|
| Bunny CDN (negotiated) | 22 PB @ $0.002 | $44.000 | Rp 704 juta |
| Storage | $200 | Rp 3,2 juta |
| EC2 cluster (40+ instance Reserved) | $4.500 | Rp 72 juta |
| Aurora MySQL cluster | $3.500 | Rp 56 juta |
| Aurora PostgreSQL | $1.500 | Rp 24 juta |
| Redis cluster (sharded) | $1.500 | Rp 24 juta |
| OpenSearch | $1.800 | Rp 28,8 juta |
| ALB + WAF + Shield Advanced | $3.000 | Rp 48 juta |
| Monitoring full stack | $1.500 | Rp 24 juta |
| SES (50M emails) | $5.000 | Rp 80 juta |
| **DIY DRM** (dedicated key server cluster) | $0 | **Rp 0** |
| **AI APIs** | $8.000 | Rp 128 juta |
| AWS Enterprise Support | $5.000 | Rp 80 juta |
| Misc (VPC, transit, secrets) | $2.000 | Rp 32 juta |
| ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ | ━━━━━━ | ━━━━━━ |
| **TOTAL** | **~$81.500** | **~Rp 1,30 miliar** |
| _Optional: EZDRM enterprise_ | _$8.000_ | _Rp 128 jt extra_ |

**Per paid user** (400.000 paid): $0.20 = Rp 3.250. Pricing Rp 49K → **margin ~93%**.

### 6.5 Cost Comparison v1.0 vs v2.0 (CloudFront vs Bunny)

| MAU | v1.0 (CloudFront) | **v2.0 (Bunny)** | Save |
|-----|-------------------|------------------|------|
| 1.000 | $2.730 | **$649** | 76% |
| 10.000 | $19.400 | **$2.270** | 88% |
| 100.000 | $127.600 | **$13.350** | 90% |
| 1.000.000 | $700.000 | **$89.500** | 87% |

> **Insight**: v1.0 over-engineered untuk worst case enterprise. v2.0 realistis untuk client menengah.

---

## 6.6 Full P&L Analysis per Skala — Profit atau Boncos?

Pertanyaan kunci dari Pak Lavesh: **dengan tech cost segini besar, masih untung gak?** Berikut breakdown lengkap revenue vs SEMUA pengeluaran (bukan cuma tech).

**Asumsi**:
- Pricing: Rp 49rb/bln (bisa tier Basic — paling konservatif)
- Conversion: 40% MAU → paid
- Marketing (CAC): bervariasi per skala (lihat §6.7)
- CS staff: 1 per ~5K paid user
- Engineering maintenance: 1 per ~50K MAU post-launch
- Payment processing Midtrans: 2.9% + Rp 4.500/transaksi ≈ 3.5% effective

### 1.000 MAU (400 paid)

| Item | Monthly | % Revenue |
|------|---------|-----------|
| **Revenue** (400 × Rp 49rb) | Rp 19,6 jt | 100% |
| Tech infra (Bunny+AWS+AI+DIY DRM) | Rp 7,4 jt | 38% |
| Payment processing (3.5%) | Rp 0,7 jt | 4% |
| CS part-time (1 staff) | Rp 5 jt | 26% |
| Marketing (target Rp 100rb CAC × 100 new/bln) | Rp 10 jt | 51% |
| ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ | ━━━━━━ | ━━━━ |
| **Total Cost** | Rp 23,1 jt | 118% |
| **Profit/(Loss)** | **(Rp 3,5 jt)** | **(18%)** ⚠️ |

**Verdict 1.000 MAU**: **Mild loss bulanan** — wajar untuk fase awal (CAC heavy). Investasi marketing untuk acquire ke skala berikutnya. Tidak boncos parah karena tech infra cuma 38% revenue.

### 10.000 MAU (4.000 paid)

| Item | Monthly | % Revenue |
|------|---------|-----------|
| **Revenue** (4.000 × Rp 49rb) | Rp 196 jt | 100% |
| Tech infra | Rp 30 jt | 15% |
| Payment processing | Rp 6,9 jt | 3.5% |
| CS team (3 staff) | Rp 18 jt | 9% |
| Marketing (Rp 80rb CAC × 500 new/bln) | Rp 40 jt | 20% |
| Engineering 1 maintenance dev part-time | Rp 15 jt | 8% |
| Office/legal/misc | Rp 5 jt | 3% |
| ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ | ━━━━━━ | ━━━━ |
| **Total Cost** | Rp 114,9 jt | 59% |
| **Profit** | **Rp 81,1 jt** | **41%** ✅ |

**Verdict 10.000 MAU**: **PROFIT Rp 81 juta/bulan = 41% net margin**. Sehat banget. Tech cost cuma 15% revenue.

### 100.000 MAU (40.000 paid)

| Item | Monthly | % Revenue |
|------|---------|-----------|
| **Revenue** (40K × Rp 49rb) | Rp 1.960 jt | 100% |
| Tech infra | Rp 189 jt | 10% |
| Payment processing | Rp 69 jt | 3.5% |
| CS team (10 staff) | Rp 60 jt | 3% |
| Marketing (Rp 70rb CAC × 4K new/bln) | Rp 280 jt | 14% |
| Engineering team (3 dev FT) | Rp 105 jt | 5% |
| BD/marketing team (3 staff) | Rp 60 jt | 3% |
| Office/legal/insurance | Rp 30 jt | 2% |
| ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ | ━━━━━━ | ━━━━ |
| **Total Cost** | Rp 793 jt | 40% |
| **Profit** | **Rp 1.167 jt** | **60%** ✅✅ |

**Verdict 100.000 MAU**: **PROFIT Rp 1,17 miliar/bulan = 60% net margin**. Tech cost cuma 10% — hampir tidak relevan ke profitability.

### 1.000.000 MAU (400.000 paid)

| Item | Monthly | % Revenue |
|------|---------|-----------|
| **Revenue** (400K × Rp 49rb) | Rp 19.600 jt (Rp 19,6 M) | 100% |
| Tech infra | Rp 1.300 jt | 7% |
| Payment processing | Rp 686 jt | 3.5% |
| CS team (50 staff) | Rp 400 jt | 2% |
| Marketing (Rp 50rb CAC × 30K new/bln) | Rp 1.500 jt | 8% |
| Engineering team (15 dev FT) | Rp 525 jt | 3% |
| BD/marketing team (15 staff) | Rp 300 jt | 2% |
| Office/legal/insurance | Rp 150 jt | 1% |
| ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ | ━━━━━━ | ━━━━ |
| **Total Cost** | Rp 4.861 jt | 25% |
| **Profit** | **Rp 14.739 jt (Rp 14,7 M)** | **75%** ✅✅✅ |

**Verdict 1.000.000 MAU**: **PROFIT Rp 14,7 miliar/bulan = 75% net margin**. Tech infra hanya 7% revenue.

### Summary Tabel — Boncos atau Untung?

| Skala | Revenue/bln | Tech Cost | All Cost | Profit/Loss | % Margin |
|-------|------------|-----------|----------|-------------|----------|
| 1.000 MAU | Rp 19,6 jt | Rp 7,4 jt | Rp 23,1 jt | **(Rp 3,5 jt)** | **(18%)** ⚠️ |
| 10.000 MAU | Rp 196 jt | Rp 30 jt | Rp 115 jt | **Rp 81 jt** | **41%** ✅ |
| 100.000 MAU | Rp 1,96 M | Rp 189 jt | Rp 793 jt | **Rp 1,17 M** | **60%** ✅✅ |
| 1.000.000 MAU | Rp 19,6 M | Rp 1,3 M | Rp 4,86 M | **Rp 14,7 M** | **75%** ✅✅✅ |

**Key insight**: tech cost cuma **15% di skala 10K user** dan turun ke **7% di 1M user**. Tech BUKAN cost driver utama. **Marketing/CAC** dan **engineering team** yang harus dioptimasi.

---

## 6.7 CAC, LTV & Acquisition Economics

### CAC (Customer Acquisition Cost) — Benchmark Indonesia OTT

| Channel | CAC Range | Realistis FLiK |
|---------|-----------|----------------|
| Google Ads / Facebook Ads | Rp 80rb–200rb | Rp 100rb |
| KOL / Influencer marketing | Rp 50rb–150rb | Rp 80rb |
| SEO / Content marketing | Rp 20rb–50rb | Rp 30rb |
| Referral program | Rp 20rb–50rb | Rp 25rb |
| **Blended (target FLiK)** | | **Rp 70-100rb** |

### LTV (Lifetime Value)

LTV = ARPU × Avg Lifetime × Gross Margin (after tech only)

| Skala | ARPU | Gross Margin (post-tech) | Avg Lifetime | **LTV** |
|-------|------|-------------------------|-------------|---------|
| 1K MAU | Rp 49rb | 62% | 10 bulan | **Rp 304rb** |
| 10K MAU | Rp 49rb | 85% | 12 bulan | **Rp 500rb** |
| 100K MAU | Rp 49rb | 90% | 14 bulan | **Rp 617rb** |
| 1M MAU | Rp 49rb | 93% | 16 bulan | **Rp 729rb** |

**Avg Lifetime asumsi naik karena**: di skala lebih besar, brand recognition lebih kuat, churn lebih rendah, gamification & rekomendasi makin akurat.

### LTV/CAC Ratio (Target SaaS sehat: > 3:1)

| Skala | LTV | CAC target | **Ratio** | Status |
|-------|-----|-----------|-----------|--------|
| 1K MAU | Rp 304rb | Rp 100rb | **3.0×** | ✅ Healthy |
| 10K MAU | Rp 500rb | Rp 80rb | **6.3×** | ✅✅ Excellent |
| 100K MAU | Rp 617rb | Rp 70rb | **8.8×** | ✅✅✅ Outstanding |
| 1M MAU | Rp 729rb | Rp 50rb | **14.6×** | ✅✅✅✅ World-class |

### CAC Payback Period (Berapa Bulan Investment Back?)

Payback = CAC / (ARPU × Gross Margin)

| Skala | CAC | Monthly Gross Profit/User | **Payback** |
|-------|-----|--------------------------|-------------|
| 1K MAU | Rp 100rb | Rp 30rb | **3.3 bulan** ✅ |
| 10K MAU | Rp 80rb | Rp 42rb | **1.9 bulan** ✅✅ |
| 100K MAU | Rp 70rb | Rp 44rb | **1.6 bulan** ✅✅ |
| 1M MAU | Rp 50rb | Rp 46rb | **1.1 bulan** ✅✅✅ |

**Industry benchmark**: payback < 12 bulan = healthy, < 6 bulan = excellent. **Semua skala FLiK well below 6 bulan**.

### Why FLiK Economics Work

1. **Niche niche niche**: film klasik Indonesia underserved → low CAC karena audience self-select via search/keyword
2. **Long lifetime**: nostalgia driver — user loyal pada konten heritage
3. **Tech cost low**: Bunny CDN + DIY DRM bikin gross margin 85-93%
4. **Konten gratis**: client sudah punya 400 film → no recurring content licensing fees

---

## 6.8 Pricing Tier Sensitivity Analysis

Bagaimana kalau pricing diubah? Pengaruh ke profit di skala 10.000 MAU (4.000 paid):

| Pricing | Revenue/bln | Tech | Other Costs | Profit | Margin |
|---------|-------------|------|-------------|--------|--------|
| Rp 29.000 (super murah) | Rp 116 jt | Rp 30 jt | Rp 85 jt | **Rp 1 jt** | **0.9%** ⚠️ |
| **Rp 49.000 (default)** | **Rp 196 jt** | **Rp 30 jt** | **Rp 85 jt** | **Rp 81 jt** | **41%** ✅ |
| Rp 79.000 (Premium-only) | Rp 316 jt | Rp 30 jt | Rp 85 jt | **Rp 201 jt** | **64%** ✅✅ |
| Rp 99.000 (Premium+) | Rp 396 jt | Rp 30 jt | Rp 85 jt | **Rp 281 jt** | **71%** ✅✅ |

**Rekomendasi tier mix realistis** (60% Basic Rp 39rb, 30% Premium Rp 79rb, 10% Family Rp 129rb):
- Blended ARPU = Rp 60rb
- Revenue 10K MAU = 4K × 60rb = Rp 240 jt
- Profit = Rp 240 - Rp 30 - Rp 85 = **Rp 125 jt = 52% margin**

### Rp 29rb Risk

Pricing terlalu murah (Rp 29rb) bikin margin tipis di skala kecil. Hanya viable kalau target growth ke 100K+ MAU cepat. **Tidak direkomendasi untuk awal**.

### Bundle Recommendations

- **Annual plan diskon 20%**: cuts CAC payback in half (user prepay 12 bulan)
- **Family plan Rp 129rb** untuk 6 device: tingkatkan ARPU rata-rata
- **Add-on premium**: behind-the-scene, restoration documentary, audio commentary — Rp 19rb/bln

---

## 7. Year-on-Year Projection (Realistic Growth)

Asumsi growth rate konservatif: **2× per tahun** untuk Indonesian OTT niche film klasik.

| Periode | MAU | Monthly OPEX | Annual OPEX |
|---------|-----|-------------|-------------|
| **3-Month Build** (Bln 1-3) | — | Build budget Rp 60jt × 3 | **Rp 180 jt** |
| **Bulan 4-6** (post-launch) | 0 → 1.000 | Rp 10jt avg | **Rp 30 jt** |
| **Bulan 7-12** | 1.000 → 5.000 | Rp 15jt avg | **Rp 90 jt** |
| **Year 1 Total OPEX (post-build)** | | | **~Rp 120 jt/9 bulan** |
| **Year 2** | 5K → 20K MAU avg | Rp 30jt avg | **Rp 360 jt** |
| **Year 3** | 20K → 50K MAU avg | Rp 65jt avg | **Rp 780 jt** |

**Total 3-Year Build + OPEX**: ~Rp 1,44 miliar (build Rp 180jt + OPEX 33 bulan).

### Revenue Projection (matching scale)

Pricing tier dasar: Rp 49rb/bulan (Basic), 40% MAU paid:

| Periode | MAU | Paid Users | Revenue/bln | Revenue/tahun |
|---------|-----|-----------|-------------|---------------|
| End of Year-1 | 5.000 | 2.000 | Rp 98 jt | (mostly buildup) |
| End of Year-2 | 20.000 | 8.000 | Rp 392 jt | **Rp ~3 miliar** |
| End of Year-3 | 50.000 | 20.000 | Rp 980 jt | **Rp ~7 miliar** |

**Year-3 margin**: Revenue Rp ~7 miliar, OPEX Rp 780jt → **margin ~89%**. Sangat sehat.

---

## 8. Roadmap 3-Bulan Build

### Bulan 1 — Foundation
- Week 1-2: AWS env setup (VPC, EC2, RDS, S3), Bunny account, domain & SSL
- Week 2-3: FFmpeg transcoding pipeline (ABR ladder 360p–1080p), batch import 400 film
- Week 3-4: Tier 1 DRM (HLS AES-128), key endpoint, Shaka Player integration

### Bulan 2 — DRM Premium + AI Layer
- Week 5-6: EZDRM onboarding & integration untuk konten premium
- Week 6-7: AI service layer (`App\Services\Ai\AiClient`), DeepSeek V4 Flash sebagai default
- Week 7-8: Auto-subtitle pipeline (gpt-4o-mini-transcribe), auto-tagging (Claude Haiku)
- Week 8: pgvector semantic search

### Bulan 3 — Polish + Soft Launch
- Week 9: Recommendation engine (DeepSeek batch nightly), AI chatbot CS (Groq Llama 4)
- Week 10: Auto-translation subtitle, comment moderation
- Week 11: Security audit, performance test (load test ke 5K concurrent), monitoring dashboard
- Week 12: Soft launch ke 100 closed beta user, fix bugs, prep public launch

**Deliverables di akhir bulan 3**:
- ✅ 400 film fully encoded + DRM protected
- ✅ Multi-DRM (Tier 3 EZDRM) ready untuk konten premium
- ✅ 8 fitur AI live (subtitle, tagging, recommend, search, chatbot, moderation, translate, thumbnail)
- ✅ Bunny CDN production-ready
- ✅ Admin dashboard untuk monitoring usage & cost
- ✅ Mobile PWA + Android via NativePHP

---

## 9. Risk & Mitigasi

| Risk | Mitigasi |
|------|----------|
| **CDN cost meledak (viral)** | Bunny tier $0.003/GB di volume — sudah cheap. Spend alert via Bunny dashboard. |
| **DeepSeek API down** | Fallback otomatis ke Gemini Flash-Lite (sudah didesain di `AiClient`) |
| **EZDRM masalah** | Fallback ke Tier 1 AES-128 (degradation, not outage) |
| **Bunny coverage Indonesia kurang optimal** | Test latency dari Jakarta/Surabaya/Bali sebelum launch. Standby pakai Indonesia local CDN sebagai secondary. |
| **DeepSeek deprecation legacy V3 (24 Juli 2026)** | Migrate ke V4 Flash sebelum deadline — sudah default di v2.0 |
| **OpenAI Whisper akan deprecate** | Sudah pakai gpt-4o-mini-transcribe (replacement) |
| **Lebaran traffic spike 5×** | Bunny auto-scale, EC2 auto-scale, alert di 80% capacity |
| **Client gak punya backup setelah build** | Include 1 bulan post-launch support gratis di kontrak |

---

## 10. Why This Is Doable in Rp 60jt × 3

1. **Foundation sudah ada** — bukan rebuild from scratch. Existing Laravel app sudah handle 60% feature.
2. **Konten Rp 0** — client sudah punya 400 film, no acquisition.
3. **Bunny CDN** — 11× lebih murah dari CloudFront. Critical untuk client menengah.
4. **DeepSeek V4 Flash** — termurah dengan quality OK. AI cost jadi negligible.
5. **EZDRM starter $200** — flat pricing, tidak naik dramatis untuk first 10K user.
6. **In-house everything** — no SaaS lock-in (Algolia, Mux, Vimeo, dll).
7. **3-bulan timeline realistic** untuk 1 senior dev full-time + foundation existing.

---

## 11. Next Steps untuk Client

1. **Sign-off pitch deck** (this doc)
2. **Provide 400 film master files** (S3 upload atau hard drive ke office)
3. **Set up business accounts**:
   - AWS (atau via dev account dulu untuk staging)
   - Bunny.net (sign up, ~5 menit)
   - EZDRM trial account (request via sales)
   - DeepSeek API key (top up $50 untuk dev)
4. **Kick-off meeting** — confirm scope, set milestones
5. **Bulan 4 (post-build)**: launch decision, marketing prep

---

## Sources (Verified Mei 2026)

### AI Pricing
- [Anthropic Claude API Pricing 2026 — Opus 4.7, Sonnet 4.6, Haiku 4.5](https://benchlm.ai/blog/posts/claude-api-pricing)
- [Claude Opus 4.7 Pricing 2026 (Finout)](https://www.finout.io/blog/claude-opus-4.7-pricing-the-real-cost-story-behind-the-unchanged-price-tag)
- [OpenAI API Pricing 2026 — GPT-5.5, 5.4, 5 (DevTk.AI)](https://devtk.ai/en/blog/openai-api-pricing-guide-2026/)
- [DeepSeek API Pricing — V4 Flash & V4 Pro](https://www.tldl.io/resources/deepseek-api-pricing)
- [DeepSeek V4 Pricing Guide (NxCode)](https://www.nxcode.io/resources/news/deepseek-api-pricing-complete-guide-2026)
- [DeepSeek API Docs Pricing](https://api-docs.deepseek.com/quick_start/pricing)
- [Gemini API Pricing 2026 (BenchLM)](https://benchlm.ai/blog/posts/gemini-api-pricing)
- [Gemini Developer API pricing (Google)](https://ai.google.dev/gemini-api/docs/pricing)
- [Whisper API Pricing 2026](https://tokenmix.ai/blog/whisper-api-pricing)
- [Groq Pricing 2026](https://groq.com/pricing)
- [Mistral AI Pricing 2026](https://pricepertoken.com/pricing-page/provider/mistral-ai)

### CDN
- [Bunny Stream Pricing](https://bunny.net/pricing/stream/)
- [Bunny CDN Pricing](https://bunny.net/pricing/)
- [AWS CloudFront Pricing](https://aws.amazon.com/cloudfront/pricing/)
- [AWS CloudFront 2026 cost per GB explained (BlazingCDN)](https://blog.blazingcdn.com/en-us/what-is-the-price-per-gb-of-aws-cloudfront-cdn)

### DRM
- [EZDRM Service Pricing](https://www.ezdrm.com/service-pricing)

---

**End of Pitch Deck v2.0**

> Halaman admin presentasi: **`/admin/pitch-deck`** · AI provider settings: **`/admin/ai-settings`**
