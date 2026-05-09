<x-admin.layout title="Pitch Deck">

    <style>
        /* Override admin content padding for full-screen slides */
        .admin-content { padding: 0 !important; }

        /* Slide deck container */
        .pd-deck { background: #0a0a0a; min-height: calc(100vh - 64px); position: relative; }

        /* Top toolbar */
        .pd-toolbar {
            position: sticky; top: 64px; z-index: 30;
            background: rgba(15,15,15,0.95); backdrop-filter: blur(8px);
            border-bottom: 1px solid #2a2a2a;
            padding: 10px 20px;
            display: flex; justify-content: space-between; align-items: center;
            gap: 12px; flex-wrap: wrap;
        }
        .pd-toolbar .pd-mode { display: flex; gap: 4px; background: #1a1a1a; border-radius: 8px; padding: 3px; border: 1px solid #2a2a2a; }
        .pd-toolbar .pd-mode button { padding: 6px 14px; border: none; background: transparent; color: #888; font-size: 12px; cursor: pointer; border-radius: 6px; transition: all 0.2s; font-weight: 500; }
        .pd-toolbar .pd-mode button.active { background: #C5A55A; color: #000; }
        .pd-toolbar .pd-counter { font-family: 'Outfit'; font-size: 14px; color: #C5A55A; font-weight: 600; min-width: 70px; text-align: center; }
        .pd-toolbar .pd-nav { display: flex; gap: 6px; }
        .pd-toolbar .pd-nav button { width: 36px; height: 32px; background: #1a1a1a; border: 1px solid #2a2a2a; color: #aaa; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s; }
        .pd-toolbar .pd-nav button:hover:not(:disabled) { border-color: #C5A55A; color: #C5A55A; }
        .pd-toolbar .pd-nav button:disabled { opacity: 0.3; cursor: not-allowed; }

        /* Single-slide presentation mode */
        .pd-deck.mode-slide .slide { display: none; }
        .pd-deck.mode-slide .slide.active { display: flex; }
        .pd-deck.mode-slide .slide {
            min-height: calc(100vh - 64px - 53px);
            padding: 32px 48px;
            flex-direction: column;
            overflow-y: auto;
            box-sizing: border-box;
        }

        /* Scroll mode */
        .pd-deck.mode-scroll { padding: 24px 32px; }
        .pd-deck.mode-scroll .slide {
            min-height: auto;
            padding: 32px 40px;
            margin-bottom: 24px;
            border-radius: 16px;
            border: 1px solid #2a2a2a;
        }

        /* Base slide */
        .slide {
            background: linear-gradient(180deg, #1a1a1a 0%, #141414 100%);
        }
        .slide.cover {
            background: linear-gradient(135deg, #1a1a1a 0%, #0a0a0a 70%, #1a1410 100%);
            text-align: center;
            justify-content: center !important;
            align-items: center;
        }

        /* Slide content layout - prevent overflow cutoff */
        .slide-inner {
            width: 100%;
            max-width: 1280px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .slide h1 { font-family: 'Outfit'; font-size: clamp(40px, 6vw, 72px); font-weight: 800; color: #C5A55A; letter-spacing: -1px; line-height: 1.05; }
        .slide h2 { font-family: 'Outfit'; font-size: clamp(24px, 3vw, 36px); font-weight: 700; color: #fff; line-height: 1.2; }
        .slide h3 { font-family: 'Outfit'; font-size: 16px; color: #fff; font-weight: 600; }
        .slide .slide-num { font-size: 11px; text-transform: uppercase; letter-spacing: 2px; color: #C5A55A; font-weight: 600; }
        .slide .slide-sub { color: #999; font-size: clamp(13px, 1.3vw, 15px); line-height: 1.6; }

        .cover .tagline { font-size: clamp(15px, 1.5vw, 20px); color: #aaa; max-width: 720px; line-height: 1.6; margin: 16px auto 32px; }
        .cover .badges { display: flex; gap: 8px; margin: 16px auto 32px; flex-wrap: wrap; justify-content: center; max-width: 720px; }
        .cover .badges span { padding: 6px 14px; background: rgba(197,165,90,0.1); border: 1px solid rgba(197,165,90,0.35); border-radius: 20px; font-size: 12px; color: #C5A55A; }
        .cover .meta { font-size: 11px; color: #666; text-transform: uppercase; letter-spacing: 2px; margin-top: 24px; }

        /* Tables - dense, scroll if too wide */
        .pd-tbl-wrap { overflow-x: auto; }
        .pd-table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .pd-table th { padding: 8px 12px; background: #0f0f0f; color: #C5A55A; text-transform: uppercase; font-size: 10px; letter-spacing: 1px; text-align: left; border-bottom: 1px solid #2a2a2a; white-space: nowrap; }
        .pd-table td { padding: 8px 12px; border-bottom: 1px solid #1f1f1f; vertical-align: top; }
        .pd-table tr.hi td { background: rgba(197,165,90,0.06); border-left: 3px solid #C5A55A; }
        .pd-table .cost { color: #22c55e; font-weight: 600; font-family: 'Outfit'; white-space: nowrap; }
        .pd-table .neg { color: #ef4444; }
        .pd-table .warn { color: #eab308; }

        /* Cards & grids */
        .pd-grid-2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 12px; }
        .pd-grid-3 { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; }
        .pd-grid-4 { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; }

        .pd-card { background: #0f0f0f; border: 1px solid #2a2a2a; border-radius: 10px; padding: 14px 16px; }
        .pd-card.gold { border-color: rgba(197,165,90,0.5); background: linear-gradient(135deg, rgba(197,165,90,0.06), transparent); }
        .pd-card.green { border-color: rgba(34,197,94,0.4); background: linear-gradient(135deg, rgba(34,197,94,0.06), transparent); }
        .pd-card .num { font-family: 'Outfit'; font-size: clamp(22px, 2.5vw, 32px); font-weight: 700; color: #C5A55A; margin: 4px 0; line-height: 1; }
        .pd-card .num.green { color: #22c55e; }
        .pd-card .num.red { color: #ef4444; }
        .pd-card p { font-size: 12px; color: #aaa; line-height: 1.55; margin-top: 4px; }

        /* DRM tier rows */
        .pd-tier { display: flex; gap: 12px; align-items: center; padding: 12px 14px; background: #0f0f0f; border-radius: 8px; margin-bottom: 6px; border-left: 3px solid #2a2a2a; font-size: 12px; }
        .pd-tier.t1 { border-left-color: #6b7280; }
        .pd-tier.t2 { border-left-color: #3b82f6; }
        .pd-tier.t3 { border-left-color: #C5A55A; }
        .pd-tier.t4 { border-left-color: #ef4444; }
        .pd-tier .stars { font-size: 12px; color: #C5A55A; min-width: 70px; }
        .pd-tier .info { flex: 1; min-width: 0; }
        .pd-tier .info b { color: #fff; font-size: 13px; }
        .pd-tier .info span { display: block; font-size: 11px; color: #888; margin-top: 1px; }
        .pd-tier .price { font-family: 'Outfit'; font-weight: 600; color: #22c55e; min-width: 90px; text-align: right; font-size: 13px; }

        /* Callouts */
        .pd-callout { background: rgba(197,165,90,0.06); border: 1px solid rgba(197,165,90,0.25); border-left: 3px solid #C5A55A; padding: 10px 14px; border-radius: 6px; font-size: 12px; }
        .pd-callout.green { background: rgba(34,197,94,0.06); border-color: rgba(34,197,94,0.3); border-left-color: #22c55e; }
        .pd-callout b { color: #C5A55A; }
        .pd-callout.green b { color: #22c55e; }
        .pd-callout p { color: #ccc; line-height: 1.55; margin-top: 3px; }

        .pd-pill { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 10px; font-weight: 600; }
        .pd-pill.green { background: rgba(34,197,94,0.15); color: #22c55e; }
        .pd-pill.red { background: rgba(239,68,68,0.15); color: #ef4444; }
        .pd-pill.gold { background: rgba(197,165,90,0.15); color: #C5A55A; }
        .pd-pill.blue { background: rgba(59,130,246,0.15); color: #3b82f6; }

        ul.pd-list { list-style: none; padding: 0; margin: 0; }
        ul.pd-list li { padding: 5px 0; font-size: 12px; color: #ccc; line-height: 1.5; border-bottom: 1px solid rgba(255,255,255,0.04); }
        ul.pd-list li:last-child { border-bottom: none; }
        ul.pd-list li:before { content: "▸"; color: #C5A55A; margin-right: 8px; }

        code { background: #0a0a0a; padding: 1px 6px; border-radius: 3px; font-size: 11px; color: #C5A55A; font-family: 'Consolas', monospace; }

        /* Print: each slide one page */
        @media print {
            .admin-sidebar, .admin-topbar, .pd-toolbar { display: none !important; }
            .admin-main { margin-left: 0 !important; }
            .admin-content { padding: 0 !important; }
            .pd-deck.mode-slide .slide { display: flex !important; page-break-after: always; min-height: auto; }
            .slide { background: #fff !important; color: #000 !important; padding: 20px !important; }
            .slide *, .slide h1, .slide h2, .slide h3, .slide p, .slide td, .slide th, code { color: #000 !important; }
            .slide .slide-num { color: #888 !important; }
            .pd-table th { background: #eee !important; }
            .pd-card { border-color: #ccc !important; background: #f8f8f8 !important; }
        }

        @media (max-width: 768px) {
            .pd-deck.mode-slide .slide { padding: 20px 16px; }
            .pd-deck.mode-scroll .slide { padding: 20px 16px; }
        }
    </style>

    <div class="pd-deck mode-slide" id="pdDeck">

        <!-- Toolbar -->
        <div class="pd-toolbar">
            <div class="pd-mode">
                <button class="active" id="modeSlideBtn" onclick="setMode('slide')">📽 Slide</button>
                <button id="modeScrollBtn" onclick="setMode('scroll')">📃 Scroll</button>
            </div>
            <div class="pd-nav">
                <button id="navPrev" onclick="prevSlide()" title="Previous (←)">
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/></svg>
                </button>
                <span class="pd-counter" id="navCounter">1 / 22</span>
                <button id="navNext" onclick="nextSlide()" title="Next (→)">
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                </button>
            </div>
            <div style="display:flex;gap:6px">
                <a href="/PITCH_DECK.md" target="_blank" class="btn btn-ghost btn-sm" style="padding:6px 12px;font-size:12px">View .md</a>
                <button onclick="window.print()" class="btn btn-gold btn-sm" style="padding:6px 12px;font-size:12px">Print</button>
            </div>
        </div>

        <!-- ═══════ SLIDES ═══════ -->

        <!-- 1: COVER -->
        <div class="slide cover active" data-idx="1">
            <div class="slide-inner" style="text-align:center">
                <h1>FLiK</h1>
                <div class="tagline">3-Month MVP Build Proposal — Platform OTT untuk 400 Film Klasik Indonesia milik Client</div>
                <div class="badges">
                    <span>3-Bulan Build</span>
                    <span>Rp 60jt/bulan</span>
                    <span>400 Film Ready</span>
                    <span>In-House DRM</span>
                    <span>Bunny CDN</span>
                    <span>DeepSeek V4 Flash</span>
                </div>
                <div class="meta">v2.1 · 2026-05-09 · Verified Pricing · Hybrid DRM (DIY + EZDRM Optional)</div>
            </div>
        </div>

        <!-- 2: WHAT'S DIFFERENT -->
        <div class="slide" data-idx="2">
            <div class="slide-inner">
                <div class="slide-num">01 — Apa yang Berubah dari v1.0</div>
                <h2>Realistis untuk Client Menengah</h2>
                <p class="slide-sub">v1.0 over-engineered untuk enterprise (1M users, deal Hollywood). v2.1 sesuai realita: client udah punya 400 film, mau in-house, budget Rp 60jt × 3.</p>
                <div class="pd-tbl-wrap">
                <table class="pd-table">
                    <thead><tr><th>Aspek</th><th>v1.0 (Lama)</th><th>v2.1 (Realistis)</th><th>Save</th></tr></thead>
                    <tbody>
                        <tr><td>Konten</td><td>350 film, ~$20K akuisisi</td><td><b>400 film sudah dimiliki</b></td><td class="cost">Rp 320 jt</td></tr>
                        <tr><td>Build budget</td><td>$165K Year-1 (Rp 2,6 M)</td><td><b>Rp 180jt 3-bulan</b></td><td class="cost">93%</td></tr>
                        <tr><td>AI scope</td><td>Multi-vendor SaaS</td><td><b>In-house API langsung</b></td><td class="cost">No lock-in</td></tr>
                        <tr class="hi"><td>CDN</td><td>CloudFront $0.114/GB Asia</td><td><b>Bunny $0.01/GB</b></td><td class="cost">11×</td></tr>
                        <tr class="hi"><td>AI default</td><td>gpt-4o-mini $0.15/$0.60</td><td><b>DeepSeek V4 Flash $0.14/$0.28</b></td><td class="cost">53%</td></tr>
                        <tr><td>Subtitle</td><td>Whisper $0.006/min</td><td><b>gpt-4o-mini-transcribe $0.003/min</b></td><td class="cost">50%</td></tr>
                        <tr class="hi"><td>DRM</td><td>EZDRM $200/bln wajib</td><td><b>DIY in-house (gratis), EZDRM optional</b></td><td class="cost">Rp 38jt/thn</td></tr>
                    </tbody>
                </table>
                </div>
                <div class="pd-callout green">
                    <b>Hasil akhir</b>
                    <p>Build cost <b>Rp 180jt total</b>. Post-launch OPEX di 1.000 user = <b>Rp 7,4jt/bulan</b> (DIY DRM). Margin 62% bahkan di skala terkecil.</p>
                </div>
            </div>
        </div>

        <!-- 3: EXECUTIVE SUMMARY -->
        <div class="slide" data-idx="3">
            <div class="slide-inner">
                <div class="slide-num">02 — Executive Summary</div>
                <h2>Build di Atas Foundation Existing</h2>
                <p class="slide-sub">Bukan rebuild from scratch. 60% feature sudah jalan. 3 bulan untuk tambah distribution layer (DRM in-house, transcoding, CDN) + AI layer.</p>
                <div class="pd-grid-2">
                    <div class="pd-card green">
                        <h3>✅ Sudah ada (existing)</h3>
                        <ul class="pd-list">
                            <li>Web app + admin panel</li>
                            <li>Payment Midtrans (live)</li>
                            <li>Gamification XP/coin/achievement</li>
                            <li>PWA + NativePHP Android</li>
                            <li>Auth + Google OAuth</li>
                            <li>AI provider settings (encrypted API key)</li>
                        </ul>
                    </div>
                    <div class="pd-card gold">
                        <h3>🚀 Akan dibangun (3 bulan)</h3>
                        <ul class="pd-list">
                            <li>Transcoding pipeline (FFmpeg / MediaConvert)</li>
                            <li><b>DIY DRM stack</b> (AES-128 + key server + watermark)</li>
                            <li>Bunny CDN integration</li>
                            <li>AI service layer (in-house)</li>
                            <li>Semantic search + recommendations</li>
                            <li>Auto-subtitle + auto-tagging</li>
                            <li>EZDRM toggle per-film (optional)</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- 4: BUDGET -->
        <div class="slide" data-idx="4">
            <div class="slide-inner">
                <div class="slide-num">03 — Build Budget Breakdown</div>
                <h2>Rp 180 juta untuk 3 Bulan</h2>
                <p class="slide-sub">Pas di Rp 60jt × 3. Engineering ~58% (termasuk build DIY DRM 2-3 minggu). EZDRM tidak masuk core build — optional aktivasi setelah launch.</p>
                <div class="pd-tbl-wrap">
                <table class="pd-table">
                    <thead><tr><th>Item</th><th>Per Bulan</th><th style="text-align:right">3 Bulan</th><th>Catatan</th></tr></thead>
                    <tbody>
                        <tr class="hi"><td><b>Engineering</b> (1 senior FT)</td><td>Rp 35 jt</td><td style="text-align:right" class="cost">Rp 105 jt</td><td>Termasuk DIY DRM build 2-3 minggu</td></tr>
                        <tr><td>AWS dev/staging</td><td>Rp 1,5 jt ($95)</td><td style="text-align:right">Rp 4,5 jt</td><td>Small EC2 + RDS t3.small</td></tr>
                        <tr><td>Bunny CDN (dev)</td><td>Rp 800rb ($50)</td><td style="text-align:right">Rp 2,4 jt</td><td>Storage + minim egress</td></tr>
                        <tr><td>AI dev (Claude Max + DeepSeek)</td><td>Rp 4,5 jt ($280)</td><td style="text-align:right">Rp 13,5 jt</td><td>Claude Max $200 + DeepSeek $80</td></tr>
                        <tr><td>Domain, SSL, monitoring</td><td>Rp 800rb</td><td style="text-align:right">Rp 2,4 jt</td><td></td></tr>
                        <tr><td>One-time AI process 400 film</td><td>—</td><td style="text-align:right">Rp 12 jt</td><td>Subtitle + tagging + thumbnail</td></tr>
                        <tr><td>MaxMind GeoIP2 license</td><td>—</td><td style="text-align:right">Rp 1 jt</td><td>1 tahun, untuk DIY DRM geo</td></tr>
                        <tr><td>Buffer 25%</td><td>—</td><td style="text-align:right">Rp 39 jt</td><td>Lebih besar (DIY butuh QA)</td></tr>
                        <tr style="border-top:2px solid #C5A55A;background:rgba(197,165,90,0.08)"><td><b>TOTAL</b></td><td></td><td style="text-align:right" class="cost"><b style="font-size:16px">~Rp 180 jt</b></td><td>Pas di budget</td></tr>
                        <tr style="opacity:0.6;font-style:italic"><td>Optional: EZDRM (kalau aktif)</td><td>Rp 3,2jt/bln</td><td style="text-align:right">opsional</td><td>Pay only when activated</td></tr>
                    </tbody>
                </table>
                </div>
            </div>
        </div>

        <!-- 5: AI PRICING -->
        <div class="slide" data-idx="5">
            <div class="slide-inner">
                <div class="slide-num">04 — AI Pricing per Mei 2026 (Verified)</div>
                <h2>Model Termurah Per Task</h2>
                <p class="slide-sub">Semua harga $/1M token. Sumber publik Mei 2026 (lihat slide terakhir).</p>
                <div class="pd-tbl-wrap">
                <table class="pd-table">
                    <thead><tr><th>Provider</th><th>Model</th><th>Input</th><th>Output</th><th>Use Case</th></tr></thead>
                    <tbody>
                        <tr class="hi"><td><b>DeepSeek</b></td><td><b>V4 Flash</b></td><td class="cost">$0.14</td><td class="cost">$0.28</td><td><b>DEFAULT — semua task standar</b></td></tr>
                        <tr><td>DeepSeek</td><td>V4 Pro</td><td>$0.435 → $1.74</td><td>$0.87 → $3.48</td><td>Promo s/d 5 Mei 2026</td></tr>
                        <tr><td>DeepSeek</td><td>chat (V3.2)</td><td>$0.28</td><td>$0.42</td><td><span class="pd-pill red">Deprecated 24 Juli 2026</span></td></tr>
                        <tr class="hi"><td><b>Gemini</b></td><td><b>2.5 Flash-Lite</b></td><td class="cost">$0.10</td><td class="cost">$0.40</td><td><b>TERMURAH. Multimodal vision.</b></td></tr>
                        <tr><td>Gemini</td><td>2.5 Flash</td><td>$0.30</td><td>$2.50</td><td>Audio input $1.00</td></tr>
                        <tr><td>Gemini</td><td>3.0 Flash</td><td>$0.50</td><td>$3.00</td><td>Latest multimodal</td></tr>
                        <tr><td>Gemini</td><td>2.5 Pro</td><td>$1.25</td><td>$10.00</td><td>≤200K context</td></tr>
                        <tr><td>Anthropic</td><td>Haiku 4.5</td><td>$1.00</td><td>$5.00</td><td>Reasoning ringan + klasifikasi</td></tr>
                        <tr><td>Anthropic</td><td>Sonnet 4.6</td><td>$3.00</td><td>$15.00</td><td>Long-form synopsis</td></tr>
                        <tr><td>Anthropic</td><td>Opus 4.7</td><td>$5.00</td><td>$25.00</td><td>Released 16 April 2026</td></tr>
                        <tr><td>OpenAI</td><td>gpt-5.4-nano</td><td>$0.20</td><td>$1.25</td><td>Latest nano-tier</td></tr>
                        <tr><td>OpenAI</td><td>gpt-5.4</td><td>$2.50</td><td>$15.00</td><td>Standard</td></tr>
                        <tr><td>OpenAI</td><td>gpt-5.5</td><td>$5.00</td><td>$30.00</td><td>Flagship May 2026</td></tr>
                        <tr class="hi"><td><b>OpenAI</b></td><td><b>gpt-4o-mini-transcribe</b></td><td colspan="2" class="cost">$0.003/menit</td><td><b>SUBTITLE — 50% cheaper than Whisper</b></td></tr>
                        <tr><td>Groq</td><td>Llama 4 Maverick</td><td>$0.15</td><td>$0.60</td><td>Ultra-low latency (LPU)</td></tr>
                    </tbody>
                </table>
                </div>
                <div class="pd-callout green">
                    <b>Discount Levers</b>
                    <p>Prompt caching: <b>90% off</b> cached input · Batch API: <b>50% off</b> · DeepSeek cache hit: <b>$0.003/MTok</b> (47× lebih murah)</p>
                </div>
            </div>
        </div>

        <!-- 6: AI COST -->
        <div class="slide" data-idx="6">
            <div class="slide-inner">
                <div class="slide-num">05 — Total AI Cost Realistis</div>
                <h2>One-time + Ongoing</h2>
                <div class="pd-grid-2">
                    <div class="pd-card gold">
                        <h3>One-time (400 film)</h3>
                        <table class="pd-table" style="margin-top:6px">
                            <tbody>
                                <tr><td>Subtitle (gpt-4o-mini-transcribe)</td><td class="cost" style="text-align:right">$108</td></tr>
                                <tr><td>Auto-tagging</td><td class="cost" style="text-align:right">$40</td></tr>
                                <tr><td>Translation 3 bahasa</td><td class="cost" style="text-align:right">$200</td></tr>
                                <tr><td>Trailer suggest (vision)</td><td class="cost" style="text-align:right">$80</td></tr>
                                <tr><td>Thumbnail pick</td><td class="cost" style="text-align:right">$20</td></tr>
                                <tr><td>Synopsis generation</td><td class="cost" style="text-align:right">$120</td></tr>
                                <tr><td>QC classification</td><td class="cost" style="text-align:right">$20</td></tr>
                                <tr style="border-top:2px solid #C5A55A"><td><b>TOTAL</b></td><td style="text-align:right" class="cost"><b>$590<br>~Rp 9,5jt</b></td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="pd-card gold">
                        <h3>Ongoing per Skala</h3>
                        <table class="pd-table" style="margin-top:6px">
                            <thead><tr><th>MAU</th><th>USD/bln</th><th>Rupiah</th></tr></thead>
                            <tbody>
                                <tr><td>1.000</td><td class="cost">$8</td><td>Rp 130rb</td></tr>
                                <tr><td>10.000</td><td class="cost">$80</td><td>Rp 1,3 jt</td></tr>
                                <tr><td>100.000</td><td class="cost">$800</td><td>Rp 13 jt</td></tr>
                                <tr><td>1.000.000</td><td class="cost">$8.000</td><td>Rp 128 jt</td></tr>
                            </tbody>
                        </table>
                        <p style="margin-top:8px;color:#22c55e;font-size:12px"><b>AI = ~7% dari total OPEX</b> di skala 100K. CDN dominan.</p>
                    </div>
                </div>
                <div class="pd-callout">
                    <b>Subscription vs API</b>
                    <p>Claude Max $200/bln cocok untuk <b>development</b> (1-3 dev). Untuk <b>production multi-user wajib API</b> — kena rate limit per akun. Tapi API per-token jauh lebih murah: 1.000 MAU FLiK ≈ <b>$8/bln API cost</b>.</p>
                </div>
            </div>
        </div>

        <!-- 7: CDN -->
        <div class="slide" data-idx="7">
            <div class="slide-inner">
                <div class="slide-num">06 — CDN Strategy: Bunny.net</div>
                <h2>Critical Choice — 11× Lebih Murah</h2>
                <p class="slide-sub">CDN egress = ~70% total OPEX. Pilihan CDN = penghematan terbesar yang tersedia.</p>
                <div class="pd-tbl-wrap">
                <table class="pd-table">
                    <thead><tr><th>CDN</th><th>Per GB Asia (10TB)</th><th>Per GB Volume</th><th>Notes</th></tr></thead>
                    <tbody>
                        <tr><td><b>AWS CloudFront</b></td><td class="neg">$0.114</td><td>$0.020 (5PB+)</td><td>Bundled features, mahal di Asia</td></tr>
                        <tr class="hi"><td><b>Bunny Stream</b></td><td class="cost">$0.01</td><td class="cost">$0.005</td><td><b>Includes transcoding free</b></td></tr>
                        <tr><td>Bunny CDN Standard</td><td class="cost">$0.005</td><td class="cost">$0.003 (10TB+)</td><td>Untuk static asset</td></tr>
                        <tr><td>Cloudflare Stream</td><td>~$0.40/GB delivered</td><td>—</td><td>Bundle stream+CDN+DRM</td></tr>
                        <tr><td>Indonesia local (BizNet/Telkom)</td><td>~$0.06 (Rp 1.000)</td><td>Negotiable</td><td>Latency lokal terbaik</td></tr>
                    </tbody>
                </table>
                </div>
                <div class="pd-grid-2">
                    <div class="pd-card">
                        <h3>1.000 user (22 TB/bln)</h3>
                        <p>CloudFront: <b style="color:#ef4444">$2.508/bln</b><br>Bunny: <b style="color:#22c55e">$220/bln</b></p>
                        <div class="num green">Save Rp 36 jt/bln</div>
                    </div>
                    <div class="pd-card">
                        <h3>10.000 user (220 TB/bln)</h3>
                        <p>CloudFront: <b style="color:#ef4444">$25.080/bln</b><br>Bunny: <b style="color:#22c55e">$1.100/bln</b></p>
                        <div class="num green">Save Rp 384 jt/bln</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 8: DRM HYBRID -->
        <div class="slide" data-idx="8">
            <div class="slide-inner">
                <div class="slide-num">07 — DRM Strategy (Hybrid)</div>
                <h2>DIY In-House Primary + EZDRM Optional</h2>
                <p class="slide-sub">Konten = film klasik milik client (bukan rilisan Hollywood). DIY DRM cukup untuk casual piracy protection. EZDRM disediakan optional per-film.</p>

                <div class="pd-tier t1">
                    <div class="stars">⭐⭐⭐⭐</div>
                    <div class="info"><b>DIY In-House (Default)</b><span>AES-128 + key server + token rotation + watermarking + concurrent limit + geo-block. <span class="pd-pill blue">Pure Laravel</span></span></div>
                    <div class="price">Rp 0/bln</div>
                </div>
                <div class="pd-tier t3">
                    <div class="stars">⭐⭐⭐⭐</div>
                    <div class="info"><b>EZDRM (Optional Toggle Per-Film)</b><span>Widevine + PlayReady + FairPlay. Untuk konten premium / iOS Safari support. <span class="pd-pill gold">Verified $200 starter</span></span></div>
                    <div class="price">$200/bln</div>
                </div>
                <div class="pd-tier t4">
                    <div class="stars">⭐⭐⭐⭐⭐</div>
                    <div class="info"><b>Hardware-backed (Skip)</b><span>Untuk deal Hollywood / 4K HDR. Tidak relevan. <span class="pd-pill red">Skip</span></span></div>
                    <div class="price">$5K+/bln</div>
                </div>

                <div class="pd-callout green">
                    <b>Save vs EZDRM-mandatory</b>
                    <p>Per bulan: Rp 3,2 jt · Per tahun: <b>Rp 38,4 jt</b> · 3 tahun: <b>~Rp 120 juta</b></p>
                </div>
            </div>
        </div>

        <!-- 9: DRM DIY DETAIL -->
        <div class="slide" data-idx="9">
            <div class="slide-inner">
                <div class="slide-num">08 — DIY DRM Stack Detail</div>
                <h2>13 Komponen Buatan Sendiri</h2>
                <p class="slide-sub">Build sekitar 2-3 minggu untuk 1 senior dev. Sudah masuk engineering budget.</p>
                <div class="pd-grid-2">
                    <div class="pd-card">
                        <ul class="pd-list">
                            <li><b>AES-128/256 segment encryption</b> via FFmpeg</li>
                            <li><b>Key delivery server</b> Laravel: JWT + auth + sub + geo + device</li>
                            <li><b>Token rotation</b> per 5 menit, signed APP_KEY</li>
                            <li><b>Signed URLs</b> ke Bunny (5-15min expiry)</li>
                            <li><b>Concurrent stream limit</b> via Redis counter</li>
                            <li><b>Device fingerprinting</b> canvas+WebGL+audio</li>
                            <li><b>Watermarking forensik</b> burn-in user ID</li>
                        </ul>
                    </div>
                    <div class="pd-card">
                        <ul class="pd-list">
                            <li><b>Geo-blocking</b> via MaxMind GeoIP2</li>
                            <li><b>Anti-debugging</b> DevTools detector</li>
                            <li><b>Session binding</b> key per session token</li>
                            <li><b>Dynamic playlist</b> M3U8 generated per request</li>
                            <li><b>Anti-replay</b> nonce + timestamp JWT</li>
                            <li><b>Bandwidth throttle</b> via Bunny edge config</li>
                        </ul>
                    </div>
                </div>
                <div class="pd-callout">
                    <b>Yang TIDAK bisa buatan sendiri (fakta teknis)</b>
                    <p><b>Widevine, PlayReady, FairPlay</b> = proprietary Google/MS/Apple. License server harus certified, SDK butuh NDA. Konsekuensi: iOS Safari user paksa PWA install. Solusi optional: aktifkan EZDRM per-film tertentu.</p>
                </div>
            </div>
        </div>

        <!-- 10: OPEX OVERVIEW -->
        <div class="slide" data-idx="10">
            <div class="slide-inner">
                <div class="slide-num">09 — Post-Launch OPEX Matrix</div>
                <h2>4 Skenario Skala (DIY DRM)</h2>
                <p class="slide-sub">Bunny CDN primary + DIY DRM + DeepSeek V4 Flash AI default.</p>
                <div class="pd-grid-4">
                    <div class="pd-card gold">
                        <h3>1.000 MAU</h3>
                        <div class="num green">Rp 7,4jt</div>
                        <p>per bulan · $460<br><b>$1.15/paid user</b><br>Margin 62%</p>
                    </div>
                    <div class="pd-card gold">
                        <h3>10.000 MAU</h3>
                        <div class="num green">Rp 30jt</div>
                        <p>per bulan · $1.870<br><b>$0.47/paid user</b><br>Margin 85%</p>
                    </div>
                    <div class="pd-card gold">
                        <h3>100.000 MAU</h3>
                        <div class="num green">Rp 189jt</div>
                        <p>per bulan · $11.850<br><b>$0.30/paid user</b><br>Margin 90%</p>
                    </div>
                    <div class="pd-card gold">
                        <h3>1.000.000 MAU</h3>
                        <div class="num green">Rp 1,30M</div>
                        <p>per bulan · $81.500<br><b>$0.20/paid user</b><br>Margin 93%</p>
                    </div>
                </div>
                <div class="pd-callout green">
                    <b>v1.0 → v2.1 Comparison</b>
                    <p>1K: Rp 43,7jt → <b>Rp 7,4jt</b> (83% save) · 10K: Rp 310jt → <b>Rp 30jt</b> (90%) · 100K: Rp 2,04M → <b>Rp 189jt</b> (91%) · 1M: Rp 11,2M → <b>Rp 1,3M</b> (88%). Karena Bunny CDN + DIY DRM.</p>
                </div>
            </div>
        </div>

        <!-- 11: COST DETAIL 1K -->
        <div class="slide" data-idx="11">
            <div class="slide-inner">
                <div class="slide-num">10 — OPEX Detail · 1.000 MAU</div>
                <h2>Rp 7,4 juta/bulan untuk Soft Launch</h2>
                <div class="pd-tbl-wrap">
                <table class="pd-table">
                    <thead><tr><th>Komponen</th><th>Spec</th><th style="text-align:right">USD/bln</th><th style="text-align:right">Rupiah</th></tr></thead>
                    <tbody>
                        <tr class="hi"><td><b>Bunny Stream CDN</b></td><td>22 TB egress @ $0.01</td><td class="cost" style="text-align:right">$220</td><td style="text-align:right">Rp 3,52 jt</td></tr>
                        <tr><td>Bunny Storage</td><td>1.2 TB transcoded</td><td style="text-align:right">$6</td><td style="text-align:right">Rp 96rb</td></tr>
                        <tr><td>AWS S3 backup</td><td>700 GB Standard-IA</td><td style="text-align:right">$9</td><td style="text-align:right">Rp 144rb</td></tr>
                        <tr><td>EC2 t3.medium</td><td>Laravel app</td><td style="text-align:right">$30</td><td style="text-align:right">Rp 480rb</td></tr>
                        <tr><td>RDS MySQL t3.small Multi-AZ</td><td>50 GB</td><td style="text-align:right">$50</td><td style="text-align:right">Rp 800rb</td></tr>
                        <tr><td>RDS PostgreSQL t3.micro</td><td>20 GB pgvector</td><td style="text-align:right">$25</td><td style="text-align:right">Rp 400rb</td></tr>
                        <tr><td>ElastiCache Redis t3.micro</td><td>—</td><td style="text-align:right">$15</td><td style="text-align:right">Rp 240rb</td></tr>
                        <tr><td>ALB + CloudWatch + SES</td><td>—</td><td style="text-align:right">$35</td><td style="text-align:right">Rp 560rb</td></tr>
                        <tr class="hi"><td><b>DIY DRM</b></td><td>key server di Laravel app</td><td class="cost" style="text-align:right">$0</td><td class="cost" style="text-align:right">Rp 0</td></tr>
                        <tr class="hi"><td><b>AI APIs</b></td><td>DeepSeek V4 Flash + Whisper</td><td class="cost" style="text-align:right">$8</td><td style="text-align:right">Rp 128rb</td></tr>
                        <tr><td>Domain, SSL, monitoring</td><td>—</td><td style="text-align:right">$20</td><td style="text-align:right">Rp 320rb</td></tr>
                        <tr style="border-top:2px solid #C5A55A;background:rgba(197,165,90,0.08)"><td><b>TOTAL</b></td><td><b style="color:#22c55e">Rp 18K/paid user</b></td><td style="text-align:right" class="cost"><b style="font-size:16px">$460</b></td><td style="text-align:right" class="cost"><b style="font-size:16px">Rp 7,4 jt</b></td></tr>
                        <tr style="opacity:0.6;font-style:italic"><td>Optional: EZDRM aktif</td><td>—</td><td style="text-align:right">+$200</td><td style="text-align:right">+Rp 3,2 jt</td></tr>
                    </tbody>
                </table>
                </div>
                <div class="pd-callout green">
                    <b>Margin Check @ Rp 49K subscription</b>
                    <p>1.000 MAU × 40% paid = 400 paid × Rp 49rb = Rp 19,6jt revenue · OPEX Rp 7,4jt → <b>Margin ~62%</b>. Profitable bahkan di skala TERkecil.</p>
                </div>
            </div>
        </div>

        <!-- 12: COST DETAIL 10K -->
        <div class="slide" data-idx="12">
            <div class="slide-inner">
                <div class="slide-num">11 — OPEX Detail · 10.000 MAU</div>
                <h2>Rp 30 juta/bulan</h2>
                <div class="pd-tbl-wrap">
                <table class="pd-table">
                    <thead><tr><th>Komponen</th><th>Spec</th><th style="text-align:right">USD/bln</th><th style="text-align:right">Rupiah</th></tr></thead>
                    <tbody>
                        <tr class="hi"><td><b>Bunny Stream CDN</b></td><td>220 TB @ $0.005</td><td class="cost" style="text-align:right">$1.100</td><td style="text-align:right">Rp 17,6 jt</td></tr>
                        <tr><td>Bunny Storage + S3 backup</td><td>2.5 TB total</td><td style="text-align:right">$21</td><td style="text-align:right">Rp 336rb</td></tr>
                        <tr><td>EC2 (2× t3.large)</td><td>Laravel + key servers</td><td style="text-align:right">$200</td><td style="text-align:right">Rp 3,2 jt</td></tr>
                        <tr><td>RDS t3.medium Multi-AZ</td><td>200 GB</td><td style="text-align:right">$200</td><td style="text-align:right">Rp 3,2 jt</td></tr>
                        <tr><td>RDS PostgreSQL t3.small</td><td>50 GB</td><td style="text-align:right">$50</td><td style="text-align:right">Rp 800rb</td></tr>
                        <tr><td>Redis + ALB + WAF</td><td>—</td><td style="text-align:right">$80</td><td style="text-align:right">Rp 1,28 jt</td></tr>
                        <tr><td>Monitoring + SES</td><td>500K emails</td><td style="text-align:right">$100</td><td style="text-align:right">Rp 1,6 jt</td></tr>
                        <tr class="hi"><td><b>DIY DRM</b></td><td>multi-instance</td><td class="cost" style="text-align:right">$0</td><td class="cost" style="text-align:right">Rp 0</td></tr>
                        <tr><td>AI APIs</td><td>scaled</td><td class="cost" style="text-align:right">$80</td><td style="text-align:right">Rp 1,28 jt</td></tr>
                        <tr><td>Misc</td><td>—</td><td style="text-align:right">$40</td><td style="text-align:right">Rp 640rb</td></tr>
                        <tr style="border-top:2px solid #C5A55A;background:rgba(197,165,90,0.08)"><td><b>TOTAL</b></td><td><b style="color:#22c55e">Rp 7,5K/paid user</b></td><td style="text-align:right" class="cost"><b style="font-size:16px">$1.870</b></td><td style="text-align:right" class="cost"><b style="font-size:16px">Rp 30 jt</b></td></tr>
                    </tbody>
                </table>
                </div>
                <div class="pd-callout green">
                    <b>Margin @ 10K MAU</b>
                    <p>4.000 paid × Rp 49rb = Rp 196jt revenue · OPEX Rp 30jt → <b>Margin ~85%</b>. ✅✅</p>
                </div>
            </div>
        </div>

        <!-- 13: COST DETAIL 100K -->
        <div class="slide" data-idx="13">
            <div class="slide-inner">
                <div class="slide-num">12 — OPEX Detail · 100.000 MAU</div>
                <h2>Rp 189 juta/bulan</h2>
                <div class="pd-tbl-wrap">
                <table class="pd-table">
                    <thead><tr><th>Komponen</th><th style="text-align:right">USD/bln</th><th style="text-align:right">Rupiah</th></tr></thead>
                    <tbody>
                        <tr class="hi"><td><b>Bunny CDN</b> (2.2 PB @ $0.003)</td><td class="cost" style="text-align:right">$6.600</td><td style="text-align:right">Rp 105,6 jt</td></tr>
                        <tr><td>Storage (Bunny + S3)</td><td style="text-align:right">$50</td><td style="text-align:right">Rp 800rb</td></tr>
                        <tr><td>EC2 cluster (8× t3.xlarge)</td><td style="text-align:right">$1.000</td><td style="text-align:right">Rp 16 jt</td></tr>
                        <tr><td>RDS r5.large Multi-AZ + replica</td><td style="text-align:right">$700</td><td style="text-align:right">Rp 11,2 jt</td></tr>
                        <tr><td>RDS PostgreSQL r5.large</td><td style="text-align:right">$400</td><td style="text-align:right">Rp 6,4 jt</td></tr>
                        <tr><td>Redis cluster</td><td style="text-align:right">$300</td><td style="text-align:right">Rp 4,8 jt</td></tr>
                        <tr><td>OpenSearch (3 nodes)</td><td style="text-align:right">$600</td><td style="text-align:right">Rp 9,6 jt</td></tr>
                        <tr><td>ALB + WAF + Shield</td><td style="text-align:right">$200</td><td style="text-align:right">Rp 3,2 jt</td></tr>
                        <tr><td>Monitoring (Datadog/Sentry)</td><td style="text-align:right">$400</td><td style="text-align:right">Rp 6,4 jt</td></tr>
                        <tr><td>SES (5M emails)</td><td style="text-align:right">$500</td><td style="text-align:right">Rp 8 jt</td></tr>
                        <tr class="hi"><td><b>DIY DRM</b> (key servers di EC2)</td><td class="cost" style="text-align:right">$0</td><td class="cost" style="text-align:right">Rp 0</td></tr>
                        <tr><td>AI APIs</td><td class="cost" style="text-align:right">$800</td><td style="text-align:right">Rp 12,8 jt</td></tr>
                        <tr><td>Misc (NAT, KMS, secrets)</td><td style="text-align:right">$300</td><td style="text-align:right">Rp 4,8 jt</td></tr>
                        <tr style="border-top:2px solid #C5A55A;background:rgba(197,165,90,0.08)"><td><b>TOTAL</b></td><td style="text-align:right" class="cost"><b style="font-size:16px">$11.850</b></td><td style="text-align:right" class="cost"><b style="font-size:16px">Rp 189 jt</b></td></tr>
                        <tr style="opacity:0.6;font-style:italic"><td>Optional: EZDRM enterprise</td><td style="text-align:right">+$1.500</td><td style="text-align:right">+Rp 24 jt</td></tr>
                    </tbody>
                </table>
                </div>
                <div class="pd-callout green">
                    <b>Margin @ 100K MAU</b>
                    <p>40K paid × Rp 49rb = Rp 1,96 miliar revenue · OPEX Rp 189jt → <b>Margin ~90%</b>. ✅✅</p>
                </div>
            </div>
        </div>

        <!-- 14a: P&L PROFIT/BONCOS -->
        <div class="slide" data-idx="14">
            <div class="slide-inner">
                <div class="slide-num">13 — Full P&L per Skala — Boncos atau Untung?</div>
                <h2>Revenue vs SEMUA Pengeluaran</h2>
                <p class="slide-sub">Bukan cuma tech. Termasuk marketing/CAC, CS, payment processing, engineering team, office. Asumsi pricing Rp 49rb/bln, conversion 40%.</p>
                <div class="pd-tbl-wrap">
                <table class="pd-table">
                    <thead><tr><th>Item</th><th style="text-align:right">1K MAU</th><th style="text-align:right">10K MAU</th><th style="text-align:right">100K MAU</th><th style="text-align:right">1M MAU</th></tr></thead>
                    <tbody>
                        <tr class="hi"><td><b>Revenue</b></td><td class="cost" style="text-align:right">Rp 19,6 jt</td><td class="cost" style="text-align:right">Rp 196 jt</td><td class="cost" style="text-align:right">Rp 1,96 M</td><td class="cost" style="text-align:right">Rp 19,6 M</td></tr>
                        <tr><td>Tech infra (Bunny+AWS+AI+DIY DRM)</td><td style="text-align:right">Rp 7,4 jt</td><td style="text-align:right">Rp 30 jt</td><td style="text-align:right">Rp 189 jt</td><td style="text-align:right">Rp 1,3 M</td></tr>
                        <tr><td>Payment processing (3.5%)</td><td style="text-align:right">Rp 0,7 jt</td><td style="text-align:right">Rp 6,9 jt</td><td style="text-align:right">Rp 69 jt</td><td style="text-align:right">Rp 686 jt</td></tr>
                        <tr><td>Customer Support (staff)</td><td style="text-align:right">Rp 5 jt (1 PT)</td><td style="text-align:right">Rp 18 jt (3)</td><td style="text-align:right">Rp 60 jt (10)</td><td style="text-align:right">Rp 400 jt (50)</td></tr>
                        <tr><td>Marketing / CAC</td><td style="text-align:right">Rp 10 jt</td><td style="text-align:right">Rp 40 jt</td><td style="text-align:right">Rp 280 jt</td><td style="text-align:right">Rp 1,5 M</td></tr>
                        <tr><td>Engineering team (post-build)</td><td style="text-align:right">—</td><td style="text-align:right">Rp 15 jt (1 PT)</td><td style="text-align:right">Rp 105 jt (3)</td><td style="text-align:right">Rp 525 jt (15)</td></tr>
                        <tr><td>BD / Marketing team</td><td style="text-align:right">—</td><td style="text-align:right">—</td><td style="text-align:right">Rp 60 jt (3)</td><td style="text-align:right">Rp 300 jt (15)</td></tr>
                        <tr><td>Office / Legal / Misc</td><td style="text-align:right">—</td><td style="text-align:right">Rp 5 jt</td><td style="text-align:right">Rp 30 jt</td><td style="text-align:right">Rp 150 jt</td></tr>
                        <tr style="border-top:1px solid #2a2a2a"><td><b>Total Cost</b></td><td style="text-align:right">Rp 23,1 jt</td><td style="text-align:right">Rp 115 jt</td><td style="text-align:right">Rp 793 jt</td><td style="text-align:right">Rp 4,86 M</td></tr>
                        <tr style="background:rgba(34,197,94,0.08);border-top:2px solid #22c55e"><td><b>Profit / (Loss)</b></td><td class="neg" style="text-align:right"><b>(Rp 3,5 jt)</b></td><td class="cost" style="text-align:right"><b>Rp 81 jt</b></td><td class="cost" style="text-align:right"><b>Rp 1,17 M</b></td><td class="cost" style="text-align:right"><b>Rp 14,7 M</b></td></tr>
                        <tr style="background:rgba(34,197,94,0.04)"><td><b>Net Margin %</b></td><td class="warn" style="text-align:right"><b>(18%)</b></td><td class="cost" style="text-align:right"><b>41%</b></td><td class="cost" style="text-align:right"><b>60%</b></td><td class="cost" style="text-align:right"><b>75%</b></td></tr>
                    </tbody>
                </table>
                </div>
                <div class="pd-callout green">
                    <b>Verdict</b>
                    <p><b>1K MAU</b>: mild loss wajar (CAC heavy phase) · <b>10K MAU</b>: PROFIT 41% · <b>100K MAU</b>: PROFIT 60% · <b>1M MAU</b>: PROFIT 75%. Tech cost cuma <b>15%</b> di skala 10K, turun ke <b>7%</b> di skala 1M. <b>Tech BUKAN cost driver utama.</b></p>
                </div>
            </div>
        </div>

        <!-- 14b: TECH COST DETAIL BREAKDOWN -->
        <div class="slide" data-idx="15">
            <div class="slide-inner">
                <div class="slide-num">14 — Tech Cost Detail per Kategori</div>
                <h2>Granular untuk Pak Lavesh</h2>
                <p class="slide-sub">Breakdown tech infra per kategori — terlihat CDN dominan, DIY DRM bikin DRM kategori = Rp 0.</p>
                <div class="pd-tbl-wrap">
                <table class="pd-table">
                    <thead><tr><th>Kategori</th><th style="text-align:right">1K MAU</th><th style="text-align:right">10K MAU</th><th style="text-align:right">100K MAU</th><th style="text-align:right">1M MAU</th></tr></thead>
                    <tbody>
                        <tr class="hi"><td><b>CDN (Bunny)</b></td><td style="text-align:right">Rp 3,5 jt (47%)</td><td style="text-align:right">Rp 17,6 jt (59%)</td><td style="text-align:right">Rp 105,6 jt (56%)</td><td style="text-align:right">Rp 704 jt (54%)</td></tr>
                        <tr><td>Storage (Bunny+S3)</td><td style="text-align:right">Rp 0,2 jt</td><td style="text-align:right">Rp 0,3 jt</td><td style="text-align:right">Rp 0,8 jt</td><td style="text-align:right">Rp 3,2 jt</td></tr>
                        <tr><td>Compute (EC2/Fargate)</td><td style="text-align:right">Rp 0,5 jt</td><td style="text-align:right">Rp 3,2 jt</td><td style="text-align:right">Rp 16 jt</td><td style="text-align:right">Rp 72 jt</td></tr>
                        <tr><td>Database (RDS MySQL+PG)</td><td style="text-align:right">Rp 1,2 jt</td><td style="text-align:right">Rp 4 jt</td><td style="text-align:right">Rp 17,6 jt</td><td style="text-align:right">Rp 80 jt</td></tr>
                        <tr><td>Cache (Redis)</td><td style="text-align:right">Rp 0,2 jt</td><td style="text-align:right">Rp 0,5 jt</td><td style="text-align:right">Rp 4,8 jt</td><td style="text-align:right">Rp 24 jt</td></tr>
                        <tr><td>Search (OpenSearch)</td><td style="text-align:right">—</td><td style="text-align:right">—</td><td style="text-align:right">Rp 9,6 jt</td><td style="text-align:right">Rp 28,8 jt</td></tr>
                        <tr><td>Network (ALB+WAF+Shield)</td><td style="text-align:right">Rp 0,3 jt</td><td style="text-align:right">Rp 0,8 jt</td><td style="text-align:right">Rp 3,2 jt</td><td style="text-align:right">Rp 48 jt</td></tr>
                        <tr><td>Monitoring (CW+Sentry+DD)</td><td style="text-align:right">Rp 0,5 jt</td><td style="text-align:right">Rp 1,3 jt</td><td style="text-align:right">Rp 6,4 jt</td><td style="text-align:right">Rp 24 jt</td></tr>
                        <tr><td>Email (SES)</td><td style="text-align:right">Rp 0,1 jt</td><td style="text-align:right">Rp 0,8 jt</td><td style="text-align:right">Rp 8 jt</td><td style="text-align:right">Rp 80 jt</td></tr>
                        <tr class="hi"><td><b>DRM (DIY in-house)</b></td><td class="cost" style="text-align:right">Rp 0</td><td class="cost" style="text-align:right">Rp 0</td><td class="cost" style="text-align:right">Rp 0</td><td class="cost" style="text-align:right">Rp 0</td></tr>
                        <tr class="hi"><td><b>AI APIs</b> (DeepSeek+Whisper+misc)</td><td class="cost" style="text-align:right">Rp 0,1 jt</td><td class="cost" style="text-align:right">Rp 1,3 jt</td><td class="cost" style="text-align:right">Rp 12,8 jt</td><td class="cost" style="text-align:right">Rp 128 jt</td></tr>
                        <tr><td>Misc (NAT, KMS, secrets, backup, domain)</td><td style="text-align:right">Rp 0,3 jt</td><td style="text-align:right">Rp 0,6 jt</td><td style="text-align:right">Rp 4,8 jt</td><td style="text-align:right">Rp 32 jt</td></tr>
                        <tr style="border-top:2px solid #C5A55A;background:rgba(197,165,90,0.08)"><td><b>TOTAL TECH</b></td><td class="cost" style="text-align:right"><b>Rp 7,4 jt</b></td><td class="cost" style="text-align:right"><b>Rp 30 jt</b></td><td class="cost" style="text-align:right"><b>Rp 189 jt</b></td><td class="cost" style="text-align:right"><b>Rp 1,3 M</b></td></tr>
                        <tr style="opacity:0.6;font-style:italic"><td>Optional: EZDRM</td><td style="text-align:right">+Rp 3,2 jt</td><td style="text-align:right">+Rp 6,4 jt</td><td style="text-align:right">+Rp 24 jt</td><td style="text-align:right">+Rp 128 jt</td></tr>
                    </tbody>
                </table>
                </div>
                <div class="pd-callout">
                    <b>Insight</b>
                    <p>CDN selalu dominan (54-59% tech cost). DIY DRM = Rp 0 = save 5-15% tech cost. AI cost negligible (1-7%). Compute & DB scale gradual.</p>
                </div>
            </div>
        </div>

        <!-- 14c: CAC / LTV -->
        <div class="slide" data-idx="16">
            <div class="slide-inner">
                <div class="slide-num">15 — CAC / LTV / Payback Analysis</div>
                <h2>Acquisition Economics</h2>
                <p class="slide-sub">Pak Lavesh minta hitung CAC. Berikut LTV/CAC ratio + payback period per skala. Industry benchmark: ratio > 3:1 sehat, payback < 12 bulan healthy.</p>

                <div class="pd-grid-2">
                    <div class="pd-card gold">
                        <h3>CAC Benchmark Indonesia OTT</h3>
                        <table class="pd-table" style="margin-top:6px">
                            <tbody>
                                <tr><td>Google/FB Ads</td><td class="cost" style="text-align:right">Rp 80–200rb</td></tr>
                                <tr><td>KOL / Influencer</td><td class="cost" style="text-align:right">Rp 50–150rb</td></tr>
                                <tr><td>SEO / Content</td><td class="cost" style="text-align:right">Rp 20–50rb</td></tr>
                                <tr><td>Referral program</td><td class="cost" style="text-align:right">Rp 20–50rb</td></tr>
                                <tr style="border-top:1px solid #C5A55A"><td><b>Blended target FLiK</b></td><td class="cost" style="text-align:right"><b>Rp 70–100rb</b></td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="pd-card green">
                        <h3>LTV Formula</h3>
                        <p style="font-size:13px;color:#ccc;margin-top:8px"><b>LTV = ARPU × Avg Lifetime × Gross Margin</b></p>
                        <p style="font-size:12px;color:#999;margin-top:8px">ARPU Rp 49rb · Lifetime 10-16 bulan (naik dengan skala karena brand loyalty) · Gross margin 62-93% (naik dengan skala karena economies)</p>
                    </div>
                </div>

                <div class="pd-tbl-wrap">
                <table class="pd-table">
                    <thead><tr><th>Skala</th><th>LTV</th><th>CAC target</th><th>LTV/CAC Ratio</th><th>Payback Period</th><th>Status</th></tr></thead>
                    <tbody>
                        <tr><td>1.000 MAU</td><td class="cost">Rp 304rb</td><td>Rp 100rb</td><td><b>3.0×</b></td><td>3.3 bulan</td><td><span class="pd-pill green">Healthy</span></td></tr>
                        <tr class="hi"><td>10.000 MAU</td><td class="cost">Rp 500rb</td><td>Rp 80rb</td><td><b>6.3×</b></td><td>1.9 bulan</td><td><span class="pd-pill green">Excellent</span></td></tr>
                        <tr class="hi"><td>100.000 MAU</td><td class="cost">Rp 617rb</td><td>Rp 70rb</td><td><b>8.8×</b></td><td>1.6 bulan</td><td><span class="pd-pill green">Outstanding</span></td></tr>
                        <tr class="hi"><td>1.000.000 MAU</td><td class="cost">Rp 729rb</td><td>Rp 50rb</td><td><b>14.6×</b></td><td>1.1 bulan</td><td><span class="pd-pill gold">World-class</span></td></tr>
                    </tbody>
                </table>
                </div>

                <div class="pd-callout green">
                    <b>Why FLiK Economics Work</b>
                    <p>1. Niche film klasik = audience self-select via search → CAC rendah · 2. Nostalgia → user lifetime panjang (low churn) · 3. Bunny CDN + DIY DRM → gross margin 85-93% · 4. Konten gratis (client punya 400 film) → no recurring licensing fee</p>
                </div>
            </div>
        </div>

        <!-- 14d: PRICING SENSITIVITY -->
        <div class="slide" data-idx="17">
            <div class="slide-inner">
                <div class="slide-num">16 — Pricing Tier Sensitivity</div>
                <h2>Bagaimana Kalau Pricing Diubah?</h2>
                <p class="slide-sub">Impact ke profit di skala 10.000 MAU (4.000 paid). Default Rp 49rb sudah aman, tapi tier mix bisa boost margin ke 50%+.</p>

                <div class="pd-tbl-wrap">
                <table class="pd-table">
                    <thead><tr><th>Pricing</th><th>Revenue/bln</th><th>Tech Cost</th><th>Other Costs</th><th>Profit/bln</th><th>Margin</th></tr></thead>
                    <tbody>
                        <tr><td>Rp 29.000 (super murah)</td><td class="cost">Rp 116 jt</td><td>Rp 30 jt</td><td>Rp 85 jt</td><td class="warn"><b>Rp 1 jt</b></td><td><b>0.9%</b> ⚠️ tipis</td></tr>
                        <tr class="hi"><td><b>Rp 49.000 (default)</b></td><td class="cost"><b>Rp 196 jt</b></td><td>Rp 30 jt</td><td>Rp 85 jt</td><td class="cost"><b>Rp 81 jt</b></td><td class="cost"><b>41%</b> ✅</td></tr>
                        <tr><td>Rp 79.000 (Premium-only)</td><td class="cost">Rp 316 jt</td><td>Rp 30 jt</td><td>Rp 85 jt</td><td class="cost"><b>Rp 201 jt</b></td><td class="cost"><b>64%</b> ✅✅</td></tr>
                        <tr><td>Rp 99.000 (Premium+)</td><td class="cost">Rp 396 jt</td><td>Rp 30 jt</td><td>Rp 85 jt</td><td class="cost"><b>Rp 281 jt</b></td><td class="cost"><b>71%</b> ✅✅</td></tr>
                        <tr style="background:rgba(197,165,90,0.08)"><td><b>Tier mix realistis</b><br><small style="color:#888">60% Basic 39rb, 30% Premium 79rb, 10% Family 129rb<br>Blended ARPU: Rp 60rb</small></td><td class="cost">Rp 240 jt</td><td>Rp 30 jt</td><td>Rp 85 jt</td><td class="cost"><b>Rp 125 jt</b></td><td class="cost"><b>52%</b> ✅✅</td></tr>
                    </tbody>
                </table>
                </div>

                <div class="pd-grid-3" style="margin-top:16px">
                    <div class="pd-card gold">
                        <h3>💡 Annual plan -20%</h3>
                        <p>User prepay 12 bulan. Cuts CAC payback in half. Reduce churn dramatic.</p>
                    </div>
                    <div class="pd-card gold">
                        <h3>👨‍👩‍👧 Family Rp 129rb</h3>
                        <p>6 device + 4 profile. Boost ARPU rata-rata + reduce churn (account dipakai keluarga).</p>
                    </div>
                    <div class="pd-card gold">
                        <h3>➕ Add-on Rp 19rb</h3>
                        <p>Behind-the-scene, restoration documentary, audio commentary. Pure margin add-on.</p>
                    </div>
                </div>

                <div class="pd-callout">
                    <b>Rekomendasi Strategis</b>
                    <p>Launch dengan tier mix realistis (Rp 60rb blended ARPU). Hindari Rp 29rb karena margin tipis. Annual plan diskon untuk hedge churn. Add-on premium untuk pure margin boost.</p>
                </div>
            </div>
        </div>

        <!-- ORIGINAL 14: YEAR-ON-YEAR (now slide 18) -->
        <div class="slide" data-idx="18">
            <div class="slide-inner">
                <div class="slide-num">17a — Year-on-Year Projection</div>
                <h2>Growth Konservatif 2× per Tahun</h2>
                <p class="slide-sub">Build done bulan 1-3, post-launch starts bulan 4. DIY DRM bikin OPEX Year-on-Year tetap kecil.</p>
                <div class="pd-tbl-wrap">
                <table class="pd-table">
                    <thead><tr><th>Periode</th><th>MAU</th><th>OPEX/bln avg</th><th style="text-align:right">Annual</th></tr></thead>
                    <tbody>
                        <tr class="hi"><td><b>Build (Bulan 1-3)</b></td><td>—</td><td>Rp 60jt × 3</td><td class="cost" style="text-align:right"><b>Rp 180 jt</b></td></tr>
                        <tr><td>Bulan 4-6 (post-launch)</td><td>0 → 1.000</td><td>Rp 7,5jt</td><td style="text-align:right">Rp 22 jt</td></tr>
                        <tr><td>Bulan 7-12</td><td>1.000 → 5.000</td><td>Rp 12jt</td><td style="text-align:right">Rp 72 jt</td></tr>
                        <tr style="border-top:1px solid #2a2a2a"><td><b>Year-1 OPEX (9 bln post-build)</b></td><td>avg ~3.000</td><td></td><td class="cost" style="text-align:right"><b>~Rp 94 jt</b></td></tr>
                        <tr><td>Year-2</td><td>5K → 20K avg</td><td>Rp 22jt</td><td class="cost" style="text-align:right"><b>~Rp 264 jt</b></td></tr>
                        <tr><td>Year-3</td><td>20K → 50K avg</td><td>Rp 50jt</td><td class="cost" style="text-align:right"><b>~Rp 600 jt</b></td></tr>
                        <tr style="border-top:2px solid #C5A55A;background:rgba(197,165,90,0.08)"><td><b>3-Year Total</b></td><td></td><td>build + 33 bln OPEX</td><td class="cost" style="text-align:right"><b style="font-size:14px">~Rp 1,14 M</b></td></tr>
                    </tbody>
                </table>
                </div>
                <div class="pd-grid-2">
                    <div class="pd-card">
                        <h3>Year-3 Revenue</h3>
                        <table class="pd-table" style="margin-top:6px">
                            <tbody>
                                <tr><td>End Year-1 (5K MAU)</td><td class="cost" style="text-align:right">Rp 98jt/bln</td></tr>
                                <tr><td>End Year-2 (20K MAU)</td><td class="cost" style="text-align:right">Rp 392jt/bln</td></tr>
                                <tr><td>End Year-3 (50K MAU)</td><td class="cost" style="text-align:right">Rp 980jt/bln</td></tr>
                                <tr><td><b>Annual Year-3</b></td><td class="cost" style="text-align:right"><b>~Rp 7 miliar</b></td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="pd-card green">
                        <h3>Year-3 Margin</h3>
                        <div class="num green">92%</div>
                        <p>Revenue Rp ~7M · OPEX Rp ~600jt. Sangat sehat untuk OTT niche Indonesia.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- 19: ROADMAP -->
        <div class="slide" data-idx="19">
            <div class="slide-inner">
                <div class="slide-num">17 — 3-Bulan Build Roadmap</div>
                <h2>Detail Per Minggu</h2>
                <div class="pd-grid-3">
                    <div class="pd-card">
                        <h3>📅 Bulan 1 — Foundation</h3>
                        <ul class="pd-list">
                            <li>W1: AWS env + Bunny + domain + SSL</li>
                            <li>W2: FFmpeg pipeline + ABR ladder</li>
                            <li>W2-3: Import + transcode 400 film</li>
                            <li>W3-4: <b>DIY DRM core</b> (AES-128 + key server)</li>
                            <li>W4: Shaka Player integration</li>
                        </ul>
                    </div>
                    <div class="pd-card">
                        <h3>📅 Bulan 2 — DRM Polish + AI</h3>
                        <ul class="pd-list">
                            <li>W5: <b>DIY DRM hardening</b> (geo, watermark, fingerprint)</li>
                            <li>W6: AiClient service layer</li>
                            <li>W6-7: Subtitle + tagging pipeline</li>
                            <li>W7-8: pgvector semantic search</li>
                            <li>W8: EZDRM optional toggle (per-film)</li>
                        </ul>
                    </div>
                    <div class="pd-card gold">
                        <h3>📅 Bulan 3 — Polish + Launch</h3>
                        <ul class="pd-list">
                            <li>W9: Recommendation engine (DeepSeek batch)</li>
                            <li>W9: AI chatbot CS (Groq Llama 4)</li>
                            <li>W10: Translation + comment moderation</li>
                            <li>W11: Security audit + load test 5K concurrent</li>
                            <li>W12: Soft launch 100 beta + bug fix</li>
                        </ul>
                    </div>
                </div>
                <div class="pd-callout green">
                    <b>Deliverables Akhir Bulan 3</b>
                    <p>✅ 400 film encoded + DIY DRM protected · ✅ EZDRM toggle ready · ✅ 8 fitur AI live · ✅ Bunny CDN production · ✅ Admin monitoring · ✅ PWA + NativePHP Android</p>
                </div>
            </div>
        </div>

        <!-- 20: WHY DOABLE -->
        <div class="slide" data-idx="20">
            <div class="slide-inner">
                <div class="slide-num">18 — Mengapa Doable di Rp 60jt × 3</div>
                <h2>7 Alasan Kuat</h2>
                <div class="pd-grid-2">
                    <div class="pd-card gold"><h3>1. Foundation existing</h3><p>Bukan rebuild. App handle 60% feature.</p></div>
                    <div class="pd-card gold"><h3>2. Konten Rp 0</h3><p>Client sudah punya 400 film. No acquisition.</p></div>
                    <div class="pd-card green"><h3>3. Bunny CDN</h3><p>11× lebih murah dari CloudFront Asia.</p></div>
                    <div class="pd-card green"><h3>4. DeepSeek V4 Flash</h3><p>$0.14/$0.28 per MTok — termurah dengan quality OK.</p></div>
                    <div class="pd-card green"><h3>5. DIY DRM</h3><p>Save $200/bln EZDRM. Cukup untuk casual piracy protection.</p></div>
                    <div class="pd-card gold"><h3>6. In-house everything</h3><p>No SaaS lock-in. Margin 60-93%.</p></div>
                    <div class="pd-card" style="grid-column:span 2;border-color:#22c55e">
                        <h3>7. Timeline 3-bulan realistis</h3>
                        <p>1 senior dev FT cukup karena foundation existing. Engineering 58% alokasi yang tepat. DIY DRM masuk dalam timeline (2-3 minggu).</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- 21: SOURCES -->
        <div class="slide" data-idx="21">
            <div class="slide-inner">
                <div class="slide-num">19 — Sources (Verified Mei 2026)</div>
                <h2>Pricing Verification</h2>
                <p class="slide-sub">Semua harga di pitch deck ini diverifikasi dari sumber publik. No speculation.</p>
                <div class="pd-grid-2">
                    <div class="pd-card">
                        <h3>AI Pricing</h3>
                        <ul class="pd-list" style="font-size:11px">
                            <li>benchlm.ai/blog/posts/claude-api-pricing</li>
                            <li>finout.io (Anthropic Opus 4.7 — 16 April 2026)</li>
                            <li>devtk.ai/openai-api-pricing-guide-2026</li>
                            <li>tldl.io/deepseek-api-pricing (V4 Flash)</li>
                            <li>nxcode.io DeepSeek V4 Pro guide</li>
                            <li>api-docs.deepseek.com/quick_start/pricing</li>
                            <li>benchlm.ai gemini-api-pricing</li>
                            <li>ai.google.dev/gemini-api/docs/pricing</li>
                            <li>tokenmix.ai whisper-api-pricing</li>
                            <li>groq.com/pricing</li>
                            <li>pricepertoken.com mistral-ai</li>
                        </ul>
                    </div>
                    <div class="pd-card">
                        <h3>CDN & DRM</h3>
                        <ul class="pd-list" style="font-size:11px">
                            <li>bunny.net/pricing/stream/</li>
                            <li>bunny.net/pricing/</li>
                            <li>aws.amazon.com/cloudfront/pricing/</li>
                            <li>blog.blazingcdn.com (CloudFront 2026 cost)</li>
                            <li>ezdrm.com/service-pricing</li>
                        </ul>
                        <h3 style="margin-top:14px">Notes</h3>
                        <p style="font-size:11px;color:#888">Kurs USD = Rp 16.000. Pricing CDN volume tier-based — angka real bisa bervariasi tergantung negosiasi. EZDRM enterprise pricing untuk >100K active devices custom.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- 22: NEXT STEPS -->
        <div class="slide cover" data-idx="22">
            <div class="slide-inner" style="text-align:center">
                <div class="slide-num" style="margin-bottom:12px">20 — Next Steps</div>
                <h1 style="font-size:48px">Mari Mulai</h1>
                <div class="tagline">Pitch deck v2.1 sudah disesuaikan dengan budget Rp 60jt × 3. Hybrid DRM (DIY + EZDRM optional). Tinggal sign-off & kick-off.</div>

                <div class="pd-grid-3" style="width:100%;max-width:800px;margin:24px auto 0">
                    <div class="pd-card gold">
                        <h3>📋 1. Sign-off</h3>
                        <p>Review pitch v2.1 bareng client</p>
                    </div>
                    <div class="pd-card gold">
                        <h3>🎬 2. Konten</h3>
                        <p>Provide 400 film master files</p>
                    </div>
                    <div class="pd-card gold">
                        <h3>🚀 3. Kick-off</h3>
                        <p>Setup AWS + Bunny + DeepSeek</p>
                    </div>
                </div>

                <div style="margin-top:32px;color:#888;font-size:12px">
                    Pitch deck markdown: <code>PITCH_DECK.md</code><br>
                    AI provider settings: <a href="{{ route('admin.ai.index') }}" style="color:#C5A55A">/admin/ai-settings</a><br>
                    <small style="color:#666;margin-top:8px;display:block">Semua pricing diverifikasi Mei 2026. No speculation.</small>
                </div>
            </div>
        </div>

    </div>

    <script>
        const totalSlides = document.querySelectorAll('.slide').length;
        let currentSlide = 1;
        let mode = 'slide';

        function setMode(m) {
            mode = m;
            const deck = document.getElementById('pdDeck');
            deck.classList.remove('mode-slide', 'mode-scroll');
            deck.classList.add('mode-' + m);
            document.getElementById('modeSlideBtn').classList.toggle('active', m === 'slide');
            document.getElementById('modeScrollBtn').classList.toggle('active', m === 'scroll');
            if (m === 'slide') showSlide(currentSlide);
            else document.querySelectorAll('.slide').forEach(s => s.classList.add('active'));
            updateNavButtons();
        }

        function showSlide(n) {
            currentSlide = Math.max(1, Math.min(totalSlides, n));
            document.querySelectorAll('.slide').forEach(s => {
                s.classList.toggle('active', parseInt(s.dataset.idx) === currentSlide);
            });
            document.getElementById('navCounter').textContent = currentSlide + ' / ' + totalSlides;
            updateNavButtons();
            // scroll to top of slide
            window.scrollTo({ top: 0, behavior: 'instant' });
        }

        function updateNavButtons() {
            document.getElementById('navPrev').disabled = (mode === 'scroll' || currentSlide === 1);
            document.getElementById('navNext').disabled = (mode === 'scroll' || currentSlide === totalSlides);
        }

        function nextSlide() { if (mode === 'slide' && currentSlide < totalSlides) showSlide(currentSlide + 1); }
        function prevSlide() { if (mode === 'slide' && currentSlide > 1) showSlide(currentSlide - 1); }

        document.addEventListener('keydown', function(e) {
            if (mode !== 'slide') return;
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
            if (e.key === 'ArrowRight' || e.key === ' ' || e.key === 'PageDown') { e.preventDefault(); nextSlide(); }
            else if (e.key === 'ArrowLeft' || e.key === 'PageUp') { e.preventDefault(); prevSlide(); }
            else if (e.key === 'Home') { e.preventDefault(); showSlide(1); }
            else if (e.key === 'End') { e.preventDefault(); showSlide(totalSlides); }
        });

        // Initialize
        document.getElementById('navCounter').textContent = '1 / ' + totalSlides;
    </script>

</x-admin.layout>
