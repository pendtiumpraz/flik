<x-admin.layout title="Pitch Deck">

    <style>
        .pd-controls { position: sticky; top: 0; z-index: 20; background: #0f0f0f; padding: 12px 0 16px; border-bottom: 1px solid #2a2a2a; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
        .pd-controls .nav-tabs { display: flex; gap: 4px; flex-wrap: wrap; }
        .pd-tab { padding: 6px 14px; background: #1a1a1a; border: 1px solid #2a2a2a; border-radius: 20px; font-size: 12px; color: #aaa; cursor: pointer; transition: all 0.2s; user-select: none; }
        .pd-tab:hover { border-color: #C5A55A; color: #fff; }
        .pd-tab.active { background: rgba(197,165,90,0.15); border-color: #C5A55A; color: #C5A55A; }

        .slide { background: linear-gradient(180deg, #1a1a1a 0%, #141414 100%); border: 1px solid #2a2a2a; border-radius: 16px; padding: 40px; margin-bottom: 24px; min-height: 400px; }
        .slide-cover { background: linear-gradient(135deg, #1a1a1a 0%, #0a0a0a 100%); border: 1px solid #C5A55A; padding: 60px 40px; text-align: center; min-height: 500px; display: flex; flex-direction: column; justify-content: center; align-items: center; }
        .slide-cover h1 { font-family: 'Outfit'; font-size: 64px; font-weight: 800; color: #C5A55A; letter-spacing: -1px; margin-bottom: 16px; }
        .slide-cover .tagline { font-size: 18px; color: #aaa; max-width: 600px; line-height: 1.6; margin-bottom: 40px; }
        .slide-cover .meta { font-size: 12px; color: #666; text-transform: uppercase; letter-spacing: 2px; }
        .slide-cover .badges { display: flex; gap: 8px; margin: 24px 0; flex-wrap: wrap; justify-content: center; }
        .slide-cover .badges span { padding: 6px 14px; background: rgba(197,165,90,0.1); border: 1px solid rgba(197,165,90,0.3); border-radius: 20px; font-size: 12px; color: #C5A55A; }

        .slide h2 { font-family: 'Outfit'; font-size: 32px; font-weight: 700; color: #fff; margin-bottom: 8px; }
        .slide .slide-num { font-size: 11px; text-transform: uppercase; letter-spacing: 2px; color: #C5A55A; font-weight: 600; margin-bottom: 8px; }
        .slide .slide-sub { color: #888; font-size: 14px; margin-bottom: 32px; line-height: 1.6; }

        .pd-table { width: 100%; border-collapse: collapse; margin: 16px 0; font-size: 13px; }
        .pd-table th { padding: 10px 14px; background: #0f0f0f; color: #C5A55A; text-transform: uppercase; font-size: 11px; letter-spacing: 1px; text-align: left; border-bottom: 1px solid #2a2a2a; }
        .pd-table td { padding: 10px 14px; border-bottom: 1px solid #1f1f1f; vertical-align: top; }
        .pd-table tr:hover td { background: #1f1f1f; }
        .pd-table tr.row-highlight td { background: rgba(197,165,90,0.06); border-left: 3px solid #C5A55A; }
        .pd-table .cost { color: #22c55e; font-weight: 600; font-family: 'Outfit'; }
        .pd-table .neg { color: #ef4444; }
        .pd-table .warn { color: #eab308; }

        .pd-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .pd-grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
        .pd-grid-4 { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }

        .pd-card { background: #0f0f0f; border: 1px solid #2a2a2a; border-radius: 12px; padding: 20px; }
        .pd-card.gold { border-color: #C5A55A; background: linear-gradient(135deg, rgba(197,165,90,0.08), transparent); }
        .pd-card h3 { font-family: 'Outfit'; font-size: 16px; color: #fff; margin-bottom: 8px; }
        .pd-card .num { font-family: 'Outfit'; font-size: 36px; font-weight: 700; color: #C5A55A; margin: 8px 0; }
        .pd-card .num.green { color: #22c55e; }
        .pd-card .num.red { color: #ef4444; }
        .pd-card p { font-size: 13px; color: #999; line-height: 1.6; }

        .pd-tier { display: flex; gap: 16px; align-items: center; padding: 16px; background: #0f0f0f; border-radius: 10px; margin-bottom: 8px; border-left: 3px solid #2a2a2a; }
        .pd-tier.t1 { border-left-color: #6b7280; }
        .pd-tier.t2 { border-left-color: #3b82f6; }
        .pd-tier.t3 { border-left-color: #C5A55A; }
        .pd-tier.t4 { border-left-color: #ef4444; }
        .pd-tier .stars { font-size: 14px; color: #C5A55A; min-width: 80px; }
        .pd-tier .info { flex: 1; }
        .pd-tier .info b { color: #fff; font-size: 14px; }
        .pd-tier .info span { display: block; font-size: 12px; color: #888; margin-top: 2px; }
        .pd-tier .price { font-family: 'Outfit'; font-weight: 600; color: #22c55e; min-width: 100px; text-align: right; }

        .ascii-arch { background: #0a0a0a; padding: 20px; border-radius: 8px; font-family: 'Consolas', 'Monaco', monospace; font-size: 11px; color: #C5A55A; overflow-x: auto; line-height: 1.4; white-space: pre; }

        .pd-callout { background: rgba(197,165,90,0.08); border: 1px solid rgba(197,165,90,0.3); border-left: 4px solid #C5A55A; padding: 16px 20px; border-radius: 8px; margin: 20px 0; }
        .pd-callout b { color: #C5A55A; }
        .pd-callout p { color: #ccc; font-size: 13px; line-height: 1.6; margin-top: 4px; }

        .pd-pill { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; margin-right: 4px; }
        .pd-pill.green { background: rgba(34,197,94,0.15); color: #22c55e; }
        .pd-pill.red { background: rgba(239,68,68,0.15); color: #ef4444; }
        .pd-pill.gold { background: rgba(197,165,90,0.15); color: #C5A55A; }
        .pd-pill.blue { background: rgba(59,130,246,0.15); color: #3b82f6; }

        ul.pd-list { list-style: none; padding: 0; }
        ul.pd-list li { padding: 8px 0; border-bottom: 1px solid #1f1f1f; color: #ccc; font-size: 13px; line-height: 1.6; }
        ul.pd-list li:before { content: "▸"; color: #C5A55A; margin-right: 10px; }

        @media print {
            .admin-sidebar, .admin-topbar, .pd-controls { display: none !important; }
            .admin-main { margin-left: 0 !important; }
            .slide { page-break-after: always; border: 1px solid #ccc !important; background: #fff !important; color: #000 !important; }
            .slide *, .slide h2, .slide h3, .slide p, .slide td, .slide th { color: #000 !important; }
        }

        @media (max-width: 768px) {
            .pd-grid-2, .pd-grid-3 { grid-template-columns: 1fr; }
            .slide { padding: 24px 20px; }
            .slide-cover h1 { font-size: 40px; }
        }
    </style>

    <!-- Controls -->
    <div class="pd-controls">
        <div class="nav-tabs">
            <span class="pd-tab active" onclick="showSection('all', this)">All</span>
            <span class="pd-tab" onclick="showSection('overview', this)">Overview</span>
            <span class="pd-tab" onclick="showSection('drm', this)">DRM</span>
            <span class="pd-tab" onclick="showSection('ai', this)">AI</span>
            <span class="pd-tab" onclick="showSection('arch', this)">Architecture</span>
            <span class="pd-tab" onclick="showSection('cost', this)">Cost</span>
            <span class="pd-tab" onclick="showSection('roadmap', this)">Roadmap</span>
        </div>
        <div style="display:flex;gap:8px">
            <a href="/PITCH_DECK.md" target="_blank" class="btn btn-ghost btn-sm">View .md</a>
            <button onclick="window.print()" class="btn btn-gold btn-sm">
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                Print
            </button>
        </div>
    </div>

    <!-- 0: COVER -->
    <div class="slide slide-cover" data-section="overview">
        <h1>FLiK</h1>
        <div class="tagline">Rumah Sinema Indonesia · Pitch Deck untuk Platform OTT Film Klasik & Jadul Indonesia</div>
        <div class="badges">
            <span>Laravel 12</span>
            <span>Multi-DRM Ready</span>
            <span>AI-Powered</span>
            <span>AWS Native</span>
            <span>NativePHP Mobile</span>
        </div>
        <div class="meta">v1.0 · 2026-05-09 · Internal & Investor Use</div>
    </div>

    <!-- 1: EXECUTIVE SUMMARY -->
    <div class="slide" data-section="overview">
        <div class="slide-num">01 — Executive Summary</div>
        <h2>Platform OTT Film Klasik Indonesia</h2>
        <p class="slide-sub">FLiK target niche film jadul yang underserved oleh Netflix/Disney+. Built on Laravel 12 dengan multi-DRM, AI personalization, dan deploy AWS-native.</p>

        <div class="pd-grid-3">
            <div class="pd-card gold">
                <h3>📚 Konten</h3>
                <div class="num">350+</div>
                <p>Film klasik Indonesia di tahun-1, target 1.000 di tahun-3. Library yang sudah didigitalkan & di-restore.</p>
            </div>
            <div class="pd-card gold">
                <h3>🛡️ Distribusi</h3>
                <div class="num">Multi-DRM</div>
                <p>HLS/DASH adaptive streaming dengan Widevine + PlayReady + FairPlay via EZDRM. CloudFront edge di Indonesia.</p>
            </div>
            <div class="pd-card gold">
                <h3>🤖 AI Layer</h3>
                <div class="num">15</div>
                <p>Fitur AI: rekomendasi, semantic search, auto-subtitle, tagging, moderation, chatbot. Multi-provider (DeepSeek/OpenAI/Claude/Gemini).</p>
            </div>
        </div>

        <div class="pd-callout" style="margin-top:24px">
            <b>Status saat ini</b>
            <p>MVP web jalan ✓ · Admin panel ✓ · Payment Midtrans ✓ · Gamification (XP/coins/achievements) ✓ · PWA ✓ · Tabel <code>ai_providers</code> dengan encrypted API keys ✓ · ~50 film di-seed ✓</p>
        </div>
    </div>

    <!-- 2: ASSUMPTIONS -->
    <div class="slide" data-section="overview">
        <div class="slide-num">02 — Asumsi & Skenario</div>
        <h2>Baseline Numbers</h2>
        <p class="slide-sub">Semua kalkulasi cost & infrastruktur menggunakan asumsi standar OTT Indonesia di bawah ini.</p>

        <table class="pd-table">
            <thead><tr><th>Parameter</th><th>Value</th><th>Sumber</th></tr></thead>
            <tbody>
                <tr><td>Jumlah film tahun-1</td><td><b>{{ $assumptions['film_count'] }}</b></td><td>Target awal katalog</td></tr>
                <tr><td>Ukuran source per film</td><td><b>{{ $assumptions['avg_size_gb'] }} GB</b></td><td>Master MP4, 1080p H.264</td></tr>
                <tr><td>Storage transcoded</td><td><b>~1.2 TB</b></td><td>5 ABR rendition × 350 film</td></tr>
                <tr><td>Watch time/user/bulan</td><td><b>{{ $assumptions['avg_hours_per_user'] }} jam</b></td><td>Indonesian OTT benchmark</td></tr>
                <tr><td>Bitrate average</td><td><b>{{ $assumptions['avg_bitrate_mbps'] }} Mbps</b> (1080p adaptive)</td><td>ABR ladder 360p–4K</td></tr>
                <tr><td>Egress per user/bulan</td><td><b>~22 GB</b></td><td>(10h × 3600 × 5Mbps) / 8 / 1024</td></tr>
                <tr><td>CDN cache hit ratio</td><td><b>{{ ($assumptions['cdn_cache_hit_ratio']*100) }}% / 70% edge</b></td><td>Konservatif (long-tail catalog)</td></tr>
                <tr><td>Region</td><td><b>ap-southeast-1</b> (Singapore)</td><td>Routing default Indonesia</td></tr>
                <tr><td>Peak/avg ratio</td><td><b>3.5×</b></td><td>Prime time + akhir pekan</td></tr>
                <tr><td>Paid conversion</td><td><b>40% MAU → paid</b></td><td>Estimasi konservatif</td></tr>
            </tbody>
        </table>
    </div>

    <!-- 3: DRM LANDSCAPE -->
    <div class="slide" data-section="drm">
        <div class="slide-num">03 — Digital Rights Management</div>
        <h2>5 Tier DRM — dari $0 sampai $15K/bulan</h2>
        <p class="slide-sub">Pertanyaan kunci: <b style="color:#C5A55A">Bisa pure Laravel?</b> Tergantung level proteksi. Studio-grade DRM (Widevine/PlayReady/FairPlay) WAJIB 3rd party — sertifikasi Google & Apple tidak bisa di-bypass.</p>

        <div class="pd-tier t1">
            <div class="stars">⭐</div>
            <div class="info">
                <b>Tier 0 — Signed URLs</b>
                <span>Pure Laravel. Temporary signed URLs ke S3. <span class="pd-pill blue">No 3rd party</span></span>
            </div>
            <div class="price">~$0/bln</div>
        </div>
        <div class="pd-tier t1">
            <div class="stars">⭐⭐</div>
            <div class="info">
                <b>Tier 1 — HLS AES-128</b>
                <span>Pure Laravel + FFmpeg. Encrypt segments, key endpoint validate auth. <span class="pd-pill blue">No 3rd party</span></span>
            </div>
            <div class="price">~$0/bln</div>
        </div>
        <div class="pd-tier t2">
            <div class="stars">⭐⭐⭐</div>
            <div class="info">
                <b>Tier 2 — Open-source ClearKey + Widevine L3</b>
                <span>Shaka Packager + custom key server. Legal grey area — <b style="color:#eab308">tidak direkomendasi production</b>.</span>
            </div>
            <div class="price">~$50–$200/bln</div>
        </div>
        <div class="pd-tier t3">
            <div class="stars">⭐⭐⭐⭐</div>
            <div class="info">
                <b>Tier 3 — Studio L3 (Multi-DRM Software)</b>
                <span>Widevine L3 + PlayReady SL150 + FairPlay via EZDRM/BuyDRM. <b style="color:#C5A55A">Recommended untuk FLiK Year 1.</b> <span class="pd-pill gold">3rd party REQUIRED</span></span>
            </div>
            <div class="price">$300–$5.000/bln</div>
        </div>
        <div class="pd-tier t4">
            <div class="stars">⭐⭐⭐⭐⭐</div>
            <div class="info">
                <b>Tier 4 — Studio L1 (Hardware-backed)</b>
                <span>Widevine L1 + PlayReady SL3000 hardware-backed. Wajib untuk 4K HDR & deal studio besar. <span class="pd-pill red">Enterprise contract</span></span>
            </div>
            <div class="price">$500–$15.000/bln</div>
        </div>

        <div class="pd-callout" style="margin-top:20px">
            <b>Rekomendasi FLiK</b>
            <p>Mulai dengan <b>Tier 1 (AES-128)</b> untuk konten gratis & trailer → <b>Tier 3 via EZDRM</b> untuk konten premium berbayar. Skip Tier 4 sampai ada deal dengan studio besar yang demand 4K HDR.</p>
        </div>
    </div>

    <!-- 4: DRM VENDOR COMPARISON -->
    <div class="slide" data-section="drm">
        <div class="slide-num">04 — DRM Vendor Comparison</div>
        <h2>Memilih 3rd Party DRM Provider</h2>
        <p class="slide-sub">6 vendor utama. Untuk FLiK rekomendasi <b>EZDRM</b> di tahun 1-2 (paling murah, paling cepat onboard), pertimbangkan upgrade ke <b>BuyDRM</b> atau <b>DRMtoday</b> kalau deal sama studio besar.</p>

        <table class="pd-table">
            <thead>
                <tr><th>Vendor</th><th>Pricing Model</th><th>@100K Active</th><th>Notable Customer</th></tr>
            </thead>
            <tbody>
                <tr class="row-highlight">
                    <td><b>EZDRM</b></td>
                    <td>$0.0024/license (volume)</td>
                    <td class="cost">~$1.500/bln</td>
                    <td>Paling cepat onboarding, US-based, 24/7 support</td>
                </tr>
                <tr>
                    <td><b>AWS MediaPackage</b></td>
                    <td>$0.01/min packaged + $0.005/license</td>
                    <td class="cost">~$3.000/bln</td>
                    <td>Native AWS, integrated MediaConvert</td>
                </tr>
                <tr>
                    <td><b>BuyDRM KeyOS</b></td>
                    <td>$500/mo flat → $0.005/license</td>
                    <td class="cost">~$2.000–4.000/bln</td>
                    <td>Disney+ HotStar, enterprise</td>
                </tr>
                <tr>
                    <td><b>Verimatrix MultiDRM</b></td>
                    <td>Enterprise contract</td>
                    <td class="cost">$5K+/bln</td>
                    <td>Heavy enterprise, anti-piracy strong</td>
                </tr>
                <tr>
                    <td><b>Axinom</b></td>
                    <td>~$0.10/active device/mo</td>
                    <td class="cost">~$5.000/bln</td>
                    <td>EU, GDPR-friendly</td>
                </tr>
                <tr>
                    <td><b>DRMtoday (Castlabs)</b></td>
                    <td>Tiered enterprise</td>
                    <td class="cost">$2K–8K/bln</td>
                    <td>ProSiebenSat.1, EU broadcasters</td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- 5: DRM ARCHITECTURE -->
    <div class="slide" data-section="drm">
        <div class="slide-num">05 — Studio DRM Architecture</div>
        <h2>End-to-End Encrypted Streaming Flow</h2>
        <p class="slide-sub">Bagaimana studio-grade DRM bekerja di FLiK. Laravel jadi orchestrator, AWS handle transcoding & CDN, EZDRM handle license issuance.</p>

<div class="ascii-arch">┌─────────────────────────────────────────────────────────────┐
│ FLiK Laravel App                                             │
│  ├── Admin upload film (master MP4)                         │
│  └── Trigger AWS MediaConvert job via SDK                   │
└───────────────────────┬─────────────────────────────────────┘
                        ▼
┌─────────────────────────────────────────────────────────────┐
│ AWS MediaConvert    →   Transcode ABR ladder (360p–4K)      │
│                          Output ke S3 "flik-encoded"        │
└───────────────────────┬─────────────────────────────────────┘
                        ▼
┌─────────────────────────────────────────────────────────────┐
│ Shaka Packager      →   Encrypt segments (CPIX)             │
│                          HLS (FairPlay) + DASH (WV+PR)      │
│                          Upload ke S3 "flik-protected"      │
└───────────────────────┬─────────────────────────────────────┘
                        ▼
┌─────────────────────────────────────────────────────────────┐
│ CloudFront CDN      →   Signed URLs / Cookies               │
│                          Geo-restriction Indonesia          │
└───────────────────────┬─────────────────────────────────────┘
                        ▼
┌─────────────────────────────────────────────────────────────┐
│ User Player (Shaka.js / Video.js / ExoPlayer / Safari)      │
│  ├── Detect DRM capability                                  │
│  └── Request license:                                       │
│       Widevine  → license.ezdrm.com  ────┐                  │
│       PlayReady → license.ezdrm.com  ────┤                  │
│       FairPlay  → license.ezdrm.com  ────┘                  │
└─────────────────────────────────────────────────────────────┘
                                          ▼
┌─────────────────────────────────────────────────────────────┐
│ EZDRM License Server                                         │
│  ├── Validate JWT token (signed by Laravel)                 │
│  └── Issue license + device-bound restrictions              │
└─────────────────────────────────────────────────────────────┘</div>

        <div class="pd-callout">
            <b>Yang perlu dibangun di Laravel</b>
            <p>1. <code>App\Services\Drm\LicenseTokenService</code> — generate JWT untuk DRM provider · 2. <code>App\Services\Transcoding\MediaConvertService</code> — dispatch jobs · 3. <code>PlaybackController</code> — return manifest URL + license server + token · 4. Tambahan kolom di <code>movies</code>: <code>dash_manifest_url</code>, <code>hls_manifest_url</code>, <code>drm_status</code></p>
        </div>
    </div>

    <!-- 6: AI OVERVIEW -->
    <div class="slide" data-section="ai">
        <div class="slide-num">06 — AI Layer Overview</div>
        <h2>15 Fitur AI · Multi-Provider · Pay-as-you-go</h2>
        <p class="slide-sub">Sudah ada tabel <code>ai_providers</code> dengan encrypted API key. Admin bisa add OpenAI, Anthropic, DeepSeek, Gemini, Groq, Mistral, OpenRouter, atau custom OpenAI-compatible endpoint via <a href="{{ route('admin.ai.index') }}" style="color:#C5A55A">/admin/ai-settings</a>.</p>

        <div class="pd-grid-2">
            <div class="pd-card">
                <h3>🎯 User-Facing (8 fitur)</h3>
                <ul class="pd-list" style="margin-top:8px">
                    <li>Personalized Recommendations</li>
                    <li>Semantic Search ("film tentang...")</li>
                    <li>Auto-generated Synopsis</li>
                    <li>AI Chatbot Customer Support</li>
                    <li>Smart Continue Watching</li>
                    <li>Mood-based Discovery</li>
                    <li>AI Subtitle Generation (Whisper)</li>
                    <li>Auto-translation (Indo → Mandarin/Arab)</li>
                </ul>
            </div>
            <div class="pd-card">
                <h3>🛠️ Backend / Admin (7 fitur)</h3>
                <ul class="pd-list" style="margin-top:8px">
                    <li>Auto-tagging (genre, mood, era)</li>
                    <li>Comment/Review Moderation</li>
                    <li>Trailer Auto-generation Suggest</li>
                    <li>Thumbnail Selection (vision)</li>
                    <li>Content Quality Classification</li>
                    <li>Restoration Priority Ranking</li>
                    <li>Email/Notification Personalization</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- 7: AI PROVIDER PRICING -->
    <div class="slide" data-section="ai">
        <div class="slide-num">07 — AI Provider Pricing (per 1M tokens)</div>
        <h2>Memilih Provider per Task</h2>
        <p class="slide-sub">Strategy multi-provider: 1 default murah (DeepSeek), spesialis per task (Whisper untuk audio, Claude untuk reasoning, Gemini untuk vision). Auto-fallback kalau primary down.</p>

        <table class="pd-table">
            <thead><tr><th>Provider</th><th>Model</th><th>Input</th><th>Output</th><th>Best For</th></tr></thead>
            <tbody>
                <tr class="row-highlight"><td><b>DeepSeek</b></td><td>deepseek-chat (V3)</td><td class="cost">$0.27</td><td class="cost">$1.10</td><td>Default — generation, summarization</td></tr>
                <tr><td>DeepSeek</td><td>deepseek-reasoner</td><td>$0.55</td><td>$2.19</td><td>Complex reasoning, recommendations</td></tr>
                <tr><td>OpenAI</td><td>gpt-4o-mini</td><td class="cost">$0.15</td><td class="cost">$0.60</td><td>Fast UI features, quick tagging</td></tr>
                <tr><td>OpenAI</td><td>gpt-4o</td><td>$2.50</td><td>$10.00</td><td>Premium tasks, content moderation</td></tr>
                <tr><td>OpenAI</td><td>Whisper (audio)</td><td colspan="2">$0.006/menit</td><td>Auto-subtitle generation</td></tr>
                <tr><td>Anthropic</td><td>Claude Sonnet 4.6</td><td>$3.00</td><td>$15.00</td><td>Long-form synopsis, editorial</td></tr>
                <tr><td>Anthropic</td><td>Claude Haiku 4.5</td><td>$1.00</td><td>$5.00</td><td>Comments moderation, classification</td></tr>
                <tr class="row-highlight"><td><b>Google</b></td><td>Gemini 2.0 Flash</td><td class="cost">$0.075</td><td class="cost">$0.30</td><td>Cheapest, multimodal vision</td></tr>
                <tr><td>Groq</td><td>Llama 3.3 70B</td><td>$0.59</td><td>$0.79</td><td>Ultra-low latency real-time chat</td></tr>
                <tr><td>OpenRouter</td><td>Multi-vendor</td><td colspan="2">varies</td><td>Single API key, multi-provider routing</td></tr>
            </tbody>
        </table>
    </div>

    <!-- 8: AI COST -->
    <div class="slide" data-section="ai">
        <div class="slide-num">08 — AI Cost Projection</div>
        <h2>One-time Setup + Monthly OPEX</h2>
        <p class="slide-sub">Cost AI scale linearly tapi sangat manageable. Recommendations bisa di-precompute harian (batched) untuk save 70% cost.</p>

        <div class="pd-grid-2" style="margin-bottom:24px">
            <div class="pd-card gold">
                <h3>One-time (350 film katalog)</h3>
                <table class="pd-table" style="margin-top:8px">
                    <tbody>
                        <tr><td>Subtitle (Whisper)</td><td class="cost" style="text-align:right">$189</td></tr>
                        <tr><td>Auto-tagging</td><td class="cost" style="text-align:right">$70</td></tr>
                        <tr><td>Translation (3 bahasa)</td><td class="cost" style="text-align:right">$2.100</td></tr>
                        <tr><td>Trailer suggest</td><td class="cost" style="text-align:right">$700</td></tr>
                        <tr><td>Thumbnail pick</td><td class="cost" style="text-align:right">$105</td></tr>
                        <tr><td>QC + Synopsis</td><td class="cost" style="text-align:right">$385</td></tr>
                        <tr style="border-top:2px solid #C5A55A"><td><b>TOTAL One-time</b></td><td style="text-align:right" class="cost"><b>$3.550</b><br><small>Rp 57 juta</small></td></tr>
                    </tbody>
                </table>
            </div>

            <div class="pd-card gold">
                <h3>Monthly OPEX per Skala</h3>
                <table class="pd-table" style="margin-top:8px">
                    <thead><tr><th>MAU</th><th>USD/bln</th><th>Rupiah</th></tr></thead>
                    <tbody>
                        <tr><td>1.000</td><td class="cost">$25</td><td>Rp 400.000</td></tr>
                        <tr><td>10.000</td><td class="cost">$250</td><td>Rp 4 juta</td></tr>
                        <tr><td>100.000</td><td class="cost">$2.500</td><td>Rp 40 juta</td></tr>
                        <tr><td>1.000.000</td><td class="cost">$25.000</td><td>Rp 400 juta</td></tr>
                    </tbody>
                </table>
                <p style="margin-top:12px;color:#999;font-size:12px">Dari $25/bln (1K user) sampai $25K/bln (1M user) — hanya <b>0.7%</b> dari total infra cost di skala 1M.</p>
            </div>
        </div>

        <div class="pd-callout">
            <b>Tools tambahan yang perlu (selain AI provider API)</b>
            <p>1. <b>Vector DB</b>: pgvector di RDS PostgreSQL (gratis) untuk semantic search · 2. <b>Queue</b>: Redis (existing) untuk batch AI processing · 3. <b>Cache</b>: Redis untuk hemat AI calls · 4. <b>Rate limiting</b>: Laravel built-in <code>throttle</code> · 5. <b>Spend tracking</b>: kolom <code>total_tokens_used</code> & <code>total_cost_usd</code> sudah ada di <code>ai_providers</code></p>
        </div>
    </div>

    <!-- 9: ARCHITECTURE -->
    <div class="slide" data-section="arch">
        <div class="slide-num">09 — Platform Architecture</div>
        <h2>End-to-End AWS Stack</h2>
        <p class="slide-sub">Laravel 12 sebagai control plane, AWS managed services untuk heavy-lifting (encoding, storage, CDN, search). Multi-region ready.</p>

<div class="ascii-arch">                          ┌──────────────────────────┐
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
        ┌─────┴─────────┐         ┌────────────────┐
        │ MediaConvert  │ ──────▶ │ Shaka Packager │
        │ (transcode)   │         │ + DRM encrypt  │
        └───────────────┘         └────────────────┘</div>

        <div class="pd-grid-3" style="margin-top:24px">
            <div class="pd-card"><h3>✓ Sudah ada</h3><ul class="pd-list"><li>Web app + admin panel</li><li>Auth + Google OAuth</li><li>Payment Midtrans</li><li>Gamification</li><li>PWA + NativePHP Android</li></ul></div>
            <div class="pd-card gold"><h3>📋 Perlu dibangun</h3><ul class="pd-list"><li>AiClient (multi-provider)</li><li>DRM service & token</li><li>MediaConvert pipeline</li><li>Playback controller + Shaka.js</li><li>pgvector embeddings</li></ul></div>
            <div class="pd-card"><h3>⏱ Timeline</h3><p style="font-size:13px;line-height:1.7">~6 minggu (1 senior dev) untuk Foundation. Lihat Roadmap untuk full timeline 12 bulan.</p></div>
        </div>
    </div>

    <!-- 10: COST OVERVIEW -->
    <div class="slide" data-section="cost">
        <div class="slide-num">10 — AWS Cost Matrix</div>
        <h2>4 Skenario Skala (1K → 1M MAU)</h2>
        <p class="slide-sub">Asumsi 40% MAU = paid subscriber. Egress per user 22GB/bln. Region ap-southeast-1.</p>

        <div class="pd-grid-4">
            <div class="pd-card gold">
                <h3>1.000 MAU</h3>
                <div class="num">$2.730</div>
                <p>per bulan · Rp 43,7 juta<br><b style="color:#ef4444">$6,80/paid user</b></p>
            </div>
            <div class="pd-card gold">
                <h3>10.000 MAU</h3>
                <div class="num">$19.400</div>
                <p>per bulan · Rp 310 juta<br><b style="color:#eab308">$4,85/paid user</b></p>
            </div>
            <div class="pd-card gold">
                <h3>100.000 MAU</h3>
                <div class="num">$127.600</div>
                <p>per bulan · Rp 2,04 miliar<br><b style="color:#22c55e">$3,19/paid user</b></p>
            </div>
            <div class="pd-card gold">
                <h3>1.000.000 MAU</h3>
                <div class="num">$700K</div>
                <p>worst-case · Rp 11,2 miliar<br><b style="color:#22c55e">$1,75/paid user</b><br><small>Optimized: $400K = $1,00/user</small></p>
            </div>
        </div>

        <div class="pd-callout" style="margin-top:24px">
            <b>Economies of Scale</b>
            <p>Cost per paid user turun dari <b>$6.80</b> (1K user) ke <b>$1.00</b> (1M optimized) — efisiensi <b>6.8×</b>. Dominan di CDN egress (~70% total cost), karena itu strategi multi-CDN + Indonesia local peering critical di skala 100K+.</p>
        </div>
    </div>

    <!-- 11: COST DETAIL 1K -->
    <div class="slide" data-section="cost">
        <div class="slide-num">11 — Cost Detail · 1.000 MAU</div>
        <h2>Bulan ke-3 Tahun-1</h2>
        <p class="slide-sub">400 paid user · 22 TB egress/bulan</p>

        <table class="pd-table">
            <thead><tr><th>Komponen</th><th>Spec/Volume</th><th style="text-align:right">Monthly</th></tr></thead>
            <tbody>
                <tr class="row-highlight"><td>CloudFront egress</td><td>22 TB @ $0.080/GB</td><td class="cost" style="text-align:right">$1.760</td></tr>
                <tr><td>S3 storage</td><td>1.2 TB Standard</td><td style="text-align:right">$30</td></tr>
                <tr><td>S3 GET requests</td><td>50M</td><td style="text-align:right">$20</td></tr>
                <tr><td>MediaConvert</td><td>750 menit/bln</td><td style="text-align:right">$6</td></tr>
                <tr><td>ECS Fargate</td><td>2 task × 2 vCPU</td><td style="text-align:right">$58</td></tr>
                <tr><td>RDS MySQL t3.medium Multi-AZ</td><td>50 GB SSD</td><td style="text-align:right">$130</td></tr>
                <tr><td>RDS PostgreSQL t3.small (pgvector)</td><td>20 GB</td><td style="text-align:right">$40</td></tr>
                <tr><td>ElastiCache Redis t3.small</td><td>—</td><td style="text-align:right">$25</td></tr>
                <tr><td>ALB + internal transfer</td><td>—</td><td style="text-align:right">$20</td></tr>
                <tr><td>CloudWatch</td><td>—</td><td style="text-align:right">$15</td></tr>
                <tr><td>SES</td><td>100K emails</td><td style="text-align:right">$10</td></tr>
                <tr><td>DRM (EZDRM)</td><td>~80K licenses</td><td style="text-align:right">$192</td></tr>
                <tr><td>AI APIs</td><td>DeepSeek + others</td><td style="text-align:right">$25</td></tr>
                <tr><td>Backup, secrets, misc</td><td>—</td><td style="text-align:right">$40</td></tr>
                <tr style="border-top:1px solid #2a2a2a"><td><b>Subtotal</b></td><td></td><td style="text-align:right" class="cost"><b>$2.371</b></td></tr>
                <tr><td>Buffer 15%</td><td>egress spikes</td><td style="text-align:right">$356</td></tr>
                <tr style="border-top:2px solid #C5A55A;background:rgba(197,165,90,0.08)"><td><b>TOTAL</b></td><td><b style="color:#ef4444">$6.80/paid user</b></td><td style="text-align:right" class="cost"><b style="font-size:18px">$2.730</b></td></tr>
            </tbody>
        </table>
    </div>

    <!-- 12: COST DETAIL 100K -->
    <div class="slide" data-section="cost">
        <div class="slide-num">12 — Cost Detail · 100.000 MAU</div>
        <h2>Skala Production (Year 3)</h2>
        <p class="slide-sub">40.000 paid user · 2.2 PB egress/bulan · CloudFront committed pricing</p>

        <table class="pd-table">
            <thead><tr><th>Komponen</th><th>Spec/Volume</th><th style="text-align:right">Monthly</th></tr></thead>
            <tbody>
                <tr class="row-highlight"><td>CloudFront egress</td><td>2.2 PB @ $0.040/GB avg</td><td class="cost" style="text-align:right">$88.000</td></tr>
                <tr><td>S3 (hot + IA)</td><td>2 TB hot + 3 TB IA</td><td style="text-align:right">$200</td></tr>
                <tr><td>S3 requests</td><td>5B</td><td style="text-align:right">$1.500</td></tr>
                <tr><td>MediaConvert</td><td>10K menit</td><td style="text-align:right">$75</td></tr>
                <tr><td>ECS Fargate</td><td>12 task × 4 vCPU + 8 GB</td><td style="text-align:right">$1.500</td></tr>
                <tr><td>RDS MySQL r5.2xlarge Multi-AZ</td><td>1 TB SSD</td><td style="text-align:right">$2.200</td></tr>
                <tr><td>RDS PostgreSQL r5.large + replica</td><td>200 GB</td><td style="text-align:right">$700</td></tr>
                <tr><td>ElastiCache Redis cluster (3 node)</td><td>—</td><td style="text-align:right">$900</td></tr>
                <tr><td>OpenSearch r5.large × 3</td><td>—</td><td style="text-align:right">$1.800</td></tr>
                <tr><td>ALB + WAF + Shield Standard</td><td>—</td><td style="text-align:right">$400</td></tr>
                <tr><td>Monitoring (CW + Sentry + Datadog)</td><td>—</td><td style="text-align:right">$1.200</td></tr>
                <tr><td>SES</td><td>10M emails</td><td style="text-align:right">$1.000</td></tr>
                <tr><td>DRM (EZDRM enterprise)</td><td>~8M licenses negotiated</td><td style="text-align:right">$8.000</td></tr>
                <tr><td>AI APIs</td><td>—</td><td style="text-align:right">$2.500</td></tr>
                <tr><td>Misc</td><td>KMS, NAT, secrets, backup</td><td style="text-align:right">$1.000</td></tr>
                <tr style="border-top:1px solid #2a2a2a"><td><b>Subtotal</b></td><td></td><td style="text-align:right" class="cost"><b>$110.975</b></td></tr>
                <tr><td>Buffer 15%</td><td></td><td style="text-align:right">$16.646</td></tr>
                <tr style="border-top:2px solid #C5A55A;background:rgba(197,165,90,0.08)"><td><b>TOTAL</b></td><td><b style="color:#22c55e">$3.19/paid user</b></td><td style="text-align:right" class="cost"><b style="font-size:18px">$127.600</b></td></tr>
                <tr><td colspan="2" style="color:#22c55e;font-style:italic">Optimized (Reserved Instances + CloudFront commit)</td><td style="text-align:right" class="cost"><b>~$95.000</b></td></tr>
            </tbody>
        </table>
    </div>

    <!-- 13: REVENUE SANITY -->
    <div class="slide" data-section="cost">
        <div class="slide-num">13 — Revenue Sanity Check</div>
        <h2>Pricing Strategy</h2>
        <p class="slide-sub">Pricing Rp 49K terlalu murah untuk skala awal. Tier-based dengan free ad-supported tier untuk monetize non-paying user.</p>

        <table class="pd-table">
            <thead><tr><th>Skenario</th><th>Paid Users</th><th>Revenue/bln</th><th>Cost/bln</th><th>Margin</th></tr></thead>
            <tbody>
                <tr><td>1K MAU @ Rp 49K</td><td>400</td><td>$1.200</td><td>$2.730</td><td class="neg"><b>-127% ❌ Burn</b></td></tr>
                <tr><td>10K MAU @ Rp 49K</td><td>4K</td><td>$12.000</td><td>$19.400</td><td class="neg"><b>-62% ❌ Burn</b></td></tr>
                <tr><td>100K MAU @ Rp 49K</td><td>40K</td><td>$120.000</td><td>$127.600</td><td class="warn"><b>-6% ⚠️ Break-even</b></td></tr>
                <tr class="row-highlight"><td>100K MAU @ Rp 79K + optimize</td><td>40K</td><td>$200.000</td><td>$95.000</td><td class="cost"><b>+52% ✅</b></td></tr>
                <tr class="row-highlight"><td>1M MAU @ Rp 49K + optimize</td><td>400K</td><td>$1.200.000</td><td>$400K–$700K</td><td class="cost"><b>+40–67% ✅</b></td></tr>
            </tbody>
        </table>

        <div class="pd-grid-3" style="margin-top:24px">
            <div class="pd-card"><h3>💰 Basic — Rp 39K</h3><p>480p · 2 device · with ads</p></div>
            <div class="pd-card gold"><h3>⭐ Premium — Rp 79K</h3><p>1080p · 4 device · no ads · download</p></div>
            <div class="pd-card"><h3>👨‍👩‍👧 Family — Rp 129K</h3><p>4K future · 6 device · 4 profile</p></div>
        </div>

        <div class="pd-callout" style="margin-top:20px">
            <b>Revenue Diversification</b>
            <p>1. <b>Free ad-supported tier</b> — Google Ad Manager / Magnite untuk monetize non-paying · 2. <b>B2B licensing</b> untuk sekolah, kampus, hotel, lembaga budaya — Rp 5–20 juta/tahun per institusi · 3. <b>Heritage sponsorship</b> — KemenParekraf, brand seperti Bentoel, Garuda, BCA</p>
        </div>
    </div>

    <!-- 14: ROADMAP -->
    <div class="slide" data-section="roadmap">
        <div class="slide-num">14 — Roadmap Implementasi</div>
        <h2>12-Month Plan</h2>

        <div class="pd-grid-2">
            <div class="pd-card">
                <h3>✅ Fase 0 — MVP (Done)</h3>
                <ul class="pd-list"><li>Web app + admin panel</li><li>Payment Midtrans</li><li>Gamification</li><li>PWA</li></ul>
            </div>
            <div class="pd-card gold">
                <h3>🚀 Fase 1 — Production Ready (Bulan 1-2)</h3>
                <ul class="pd-list"><li>AWS environment setup</li><li>CI/CD pipeline</li><li>MediaConvert pipeline</li><li>Tier 1 DRM (HLS AES-128)</li><li>Migrate ke S3</li></ul>
            </div>
            <div class="pd-card gold">
                <h3>🛡️ Fase 2 — DRM & AI (Bulan 3-4)</h3>
                <ul class="pd-list"><li>EZDRM onboarding</li><li>AiClient multi-provider</li><li>Whisper subtitle pipeline</li><li>Auto-tagging Claude+Gemini</li><li>pgvector semantic search</li></ul>
            </div>
            <div class="pd-card">
                <h3>📈 Fase 3 — Growth (Bulan 5-6)</h3>
                <ul class="pd-list"><li>Personalized recommendation</li><li>AI chatbot CS</li><li>Auto-translation 3 bahasa</li><li>Comment moderation</li></ul>
            </div>
            <div class="pd-card">
                <h3>📱 Fase 4 — Mobile (Bulan 6-8)</h3>
                <ul class="pd-list"><li>Polish NativePHP Android</li><li>iOS app (Flutter/native)</li><li>TV apps (Android TV, Tizen)</li></ul>
            </div>
            <div class="pd-card">
                <h3>⚡ Fase 5 — Scale (Bulan 8-12)</h3>
                <ul class="pd-list"><li>Multi-CDN setup</li><li>Indonesia peering</li><li>Reserved Instances</li><li>Forensic watermarking</li><li>B2B portal</li></ul>
            </div>
        </div>
    </div>

    <!-- 15: TCO -->
    <div class="slide" data-section="cost">
        <div class="slide-num">15 — Total Cost of Ownership</div>
        <h2>Year-1 & Year-3 Budget</h2>

        <div class="pd-grid-2">
            <div class="pd-card">
                <h3>Year-1 Budget (1K → 10K MAU)</h3>
                <table class="pd-table" style="margin-top:8px">
                    <tbody>
                        <tr><td>AWS setup + arsitektur</td><td class="cost" style="text-align:right">$5.000</td></tr>
                        <tr><td>One-time AI processing 350 film</td><td class="cost" style="text-align:right">$3.550</td></tr>
                        <tr><td>Konten ingestion & restoration</td><td class="cost" style="text-align:right">$20.000</td></tr>
                        <tr><td>DRM onboarding</td><td class="cost" style="text-align:right">$2.000</td></tr>
                        <tr><td>Dev team (6 minggu + hardening)</td><td class="cost" style="text-align:right">$30.000</td></tr>
                        <tr><td>AWS infra (avg 1K → 10K, 12 bln)</td><td class="cost" style="text-align:right">$96.000</td></tr>
                        <tr><td>DRM ongoing</td><td class="cost" style="text-align:right">$4.800</td></tr>
                        <tr><td>AI ongoing</td><td class="cost" style="text-align:right">$960</td></tr>
                        <tr><td>SaaS (domain, monitoring)</td><td class="cost" style="text-align:right">$2.400</td></tr>
                        <tr style="border-top:2px solid #C5A55A;background:rgba(197,165,90,0.08)"><td><b>TOTAL Year-1</b></td><td style="text-align:right" class="cost"><b style="font-size:16px">~$165.000<br><small>Rp 2,64 miliar</small></b></td></tr>
                    </tbody>
                </table>
            </div>

            <div class="pd-card gold">
                <h3>Year-3 Steady State (100K MAU)</h3>
                <table class="pd-table" style="margin-top:8px">
                    <tbody>
                        <tr><td>AWS infra (optimized)</td><td class="cost" style="text-align:right">$95.000/bln</td></tr>
                        <tr><td>DRM</td><td class="cost" style="text-align:right">$8.000/bln</td></tr>
                        <tr><td>AI</td><td class="cost" style="text-align:right">$2.500/bln</td></tr>
                        <tr><td>3rd party SaaS</td><td class="cost" style="text-align:right">$3.000/bln</td></tr>
                        <tr><td>Engineering (5 senior dev)</td><td class="cost" style="text-align:right">$30.000/bln</td></tr>
                        <tr style="border-top:2px solid #C5A55A;background:rgba(197,165,90,0.08)"><td><b>Monthly</b></td><td style="text-align:right" class="cost"><b>$138.500</b></td></tr>
                        <tr style="background:rgba(197,165,90,0.08)"><td><b>Annual</b></td><td style="text-align:right" class="cost"><b style="font-size:16px">~$1.66M<br><small>Rp 26,6 miliar</small></b></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- 16: RISK -->
    <div class="slide" data-section="roadmap">
        <div class="slide-num">16 — Risk Register</div>
        <h2>Top Risks & Mitigasi</h2>

        <table class="pd-table">
            <thead><tr><th>Risk</th><th>Likelihood</th><th>Impact</th><th>Mitigasi</th></tr></thead>
            <tbody>
                <tr><td>CDN egress meledak (viral)</td><td><span class="pd-pill red">High</span></td><td><span class="pd-pill red">High</span></td><td>Multi-CDN, ID peering, spend alert, throttle bitrate</td></tr>
                <tr><td>DRM key compromise</td><td><span class="pd-pill green">Low</span></td><td><span class="pd-pill red">High</span></td><td>Forensic watermarking, monthly key rotation, EZDRM audit</td></tr>
                <tr><td>AI provider down</td><td><span class="pd-pill blue">Medium</span></td><td><span class="pd-pill blue">Medium</span></td><td>Multi-provider fallback (sudah didesain)</td></tr>
                <tr><td>Rights holder tarik konten</td><td><span class="pd-pill blue">Medium</span></td><td><span class="pd-pill red">High</span></td><td>Kontrak multi-year, library diversification</td></tr>
                <tr><td>Pembajakan / screen capture</td><td><span class="pd-pill red">High</span></td><td><span class="pd-pill blue">Medium</span></td><td>DRM L1 untuk premium, watermarking, takedown ops</td></tr>
                <tr><td>Lebaran traffic 5× spike</td><td><span class="pd-pill red">High</span></td><td><span class="pd-pill blue">Medium</span></td><td>Auto-scale, buffer 15%, capacity test 2×/year</td></tr>
                <tr><td>UU PDP compliance</td><td><span class="pd-pill red">High</span></td><td><span class="pd-pill red">High</span></td><td>Data residency Indonesia, audit trail, consent UI</td></tr>
                <tr><td>Midtrans down</td><td><span class="pd-pill green">Low</span></td><td><span class="pd-pill blue">Medium</span></td><td>Backup gateway: Xendit / DOKU</td></tr>
                <tr><td>AI hallucination di synopsis</td><td><span class="pd-pill blue">Medium</span></td><td><span class="pd-pill blue">Medium</span></td><td>Editorial review workflow, human-in-loop</td></tr>
            </tbody>
        </table>
    </div>

    <!-- 17: ASK -->
    <div class="slide slide-cover" data-section="overview">
        <div class="slide-num" style="margin-bottom:16px">17 — The Ask</div>
        <h1 style="font-size:48px">Mari Bangun Bersama</h1>
        <div class="tagline">FLiK siap jadi rumah definitif untuk film klasik Indonesia. Dengan arsitektur yang sudah didesain, tim yang sudah eksekusi MVP, dan roadmap yang jelas — yang dibutuhkan adalah modal & partnership untuk Year-1.</div>

        <div class="pd-grid-3" style="width:100%;max-width:800px;margin-top:32px">
            <div class="pd-card gold"><h3>💵 Funding</h3><div class="num">$200K</div><p>Year-1 runway dengan buffer</p></div>
            <div class="pd-card gold"><h3>📚 Konten</h3><div class="num">350</div><p>Film katalog · partnership Sinematek/ANRI</p></div>
            <div class="pd-card gold"><h3>👥 Team</h3><div class="num">5</div><p>Senior engineer untuk Year-1</p></div>
        </div>

        <div style="margin-top:40px;color:#888;font-size:13px">
            Pitch deck lengkap dalam markdown: <code style="color:#C5A55A">PITCH_DECK.md</code><br>
            AI Provider settings: <a href="{{ route('admin.ai.index') }}" style="color:#C5A55A">/admin/ai-settings</a>
        </div>
    </div>

    <script>
        function showSection(section, btn) {
            document.querySelectorAll('.pd-tab').forEach(t => t.classList.remove('active'));
            btn.classList.add('active');
            document.querySelectorAll('.slide').forEach(s => {
                if (section === 'all') {
                    s.style.display = 'block';
                } else {
                    s.style.display = (s.dataset.section === section) ? 'block' : 'none';
                }
            });
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    </script>

</x-admin.layout>
