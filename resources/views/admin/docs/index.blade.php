<x-admin.layout title="Platform Architecture">

    <script src="https://cdn.jsdelivr.net/npm/mermaid@10/dist/mermaid.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            mermaid.initialize({
                startOnLoad: true,
                theme: 'dark',
                themeVariables: {
                    primaryColor: '#C5A55A',
                    primaryTextColor: '#0a0a0a',
                    primaryBorderColor: '#C5A55A',
                    lineColor: '#888',
                    secondaryColor: '#1f2937',
                    tertiaryColor: '#0a0a0a',
                    background: '#0f0f0f',
                    mainBkg: '#1a1a1a',
                    secondBkg: '#242424',
                    textColor: '#e5e5e5',
                    fontFamily: 'Inter, sans-serif',
                },
                flowchart: { curve: 'basis', useMaxWidth: true, htmlLabels: true },
                sequence: { useMaxWidth: true, mirrorActors: false, actorMargin: 60 },
            });
        });
    </script>

    <style>
        .docs-hero {
            background: radial-gradient(ellipse at top, rgba(197,165,90,0.18), transparent 70%),
                        linear-gradient(180deg, #1a1a1a, #141210);
            border: 1px solid rgba(197,165,90,0.25);
            border-radius: 16px;
            padding: 36px 32px;
            margin-bottom: 28px;
            position: relative;
            overflow: hidden;
        }
        .docs-hero::before {
            content: ''; position: absolute; top: 0; right: 0; bottom: 0;
            width: 240px;
            background: radial-gradient(circle at center, rgba(232,213,163,0.12), transparent 70%);
        }
        .docs-hero h1 {
            font-size: 36px; font-weight: 800; letter-spacing: -1px;
            background: linear-gradient(135deg, #F0D78C 0%, #C5A55A 60%, #8b7239 100%);
            -webkit-background-clip: text; background-clip: text; color: transparent;
            margin-bottom: 8px;
        }
        .docs-hero p.lead {
            font-size: 15px; color: #bbb; max-width: 760px; line-height: 1.6;
        }
        .docs-hero .badges {
            display: flex; gap: 10px; flex-wrap: wrap; margin-top: 18px;
        }
        .docs-hero .badges span {
            background: rgba(197,165,90,0.12);
            border: 1px solid rgba(197,165,90,0.3);
            color: #E8D5A3;
            padding: 6px 14px; border-radius: 20px;
            font-size: 12px; font-weight: 600;
        }

        .docs-stats {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px; margin-bottom: 32px;
        }
        .docs-stat {
            background: linear-gradient(180deg, #1a1a1a, #141414);
            border: 1px solid #2a2a2a; border-radius: 12px;
            padding: 18px 16px; text-align: center;
            transition: all .18s ease;
        }
        .docs-stat:hover { border-color: rgba(197,165,90,0.4); transform: translateY(-2px); }
        .docs-stat .num {
            font-size: 28px; font-weight: 800; color: #C5A55A;
            font-family: 'Outfit', sans-serif;
        }
        .docs-stat .label {
            font-size: 11px; color: #888; text-transform: uppercase;
            letter-spacing: 0.8px; font-weight: 600; margin-top: 2px;
        }

        .docs-section {
            margin: 40px 0 28px;
        }
        .docs-section-title {
            display: flex; align-items: center; gap: 12px;
            margin-bottom: 6px;
        }
        .docs-section-title .num-pill {
            width: 36px; height: 36px;
            background: linear-gradient(135deg, #C5A55A, #E8D5A3);
            color: #0a0a0a;
            border-radius: 10px;
            display: inline-flex; align-items: center; justify-content: center;
            font-weight: 800; font-size: 14px;
            box-shadow: 0 4px 12px -4px rgba(197,165,90,0.5);
        }
        .docs-section-title h2 {
            font-size: 22px; font-weight: 700; color: #fff;
            letter-spacing: -0.3px;
        }
        .docs-section-desc {
            color: #999; font-size: 14px; margin: 0 0 18px 48px;
            line-height: 1.55;
        }

        .docs-card {
            background: #1a1a1a; border: 1px solid #2a2a2a;
            border-radius: 14px; padding: 24px;
            margin-bottom: 16px;
        }
        .docs-card.feature-card {
            border-left: 4px solid #C5A55A;
            background: linear-gradient(90deg, rgba(197,165,90,0.05), #1a1a1a 60%);
        }

        .mermaid {
            background: #0f0f0f;
            border: 1px solid #242424;
            border-radius: 12px;
            padding: 20px;
            margin: 12px 0;
            text-align: center;
        }

        .docs-grid-3 {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 14px;
        }
        .docs-mini-card {
            background: #1a1a1a; border: 1px solid #2a2a2a; border-radius: 12px;
            padding: 18px;
            transition: all .15s ease;
            position: relative; overflow: hidden;
        }
        .docs-mini-card:hover { border-color: #3a3a3a; transform: translateY(-1px); }
        .docs-mini-card .icon-tile {
            width: 38px; height: 38px;
            border-radius: 9px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 18px;
            margin-bottom: 10px;
        }
        .docs-mini-card h4 {
            color: #fff; font-size: 14px; font-weight: 700; margin-bottom: 4px;
        }
        .docs-mini-card p {
            color: #aaa; font-size: 12.5px; line-height: 1.5; margin: 0;
        }

        .layer-stack {
            display: flex; flex-direction: column; gap: 6px;
        }
        .layer-row {
            display: flex; align-items: center; gap: 12px;
            padding: 14px 18px;
            border-radius: 10px;
            font-size: 13px;
            border: 1px solid rgba(255,255,255,0.06);
        }
        .layer-row .layer-icon {
            width: 36px; height: 36px;
            border-radius: 8px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 17px;
            flex-shrink: 0;
        }
        .layer-row .layer-body { flex: 1; }
        .layer-row .layer-name { font-weight: 700; color: #fff; font-size: 14px; }
        .layer-row .layer-tech { color: #888; font-size: 12px; margin-top: 2px; }
        .layer-row .layer-arrow { color: #555; font-size: 18px; }

        .step-list {
            list-style: none; padding: 0; margin: 0;
            counter-reset: step;
        }
        .step-list li {
            counter-increment: step;
            display: flex; gap: 14px;
            padding: 14px 0;
            border-bottom: 1px solid #232323;
        }
        .step-list li:last-child { border-bottom: none; }
        .step-list li::before {
            content: counter(step);
            min-width: 28px; height: 28px;
            background: linear-gradient(135deg, #C5A55A, #E8D5A3);
            color: #0a0a0a;
            border-radius: 50%;
            display: inline-flex; align-items: center; justify-content: center;
            font-weight: 800; font-size: 13px;
            flex-shrink: 0;
        }
        .step-list li .step-body { flex: 1; }
        .step-list li .step-title {
            color: #fff; font-size: 14px; font-weight: 600; margin-bottom: 3px;
        }
        .step-list li .step-detail {
            color: #aaa; font-size: 13px; line-height: 1.55;
        }
        .step-list li code {
            background: #0a0a0a; color: #C5A55A;
            padding: 1px 7px; border-radius: 4px;
            font-size: 11.5px; font-family: ui-monospace, Menlo, monospace;
        }

        .toc {
            background: linear-gradient(180deg, #1a1a1a, #141414);
            border: 1px solid #2a2a2a;
            border-radius: 12px;
            padding: 18px 22px;
            margin-bottom: 28px;
        }
        .toc h3 {
            color: #C5A55A; font-size: 12px; text-transform: uppercase;
            letter-spacing: 1px; font-weight: 700; margin-bottom: 12px;
        }
        .toc ol {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 6px 16px; padding-left: 18px; margin: 0;
        }
        .toc ol li a {
            color: #ccc; text-decoration: none; font-size: 13px;
            line-height: 1.7; transition: color .12s;
        }
        .toc ol li a:hover { color: #C5A55A; }

        .callout {
            background: linear-gradient(90deg, rgba(197,165,90,0.08), transparent);
            border-left: 3px solid #C5A55A;
            padding: 12px 18px;
            border-radius: 0 8px 8px 0;
            margin: 16px 0;
            font-size: 13px; color: #ddd;
            line-height: 1.6;
        }
        .callout strong { color: #C5A55A; }

        .tech-pill {
            display: inline-block;
            padding: 3px 10px; border-radius: 14px;
            background: rgba(255,255,255,0.06);
            color: #ddd; font-size: 11px; font-weight: 600;
            margin: 2px 3px;
            border: 1px solid rgba(255,255,255,0.08);
        }
        .tech-pill.gold { background: rgba(197,165,90,0.15); color: #E8D5A3; border-color: rgba(197,165,90,0.3); }
        .tech-pill.blue { background: rgba(59,130,246,0.15); color: #93c5fd; border-color: rgba(59,130,246,0.3); }
        .tech-pill.green { background: rgba(34,197,94,0.15); color: #86efac; border-color: rgba(34,197,94,0.3); }
        .tech-pill.purple { background: rgba(168,85,247,0.15); color: #d8b4fe; border-color: rgba(168,85,247,0.3); }
        .tech-pill.red { background: rgba(239,68,68,0.15); color: #fca5a5; border-color: rgba(239,68,68,0.3); }
    </style>

    {{-- ━━━━━━━━━━━━━━━━━━━ HERO ━━━━━━━━━━━━━━━━━━━ --}}
    <div class="docs-hero">
        <h1>FLiK Platform — High-Level Architecture</h1>
        <p class="lead">
            Panduan visual untuk client + tim non-teknis. Jelaskan apa yang terjadi
            di balik layar tiap kali user menonton film, admin upload konten,
            sistem rekomendasi AI berjalan, pembayaran masuk, dan bagaimana
            semuanya saling terhubung. Klik diagram untuk zoom (Mermaid auto-render).
        </p>
        <div class="badges">
            <span>🎬 Streaming OTT</span>
            <span>🤖 30+ AI Features</span>
            <span>🔐 DRM + Watermark</span>
            <span>📱 PWA + Mobile-ready</span>
            <span>🌏 3 Languages (ID/EN/AR)</span>
            <span>💳 Midtrans Integrated</span>
        </div>
    </div>

    {{-- ━━━━━━━━━━━━━━━━━━━ LIVE STATS ━━━━━━━━━━━━━━━━━━━ --}}
    <div class="docs-stats">
        <div class="docs-stat"><div class="num">{{ number_format($stats['movies']) }}</div><div class="label">Movies</div></div>
        <div class="docs-stat"><div class="num">{{ number_format($stats['genres']) }}</div><div class="label">Genres</div></div>
        <div class="docs-stat"><div class="num">{{ number_format($stats['casts']) }}</div><div class="label">Cast</div></div>
        <div class="docs-stat"><div class="num">{{ number_format($stats['users']) }}</div><div class="label">Users</div></div>
        <div class="docs-stat"><div class="num">{{ number_format($stats['subscriptions']) }}</div><div class="label">Subscriptions</div></div>
        <div class="docs-stat"><div class="num">{{ number_format($stats['ai_calls']) }}</div><div class="label">AI Calls</div></div>
        <div class="docs-stat"><div class="num">{{ number_format($stats['comments']) }}</div><div class="label">Comments</div></div>
        <div class="docs-stat"><div class="num">{{ number_format($stats['audit_events']) }}</div><div class="label">Audit Events</div></div>
    </div>

    {{-- ━━━━━━━━━━━━━━━━━━━ TABLE OF CONTENTS ━━━━━━━━━━━━━━━━━━━ --}}
    <div class="toc">
        <h3>📑 Daftar Isi</h3>
        <ol style="list-style:none;padding-left:0">
            <li><a href="#sec-overview"><span class="toc-ico">🌐</span> Overview System</a></li>
            <li><a href="#sec-stack"><span class="toc-ico">🧱</span> Tech Stack 7-Layer</a></li>
            <li><a href="#sec-user-journey"><span class="toc-ico">🚶</span> User Journey</a></li>
            <li><a href="#sec-upload"><span class="toc-ico">🎬</span> Content Upload &amp; Distribution</a></li>
            <li><a href="#sec-drm"><span class="toc-ico">🔐</span> DRM &amp; Playback Flow</a></li>
            <li><a href="#sec-ai"><span class="toc-ico">🤖</span> AI Feature Stack</a></li>
            <li><a href="#sec-payment"><span class="toc-ico">💳</span> Payment Flow</a></li>
            <li><a href="#sec-notif"><span class="toc-ico">🔔</span> Notification System</a></li>
            <li><a href="#sec-security"><span class="toc-ico">🛡️</span> Security &amp; Compliance</a></li>
            <li><a href="#sec-data"><span class="toc-ico">🗄️</span> Data Flow Overview</a></li>
            <li><a href="#sec-tech-why"><span class="toc-ico">⚖️</span> Tech Choices &amp; Why <span style="color:#C5A55A;font-size:11px">(15 tab)</span></a></li>
            <li><a href="#sec-deploy"><span class="toc-ico">🚀</span> Deployment &amp; Ops</a></li>
        </ol>
        <style>
            .toc ol li a { display:flex; align-items:center; gap:10px; padding:6px 0; }
            .toc-ico {
                display:inline-flex; align-items:center; justify-content:center;
                width:28px; height:28px;
                background:rgba(197,165,90,0.08);
                border-radius:7px;
                font-size:14px;
                flex-shrink:0;
            }
        </style>
    </div>

    {{-- ━━━━━━━━━━━━━━━━━━━ §1 OVERVIEW ━━━━━━━━━━━━━━━━━━━ --}}
    <div class="docs-section" id="sec-overview">
        <div class="docs-section-title">
            <span class="num-pill">1</span>
            <h2>Overview — Apa itu FLiK</h2>
        </div>
        <p class="docs-section-desc">
            FLiK adalah platform streaming film OTT kelas premium. Kombinasi dari:
            katalog film terkurasi, AI assistant yang mengerti perfilman, sistem
            rekomendasi personal, pembayaran lokal Indonesia (Midtrans), dan
            keamanan konten kelas-broadcaster (DRM + watermark forensic).
        </p>

        <div class="docs-card">
            <div class="mermaid">
graph TB
    User([👤 User<br/>Browser/PWA/Mobile])
    LB[🌐 Load Balancer<br/>Cloudflare]
    App[⚙️ Laravel App<br/>PHP 8.2]
    DB[(🗄️ MySQL<br/>Primary DB)]
    Cache[(⚡ Redis<br/>Cache + Session)]
    Queue[📬 Queue Workers<br/>FFmpeg / AI / Email]
    CDN[☁️ Bunny CDN<br/>Encrypted HLS]
    AI[🤖 AI Providers<br/>OpenAI / Gemini / Deepseek]
    Pay[💳 Midtrans<br/>Payment Gateway]
    Mail[📧 Email/SMTP]
    Push[🔔 Pusher<br/>Realtime + Web Push]

    User --> LB
    LB --> App
    App <--> DB
    App <--> Cache
    App --> Queue
    Queue --> CDN
    Queue --> AI
    Queue --> Mail
    App --> Pay
    App --> Push
    Push -.->|WebSocket| User
    CDN -.->|Encrypted Stream| User

    style User fill:#C5A55A,stroke:#C5A55A,color:#0a0a0a
    style App fill:#1f2937,stroke:#C5A55A,color:#fff
    style DB fill:#3b82f6,stroke:#3b82f6,color:#fff
    style Cache fill:#ef4444,stroke:#ef4444,color:#fff
    style CDN fill:#22c55e,stroke:#22c55e,color:#fff
    style AI fill:#a855f7,stroke:#a855f7,color:#fff
    style Pay fill:#eab308,stroke:#eab308,color:#0a0a0a
            </div>
        </div>

        <div class="callout">
            <strong>Filosofi arsitektur</strong> — semua heavy work (transcode video,
            AI batch, kirim email) didorong ke <em>queue worker</em> sehingga browser
            user tetap responsif. Hanya request cepat (browse, search, login) yang
            di-handle synchronous oleh Laravel.
        </div>
    </div>

    {{-- ━━━━━━━━━━━━━━━━━━━ §2 TECH STACK 7-LAYER ━━━━━━━━━━━━━━━━━━━ --}}
    <div class="docs-section" id="sec-stack">
        <div class="docs-section-title">
            <span class="num-pill">2</span>
            <h2>Tech Stack — 7 Lapisan</h2>
        </div>
        <p class="docs-section-desc">
            Dari atas (apa yang dilihat user) ke bawah (yang menyimpan data).
            Tiap lapisan independen — bisa di-scale atau diganti tanpa
            mengganggu yang lain.
        </p>

        <div class="docs-card">
            <div class="layer-stack">
                <div class="layer-row" style="background:linear-gradient(90deg,rgba(197,165,90,0.15),rgba(197,165,90,0.05))">
                    <div class="layer-icon" style="background:#C5A55A;color:#0a0a0a">🎨</div>
                    <div class="layer-body">
                        <div class="layer-name">Presentation</div>
                        <div class="layer-tech">Blade · Alpine.js · TailwindCSS · Shaka Player · Mermaid</div>
                    </div>
                </div>
                <div class="layer-row" style="background:linear-gradient(90deg,rgba(232,213,163,0.12),rgba(232,213,163,0.03))">
                    <div class="layer-icon" style="background:#E8D5A3;color:#0a0a0a">📱</div>
                    <div class="layer-body">
                        <div class="layer-name">Client (PWA + Mobile)</div>
                        <div class="layer-tech">Service Worker · Manifest · iOS Splash · Push Notifications · NativePHP Android</div>
                    </div>
                </div>
                <div class="layer-row" style="background:linear-gradient(90deg,rgba(168,85,247,0.12),rgba(168,85,247,0.03))">
                    <div class="layer-icon" style="background:#a855f7;color:#fff">🛡️</div>
                    <div class="layer-body">
                        <div class="layer-name">Edge Security</div>
                        <div class="layer-tech">CSP · HSTS · WAF (RequestFirewall) · CAPTCHA · Honeypot · Rate Limit</div>
                    </div>
                </div>
                <div class="layer-row" style="background:linear-gradient(90deg,rgba(59,130,246,0.12),rgba(59,130,246,0.03))">
                    <div class="layer-icon" style="background:#3b82f6;color:#fff">⚙️</div>
                    <div class="layer-body">
                        <div class="layer-name">Application (Laravel 12)</div>
                        <div class="layer-tech">Routes · Controllers · Livewire · Auth · RBAC · Policies · Audit</div>
                    </div>
                </div>
                <div class="layer-row" style="background:linear-gradient(90deg,rgba(34,197,94,0.12),rgba(34,197,94,0.03))">
                    <div class="layer-icon" style="background:#22c55e;color:#fff">🧠</div>
                    <div class="layer-body">
                        <div class="layer-name">Domain Services</div>
                        <div class="layer-tech">AiClient · DRM · Transcoding · Recommendations · Trending · Notifications · Privacy</div>
                    </div>
                </div>
                <div class="layer-row" style="background:linear-gradient(90deg,rgba(239,68,68,0.12),rgba(239,68,68,0.03))">
                    <div class="layer-icon" style="background:#ef4444;color:#fff">📬</div>
                    <div class="layer-body">
                        <div class="layer-name">Async Workers</div>
                        <div class="layer-tech">4 Queues — default · ai-realtime · ai-batch · transcoding</div>
                    </div>
                </div>
                <div class="layer-row" style="background:linear-gradient(90deg,rgba(234,179,8,0.12),rgba(234,179,8,0.03))">
                    <div class="layer-icon" style="background:#eab308;color:#0a0a0a">🗄️</div>
                    <div class="layer-body">
                        <div class="layer-name">Storage & Data</div>
                        <div class="layer-tech">MySQL · Redis · Bunny CDN · S3 (fallback) · Local Disk</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ━━━━━━━━━━━━━━━━━━━ §3 USER JOURNEY ━━━━━━━━━━━━━━━━━━━ --}}
    <div class="docs-section" id="sec-user-journey">
        <div class="docs-section-title">
            <span class="num-pill">3</span>
            <h2>User Journey — Dari Klik Pertama ke Subscriber</h2>
        </div>
        <p class="docs-section-desc">
            Alur lengkap user dari landing page sampai jadi paying subscriber.
            Setiap touchpoint ditangani oleh service/controller yang berbeda.
        </p>

        <div class="docs-card">
            <div class="mermaid">
journey
    title Perjalanan User Khas
    section Discovery
      Landing page: 5: User
      Browse catalog: 4: User
      Mood discovery quiz: 5: User, AI
      Lihat trending: 5: User
    section Engagement
      Register account: 4: User
      Onboarding quiz: 5: User, AI
      Tambah ke My List: 5: User
      Mulai nonton: 5: User, DRM
    section Conversion
      Lihat plans: 4: User
      Apply promo code: 5: User, Billing
      Bayar via Midtrans: 4: User, Midtrans
      Subscription aktif: 5: User
    section Retention
      Daily streak: 5: User
      AI recommendations: 5: User, AI
      Komentar + reactions: 5: User
      Watch party invite: 5: User, Friends
            </div>
        </div>

        <div class="docs-grid-3" style="margin-top:18px">
            <div class="docs-mini-card">
                <div class="icon-tile" style="background:rgba(197,165,90,0.15);color:#C5A55A">🔍</div>
                <h4>Discovery Tools</h4>
                <p>Smart search, mood quiz, family-night picker, vibe search, image search (upload poster → find similar)</p>
            </div>
            <div class="docs-mini-card">
                <div class="icon-tile" style="background:rgba(59,130,246,0.15);color:#3b82f6">💬</div>
                <h4>Engagement</h4>
                <p>Comments + emoji reactions, ratings, watchlists, daily streak, achievements, leaderboards, trivia quiz</p>
            </div>
            <div class="docs-mini-card">
                <div class="icon-tile" style="background:rgba(168,85,247,0.15);color:#a855f7">👥</div>
                <h4>Social Layer</h4>
                <p>Public profiles, follow system, curated lists, activity feed, sync watch parties via Pusher</p>
            </div>
        </div>
    </div>

    {{-- ━━━━━━━━━━━━━━━━━━━ §4 UPLOAD & DISTRIBUTION ━━━━━━━━━━━━━━━━━━━ --}}
    <div class="docs-section" id="sec-upload">
        <div class="docs-section-title">
            <span class="num-pill">4</span>
            <h2>Content Upload & Distribution — Master Video → User Layar</h2>
        </div>
        <p class="docs-section-desc">
            Inilah yang terjadi setelah admin upload satu file MP4 HD original.
            Tiap step di-handle oleh queue worker terpisah sehingga admin tidak
            menunggu — UI menunjukkan progress bar.
        </p>

        <div class="docs-card feature-card">
            <div class="mermaid">
sequenceDiagram
    autonumber
    participant A as 👤 Admin
    participant W as 🌐 Web
    participant DB as 🗄️ MySQL
    participant Q1 as 🎬 Transcode Worker
    participant FF as ⚙️ FFmpeg
    participant Q2 as 🔐 Encrypt Worker
    participant Q3 as ☁️ Upload Worker
    participant CDN as 🌍 Bunny CDN
    participant U as 👤 User

    A->>W: Upload master.mp4 (HD original)
    W->>DB: Save EncodingJob row + master_file_path
    W-->>A: ✅ Upload selesai. Status: PENDING

    Note over Q1,FF: Transcode ke 4 resolusi (ABR ladder)
    Q1->>FF: ffmpeg -i master.mp4 → 360p/480p/720p/1080p
    FF-->>Q1: 4 file renditions
    Q1->>DB: Update status → TRANSCODED

    Note over Q2: Generate AES-128 content key
    Q2->>DB: Save encrypted key di movies.drm_config
    Q2->>FF: ffmpeg -hls_key_info_file → encrypt segments
    FF-->>Q2: Segments .ts terenkripsi + playlist.m3u8
    Q2->>DB: Update status → ENCRYPTED

    Q3->>CDN: Upload semua segments + manifests
    CDN-->>Q3: ✅ Files live di CDN
    Q3->>DB: Set encoding_status = READY

    Note over U: User klik play
    U->>W: GET /playback/{movie}/config
    W->>DB: Mint JWT + cek concurrent stream limit
    W-->>U: { manifest_url, key_url, jwt }
    U->>CDN: Fetch manifest (signed URL)
    CDN-->>U: m3u8 dengan key URL
    U->>W: GET /drm/key/{jwt}/{keyId}
    W->>DB: Validate JWT + decrypt key
    W-->>U: Raw 16-byte AES key
    U->>CDN: Fetch + decrypt segments → PLAY 🎬
            </div>
        </div>

        <h3 style="font-size:16px;color:#fff;margin:24px 0 14px">Step-by-step (untuk client review):</h3>
        <ol class="step-list">
            <li>
                <div class="step-body">
                    <div class="step-title">Admin upload master HD via <code>/admin/movies/{id}/upload</code></div>
                    <div class="step-detail">File MP4 original (bisa 4K, 30GB+) di-validate magic-byte + virus scan (ClamAV optional). Disimpan di storage private (BUKAN public — user tidak bisa akses langsung). Sebuah <code>EncodingJob</code> row dibuat untuk track progress.</div>
                </div>
            </li>
            <li>
                <div class="step-body">
                    <div class="step-title">Transcoding Worker — bikin 4 versi resolusi (ABR Ladder)</div>
                    <div class="step-detail">FFmpeg encode master jadi 360p, 480p, 720p, 1080p dengan bitrate berbeda. Tujuan: <strong>Adaptive Bitrate</strong> — player otomatis switch resolusi mengikuti kecepatan internet user (4G lambat → 360p, WiFi cepat → 1080p). Output disimpan di local temp.</div>
                </div>
            </li>
            <li>
                <div class="step-body">
                    <div class="step-title">Encryption Worker — generate AES-128 content key + encrypt segments</div>
                    <div class="step-detail"><code>DrmKeyService</code> generate kunci AES-128 acak (16 byte) per movie. Kunci di-encrypt pakai <code>APP_KEY</code> Laravel sebelum disimpan di database. FFmpeg lalu segment + encrypt tiap rendition jadi file .ts kecil-kecil (durasi ~6 detik per segment). File master HD original <strong>tetap aman di storage private</strong> — tidak pernah disentuh user.</div>
                </div>
            </li>
            <li>
                <div class="step-body">
                    <div class="step-title">CDN Upload Worker — push semua encrypted segments ke Bunny CDN</div>
                    <div class="step-detail">Segments terenkripsi + playlist.m3u8 di-push ke Bunny CDN (global edge nodes). Status di-set <code>encoding_status=ready</code>. Admin dapat notifikasi "Movie siap tayang".</div>
                </div>
            </li>
            <li>
                <div class="step-body">
                    <div class="step-title">Playback request — user klik tonton</div>
                    <div class="step-detail">Server cek: user subscribed? GEO allowed? Belum melebihi <code>max_screens</code> dari plan? Kalau OK → mint JWT key token (expire 4 jam) → return manifest URL + key URL ke Shaka Player.</div>
                </div>
            </li>
            <li>
                <div class="step-body">
                    <div class="step-title">Shaka Player fetch manifest + key + decrypt</div>
                    <div class="step-detail">Player request m3u8 dari CDN → dapat list segment URLs (signed, expire 2 jam). Player request kunci dekripsi ke <code>/drm/key/{jwt}/{keyId}</code> → server validate JWT + cek concurrent stream → return raw 16-byte AES key. Player decrypt segment on-the-fly, render ke video element.</div>
                </div>
            </li>
            <li>
                <div class="step-body">
                    <div class="step-title">Heartbeat — refresh concurrent lock</div>
                    <div class="step-detail">Tiap 30 detik player POST ke <code>/playback/heartbeat</code>. Tujuan: <strong>concurrent stream limit</strong>. Plan Family allow 4 device, kalau ada 5 device coba play → device ke-5 ditolak.</div>
                </div>
            </li>
        </ol>

        <div class="callout">
            <strong>Original master file kemana?</strong> Disimpan di <code>storage/app/private/movies/masters/</code> (TIDAK accessible via web).
            Tujuannya: kalau user 1080p tapi besok perlu generate versi 2160p (4K), source masih ada.
            Bisa juga di-archive ke cold storage (S3 Glacier) untuk hemat biaya setelah encoding selesai —
            tinggal flip <code>master_file_disk</code> column.
        </div>
    </div>

    {{-- ━━━━━━━━━━━━━━━━━━━ §5 DRM & PLAYBACK ━━━━━━━━━━━━━━━━━━━ --}}
    <div class="docs-section" id="sec-drm">
        <div class="docs-section-title">
            <span class="num-pill">5</span>
            <h2>DRM & Playback Protection</h2>
        </div>
        <p class="docs-section-desc">
            7 lapisan proteksi konten — sehingga user tidak bisa download lalu upload ulang ke YouTube.
        </p>

        <div class="docs-grid-3">
            <div class="docs-mini-card">
                <div class="icon-tile" style="background:rgba(197,165,90,0.15);color:#C5A55A">🔐</div>
                <h4>AES-128 Encryption</h4>
                <p>Tiap segment .ts terenkripsi. Tanpa kunci, file = data acak. Kunci di-rotate per movie.</p>
            </div>
            <div class="docs-mini-card">
                <div class="icon-tile" style="background:rgba(59,130,246,0.15);color:#3b82f6">🎟️</div>
                <h4>JWT Key Delivery</h4>
                <p>Kunci dekripsi cuma bisa diambil pakai JWT yang valid (signed, expire 4 jam, terikat ke user+session).</p>
            </div>
            <div class="docs-mini-card">
                <div class="icon-tile" style="background:rgba(34,197,94,0.15);color:#22c55e">🌍</div>
                <h4>Geo-blocking</h4>
                <p>Tiap movie punya <code>geo_allow</code> list. User dari country tidak allowed → 451 Unavailable.</p>
            </div>
            <div class="docs-mini-card">
                <div class="icon-tile" style="background:rgba(239,68,68,0.15);color:#ef4444">🔢</div>
                <h4>Concurrent Stream Limit</h4>
                <p>Plan Basic 1 device, Premium 2, Family 4. Redis counter, real-time enforcement.</p>
            </div>
            <div class="docs-mini-card">
                <div class="icon-tile" style="background:rgba(168,85,247,0.15);color:#a855f7">🖋️</div>
                <h4>Forensic Watermark</h4>
                <p>Tiap session embed user_id + token + timestamp di manifest. Kalau video bocor → bisa dilacak siapa yang leak.</p>
            </div>
            <div class="docs-mini-card">
                <div class="icon-tile" style="background:rgba(234,179,8,0.15);color:#eab308">📱</div>
                <h4>Device Fingerprint</h4>
                <p>UA + IP hash → tiap device tercatat. Login dari device baru → email alert ke user.</p>
            </div>
        </div>

        <h3 style="font-size:16px;color:#fff;margin:24px 0 14px">DRM Request Flow:</h3>
        <div class="docs-card">
            <div class="mermaid">
flowchart LR
    A[👤 User Klik Play] --> B{Subscribed?}
    B -->|No| Z1[❌ Redirect /plans]
    B -->|Yes| C{Geo Allowed?}
    C -->|No| Z2[❌ 451 Unavailable]
    C -->|Yes| D{Concurrent &lt; Max?}
    D -->|No| Z3[❌ Stream Limit]
    D -->|Yes| E[🎟️ Mint JWT]
    E --> F[Return Manifest URL + Key URL]
    F --> G[🎬 Shaka Player Fetch Manifest]
    G --> H[🔑 Request Decrypt Key with JWT]
    H --> I{JWT Valid?}
    I -->|No| Z4[❌ 403 Forbidden]
    I -->|Yes| J[Return AES-128 Key]
    J --> K[🎥 Decrypt + Play]
    K --> L[💓 Heartbeat tiap 30s]
    L --> M{Still Authorized?}
    M -->|Yes| K
    M -->|No| Z5[⏸️ Pause + Notify]

    style A fill:#C5A55A,color:#0a0a0a
    style K fill:#22c55e,color:#fff
    style Z1 fill:#ef4444,color:#fff
    style Z2 fill:#ef4444,color:#fff
    style Z3 fill:#ef4444,color:#fff
    style Z4 fill:#ef4444,color:#fff
    style Z5 fill:#eab308,color:#0a0a0a
            </div>
        </div>

        <div class="callout">
            <strong>Catatan tingkat keamanan:</strong> DRM ini = <em>HLS AES-128</em>.
            Mencegah download casual + URL sharing. <strong>Tidak setara Widevine/PlayReady/FairPlay</strong>
            (yang dipakai Netflix/Disney+ untuk konten studio Hollywood). Upgrade ke
            multi-DRM CENC bisa dilakukan tanpa rewrite — tinggal add license server.
        </div>

        {{-- ─── DRM Connection Detail Tech ─── --}}
        <h3 style="font-size:17px;color:#fff;margin:32px 0 14px;display:flex;align-items:center;gap:10px">
            <span style="background:#C5A55A;color:#0a0a0a;width:24px;height:24px;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:800">⚙️</span>
            DRM Connection — Detail Teknis (Step by Step)
        </h3>

        <div class="docs-card">
            <p style="color:#bbb;font-size:13px;margin-bottom:18px;line-height:1.6">
                Untuk client yang ingin tahu <strong>persis</strong> bagaimana setiap komponen tersambung dan teknologi apa yang dipakai:
            </p>

            <ol class="step-list">
                <li>
                    <div class="step-body">
                        <div class="step-title">FFmpeg — Encoder &amp; Encryptor</div>
                        <div class="step-detail">
                            <strong>Apa:</strong> Software open-source standar industri untuk encode video. Dipakai Netflix, YouTube, semua broadcaster.<br>
                            <strong>Cara kita pakai:</strong> Wrapped di service <code>FfmpegTranscoder</code> dan <code>HlsEncryptor</code>. Worker memanggil binary <code>ffmpeg</code> dengan flag <code>-hls_key_info_file</code> untuk encrypt segments menggunakan AES-128.<br>
                            <strong>Output:</strong> Folder berisi 4 sub-folder rendition (360p/480p/720p/1080p), masing-masing punya <code>playlist.m3u8</code> + ratusan file <code>.ts</code> (segments) yang sudah terenkripsi.
                        </div>
                    </div>
                </li>
                <li>
                    <div class="step-body">
                        <div class="step-title">Bunny CDN — Edge Distribution</div>
                        <div class="step-detail">
                            <strong>Apa:</strong> CDN dengan 100+ edge node global. Lebih murah dari CloudFront/Akamai untuk streaming.<br>
                            <strong>Cara connect:</strong> Pakai <code>BunnyStorageService</code> yang implementasi <code>CdnStorageContract</code>. Auth via API key di <code>.env</code>. Worker upload semua file (.m3u8 + .ts) via HTTPS PUT ke storage zone. Bunny lalu serve via pull zone URL.<br>
                            <strong>URL pattern:</strong> <code>https://flik-cdn.b-cdn.net/movies/&#123;slug&#125;/&#123;rendition&#125;/segment_001.ts</code><br>
                            <strong>Security:</strong> Pakai <strong>Token Authentication</strong> Bunny — tiap segment URL ditandatangani dengan secret key + expiry timestamp. URL bocor → cuma valid 2 jam.
                        </div>
                    </div>
                </li>
                <li>
                    <div class="step-body">
                        <div class="step-title">DrmKeyService — Generate AES-128 Key</div>
                        <div class="step-detail">
                            <strong>Apa:</strong> Service PHP yang generate dan store kunci enkripsi.<br>
                            <strong>Cara kerja:</strong> Saat encryption worker jalan untuk movie X:
                            <ol style="margin:8px 0 0 16px;color:#aaa">
                                <li><code>random_bytes(16)</code> → generate 16 byte acak (= 128 bit)</li>
                                <li>Encrypt dengan <code>Crypt::encryptString($key)</code> pakai <code>APP_KEY</code> Laravel</li>
                                <li>Simpan encrypted blob di <code>movies.drm_config-&gt;content_key_encrypted</code></li>
                                <li>Tulis raw key sementara ke file <code>.keyinfo</code> yang FFmpeg butuh untuk encrypt segments</li>
                                <li>Delete file <code>.keyinfo</code> setelah encryption selesai</li>
                            </ol>
                            <strong>Key rotation:</strong> Admin bisa trigger <code>DrmKeyService::rotateKey($movie)</code> → semua session aktif jadi invalid, segments harus di-re-encrypt.
                        </div>
                    </div>
                </li>
                <li>
                    <div class="step-body">
                        <div class="step-title">DrmTokenService — Mint &amp; Validate JWT</div>
                        <div class="step-detail">
                            <strong>Apa:</strong> Service yang issue JWT token untuk akses kunci. Pakai library <code>firebase/php-jwt</code> (fallback ke HMAC manual kalau library tidak ada).<br>
                            <strong>Cara mint:</strong> Saat user klik play, controller call <code>DrmTokenService::issueForSession($user, $movie)</code>:
                            <pre style="background:#0a0a0a;padding:12px;border-radius:6px;color:#C5A55A;font-size:12px;margin-top:6px;overflow-x:auto">{
  "iss": "flik",
  "aud": "drm-key-endpoint",
  "sub": user_id,
  "movie_id": 123,
  "session_id": "uuid-v4",
  "kid": "key-id-of-movie",
  "device_fp": "sha256-of-ua-and-ip",
  "iat": 1716220800,
  "exp": 1716235200  // 4 jam dari sekarang
}</pre>
                            <strong>Validate:</strong> Saat Shaka Player request <code>/drm/key/{jwt}/{keyId}</code>, controller decode JWT, verify signature pakai secret + cek expiry + cek subject_id match user yang login + cek device fingerprint match.
                        </div>
                    </div>
                </li>
                <li>
                    <div class="step-body">
                        <div class="step-title">Shaka Player — Client-side Decryption</div>
                        <div class="step-detail">
                            <strong>Apa:</strong> Open-source video player dari Google, support HLS + DASH + DRM. Saingan dari video.js.<br>
                            <strong>Cara load:</strong> Loaded via CDN <code>jsdelivr.net/npm/shaka-player@4.7.11</code> di layout. Wrapped di <code>resources/js/player/flik-player.js</code> sebagai class <code>FlikPlayer</code>.<br>
                            <strong>Init flow:</strong>
                            <ol style="margin:8px 0 0 16px;color:#aaa">
                                <li>User klik play → fetch <code>/playback/{movie}/config</code> → response berisi <code>{manifest_url, key_url_template}</code></li>
                                <li>Shaka call <code>player.load(manifest_url)</code></li>
                                <li>Shaka parse m3u8 manifest dari Bunny → lihat ada <code>#EXT-X-KEY</code> directive dengan URI</li>
                                <li>Shaka request kunci ke URI tersebut (<code>/drm/key/{jwt}/{keyId}</code>)</li>
                                <li>Kunci diterima sebagai response body 16-byte binary</li>
                                <li>Shaka cache kunci di memory, decrypt tiap segment yang diterima dari Bunny</li>
                                <li>Decrypted segments di-feed ke <code>&lt;video&gt;</code> element → user lihat film</li>
                            </ol>
                        </div>
                    </div>
                </li>
                <li>
                    <div class="step-body">
                        <div class="step-title">ConcurrentStreamLimiter — Redis-based</div>
                        <div class="step-detail">
                            <strong>Apa:</strong> Cegah account sharing — enforce max concurrent device per plan (Basic 1, Premium 2, Family 4).<br>
                            <strong>Cara kerja:</strong> Pakai Redis counter dengan TTL.
                            <ol style="margin:8px 0 0 16px;color:#aaa">
                                <li>Saat play start: <code>INCR concurrent:user:{id}</code> dengan <code>EXPIRE 120</code> (2 menit auto-cleanup kalau heartbeat berhenti)</li>
                                <li>Heartbeat tiap 30s dari player → refresh TTL ke 120s lagi</li>
                                <li>Kalau counter &gt; max_screens dari plan → 403 Stream Limit Exceeded → player kasih notice "Sudah login di 4 device"</li>
                                <li>User stop video / tutup tab → 2 menit kemudian counter expire otomatis → device slot kembali available</li>
                            </ol>
                            <strong>Storage:</strong> Pakai Redis kalau dikonfigurasi, fallback ke Laravel <code>Cache::increment</code> (database driver) kalau tidak.
                        </div>
                    </div>
                </li>
                <li>
                    <div class="step-body">
                        <div class="step-title">GeoBlock — MaxMind GeoLite2 Database</div>
                        <div class="step-detail">
                            <strong>Apa:</strong> Lookup country dari IP address tanpa request ke API eksternal.<br>
                            <strong>Cara connect:</strong> Library <code>geoip2/geoip2</code> baca database file <code>storage/app/geoip/GeoLite2-Country.mmdb</code> (gratis dari MaxMind, update mingguan).<br>
                            <strong>Cara kerja:</strong> Middleware <code>GeoBlock</code> dipasang di route playback. Untuk tiap request: extract IP → lookup country code → cek <code>movies.geo_allow</code> JSON column → kalau country tidak in list → return HTTP 451 (Unavailable for Legal Reasons).<br>
                            <strong>Update DB:</strong> Command <code>flik:geo:update</code> dijadwalkan mingguan untuk auto-download MMDB terbaru dari MaxMind.
                        </div>
                    </div>
                </li>
                <li>
                    <div class="step-body">
                        <div class="step-title">ForensicWatermarker — Per-session Tracking</div>
                        <div class="step-detail">
                            <strong>Apa:</strong> Embed user identifier ke manifest sehingga kalau video bocor di forum bajakan, bisa dilacak siapa yang leak.<br>
                            <strong>Cara kerja:</strong> Saat <code>PlaybackManifestGenerator</code> generate m3u8 per session, inject directive:
                            <pre style="background:#0a0a0a;padding:12px;border-radius:6px;color:#C5A55A;font-size:12px;margin-top:6px;overflow-x:auto">#EXT-X-SESSION-DATA:DATA-ID="com.flik.forensic",
  VALUE="u123|sABCD1234|t1716220800"</pre>
                            Tag ini ter-record di every cached copy dari manifest. Plus tertulis ke log channel <code>drm</code> untuk audit. Kalau ada video leak ditemukan, parse VALUE field → dapat user_id, session token, timestamp → bisa lock account + investigate.<br>
                            <strong>Catatan:</strong> Ini watermark di <em>metadata</em>, bukan di <em>pixel video</em>. Untuk full per-user pixel watermark butuh per-session re-transcode (biaya tinggi).
                        </div>
                    </div>
                </li>
                <li>
                    <div class="step-body">
                        <div class="step-title">Connecting All — End-to-End Network Diagram</div>
                        <div class="step-detail">
                            Diagram di atas (sequence chart playback) menggambarkan urutan HTTP request konkret. Yang penting dipahami client:
                            <ul style="margin:8px 0 0 16px;color:#aaa">
                                <li><strong>Video data</strong> (segments) selalu dari <strong>CDN</strong> (Bunny) — biar fast + scalable</li>
                                <li><strong>Decrypt key</strong> selalu dari <strong>FLiK server</strong> — biar bisa control siapa yang boleh dapat key</li>
                                <li><strong>Player decrypt di browser</strong> — server tidak pernah kirim decrypted video, jadi bandwidth FLiK tetap rendah</li>
                                <li>Kalau attacker capture key dari memory browser → masih harus capture juga 4 rendition × ratusan segment .ts untuk reconstruct full video → effort tinggi vs pakai screen recorder biasa</li>
                            </ul>
                        </div>
                    </div>
                </li>
            </ol>
        </div>

        <h3 style="font-size:16px;color:#fff;margin:24px 0 14px">Teknologi Stack DRM (ringkasan):</h3>
        <div style="display:flex;flex-wrap:wrap;gap:6px">
            <span class="tech-pill gold">FFmpeg 6.x (encoder)</span>
            <span class="tech-pill gold">AES-128 CBC (cipher)</span>
            <span class="tech-pill blue">JWT (firebase/php-jwt)</span>
            <span class="tech-pill blue">HLS playlist v3</span>
            <span class="tech-pill green">Shaka Player 4.7.11 (frontend)</span>
            <span class="tech-pill green">MediaSource Extensions API (browser)</span>
            <span class="tech-pill purple">Bunny CDN (storage + delivery)</span>
            <span class="tech-pill purple">Token Authentication (signed URL)</span>
            <span class="tech-pill red">Redis (concurrent counter)</span>
            <span class="tech-pill red">MaxMind GeoLite2 (geo lookup)</span>
            <span class="tech-pill">Laravel Crypt (key at rest)</span>
        </div>
    </div>

    {{-- ━━━━━━━━━━━━━━━━━━━ §6 AI FEATURE STACK ━━━━━━━━━━━━━━━━━━━ --}}
    <div class="docs-section" id="sec-ai">
        <div class="docs-section-title">
            <span class="num-pill">6</span>
            <h2>AI Feature Stack — 30+ Fitur AI</h2>
        </div>
        <p class="docs-section-desc">
            Semua fitur AI berkonsumsi 1 service: <code>AiClient</code>. Provider
            (OpenAI/Gemini/Deepseek/Claude/Groq) bisa di-switch dari admin tanpa
            re-deploy. Tiap call ter-track di <code>ai_usage_logs</code> sehingga
            admin bisa pantau biaya per fitur.
        </p>

        <div class="docs-card">
            <div class="mermaid">
graph TB
    subgraph User-facing
        Chat[💬 Chatbot RAG]
        Mood[🎭 Mood Discovery]
        PlotEx[📖 Plot Explain]
        Compare[⚖️ Movie Comparator]
        YearReview[📅 Year-in-Review]
        Family[👨‍👩‍👧 Family Night]
        ImgSearch[🖼️ Image Search]
        Vibe[✨ Vibe Search]
        Trivia[🎯 Trivia Quiz]
    end

    subgraph Content-enrichment
        Tagger[🏷️ Auto-tag]
        Synop[📝 Synopsis Gen]
        SEO[🔗 SEO Meta]
        Thumbs[🖼️ Thumbnail Pick]
        Quotes[💭 Quote Extract]
        Reviews[⭐ AI Reviews 4-perspectives]
        BTS[🎬 Behind-Scenes]
        Highlight[✦ Highlight Reel]
        Cinema[🎨 Cinematography]
        Sound[🎵 Soundtrack]
    end

    subgraph Ops
        Sentiment[💚 Sentiment]
        Moderate[🛡️ Comment Mod]
        Spoiler[⚠️ Spoiler Detect]
        Churn[📉 Churn Predict]
        Gap[🔍 Content Gap]
        Pricing[💲 Pricing Optimize]
        CSReply[💬 CS Reply Draft]
        EmailAB[📧 Email A/B]
        TikTok[🎵 TikTok Clips]
    end

    Chat --> AC[⚙️ AiClient]
    Mood --> AC
    PlotEx --> AC
    Compare --> AC
    YearReview --> AC
    Family --> AC
    ImgSearch --> AC
    Vibe --> AC
    Trivia --> AC
    Tagger --> AC
    Synop --> AC
    SEO --> AC
    Thumbs --> AC
    Quotes --> AC
    Reviews --> AC
    BTS --> AC
    Highlight --> AC
    Cinema --> AC
    Sound --> AC
    Sentiment --> AC
    Moderate --> AC
    Spoiler --> AC
    Churn --> AC
    Gap --> AC
    Pricing --> AC
    CSReply --> AC
    EmailAB --> AC
    TikTok --> AC

    AC --> Track[📊 UsageTracker<br/>cost, tokens, latency]
    AC --> SSRF[🛡️ SsrfGuard]
    AC --> Switch{Active Provider}
    Switch --> OpenAI[OpenAI]
    Switch --> Gemini[Gemini]
    Switch --> Deepseek[Deepseek]
    Switch --> Claude[Claude]
    Switch --> Groq[Groq]
    Switch --> OpenRouter[OpenRouter]

    style AC fill:#C5A55A,color:#0a0a0a,stroke:#C5A55A
    style Track fill:#3b82f6,color:#fff
    style SSRF fill:#ef4444,color:#fff
            </div>
        </div>

        <div class="callout">
            <strong>Cost transparency:</strong> Tiap AI call tercatat di <code>/admin/ai-usage</code> — admin lihat
            biaya per task type (cost per chat, per recommendation, per moderation), 14-day sparkline,
            dan provider mana yang paling boros token.
        </div>
    </div>

    {{-- ━━━━━━━━━━━━━━━━━━━ §7 PAYMENT FLOW ━━━━━━━━━━━━━━━━━━━ --}}
    <div class="docs-section" id="sec-payment">
        <div class="docs-section-title">
            <span class="num-pill">7</span>
            <h2>Payment Flow — Midtrans + Promo + Gift + Referral</h2>
        </div>
        <p class="docs-section-desc">
            Semua pembayaran via Midtrans Snap (cash, bank transfer, e-wallet, kartu kredit Indonesia).
            Promo code, gift subscription, dan refer-a-friend reward integrated ke checkout.
        </p>

        <div class="docs-card">
            <div class="mermaid">
sequenceDiagram
    participant U as 👤 User
    participant W as 🌐 FLiK
    participant MT as 💳 Midtrans
    participant DB as 🗄️ DB

    U->>W: GET /plans
    W-->>U: List plan (Free/Basic/Premium/Family)
    U->>W: POST /checkout/{plan} + promo_code (optional)
    W->>W: Validate promo (PromoCodeService::validateCode)
    alt Valid promo
        W->>W: computeDiscount() → harga turun
    end
    W->>MT: Request Snap token (amount, order_id)
    MT-->>W: snap_token
    W-->>U: Render Snap embed page
    U->>MT: Bayar di popup Midtrans
    MT-->>U: Success/Pending/Fail
    MT->>W: Webhook POST /payment/webhook (HMAC signed)
    W->>W: Verify signature
    alt Settlement
        W->>DB: Subscription status = active
        W->>W: Apply promo redemption (idempotent)
        W->>W: Trigger referral reward (kalau ada)
        W->>W: AdminNotifier::notify (finance role)
        W->>U: Email confirmation
    else Failed
        W->>DB: Subscription status = failed
    end
            </div>
        </div>

        <div class="docs-grid-3">
            <div class="docs-mini-card">
                <div class="icon-tile" style="background:rgba(197,165,90,0.15);color:#C5A55A">🎟️</div>
                <h4>Promo Codes</h4>
                <p>Percentage / fixed / free-trial. Plan-scoped, max uses, first-time-only flag. Sample seeded: WELCOME10, LAUNCH2026, STUDENT.</p>
            </div>
            <div class="docs-mini-card">
                <div class="icon-tile" style="background:rgba(168,85,247,0.15);color:#a855f7">🎁</div>
                <h4>Gift Subscriptions</h4>
                <p>Beli sebagai gift → recipient dapat email dengan redemption code → redeem activate subscription mereka.</p>
            </div>
            <div class="docs-mini-card">
                <div class="icon-tile" style="background:rgba(34,197,94,0.15);color:#22c55e">🤝</div>
                <h4>Refer-a-Friend</h4>
                <p>Tiap user dapat referral code unik. Friend signup pakai code → both dapat reward (XP + coins) saat friend bayar pertama.</p>
            </div>
        </div>
    </div>

    {{-- ━━━━━━━━━━━━━━━━━━━ §8 NOTIFICATION SYSTEM ━━━━━━━━━━━━━━━━━━━ --}}
    <div class="docs-section" id="sec-notif">
        <div class="docs-section-title">
            <span class="num-pill">8</span>
            <h2>Notification System — 4 Channel</h2>
        </div>
        <p class="docs-section-desc">
            User dan admin dapat informasi via 4 channel berbeda — tiap channel
            optimized untuk konteks yang berbeda.
        </p>

        <div class="docs-card">
            <div class="mermaid">
graph LR
    Event[📡 App Event<br/>comment, payment, security, etc] --> Router{Router}
    Router --> InApp[🔔 In-App<br/>Bell icon]
    Router --> Email[📧 Email<br/>Mailchimp / SMTP]
    Router --> Push[📱 Web Push<br/>VAPID]
    Router --> Realtime[⚡ Pusher<br/>WebSocket]

    InApp --> AdminBell[Admin Bell<br/>realtime + polling]
    InApp --> UserNotif[User /notifications<br/>page]

    Email --> Campaign[📣 Campaign Builder<br/>segment + AI copy]
    Email --> Trans[Transactional<br/>verify, reset, gift]
    Email --> Digest[📊 Daily Digest<br/>super_admins]

    Push --> NewRelease[New release alert]
    Push --> WatchParty[Party invite]

    Realtime --> Bell[Bell badge update]
    Realtime --> WPSync[Watch party sync]

    style InApp fill:#C5A55A,color:#0a0a0a
    style Email fill:#3b82f6,color:#fff
    style Push fill:#a855f7,color:#fff
    style Realtime fill:#22c55e,color:#fff
            </div>
        </div>
    </div>

    {{-- ━━━━━━━━━━━━━━━━━━━ §9 SECURITY & COMPLIANCE ━━━━━━━━━━━━━━━━━━━ --}}
    <div class="docs-section" id="sec-security">
        <div class="docs-section-title">
            <span class="num-pill">9</span>
            <h2>Security & Compliance</h2>
        </div>
        <p class="docs-section-desc">
            Multi-lapis defense — dari edge (block payload jahat sebelum sampai aplikasi)
            sampai aplikasi (RBAC, audit log) sampai data (encryption at rest).
        </p>

        <div class="docs-card">
            <div class="mermaid">
graph TD
    Req[🌐 Incoming Request] --> CDN1[☁️ Cloudflare DDoS]
    CDN1 --> HTTPS[🔒 Force HTTPS + HSTS]
    HTTPS --> Headers[🛡️ Security Headers<br/>CSP, COOP, Permissions-Policy]
    Headers --> Geo[🌍 GeoBlock]
    Geo --> WAF[🚧 WAF RequestFirewall<br/>SQLi, XSS, traversal patterns]
    WAF --> RL[⏱️ Rate Limit<br/>10 named limiters]
    RL --> Honey[🍯 Honeypot bot trap]
    Honey --> CAPTCHA[🤖 Cloudflare Turnstile]
    CAPTCHA --> Auth[🔑 Auth + 2FA]
    Auth --> RBAC[🎭 RBAC Gates<br/>6 roles, 41 permissions]
    RBAC --> Audit[📋 Audit Log<br/>30+ security events]
    Audit --> App[✅ App Controller]

    style Req fill:#3b82f6,color:#fff
    style App fill:#22c55e,color:#fff
    style WAF fill:#ef4444,color:#fff
    style Auth fill:#C5A55A,color:#0a0a0a
            </div>
        </div>

        <div class="docs-grid-3" style="margin-top:18px">
            <div class="docs-mini-card">
                <div class="icon-tile" style="background:rgba(59,130,246,0.15);color:#3b82f6">📜</div>
                <h4>GDPR & UU PDP</h4>
                <p>Data export (right to access), account deletion (right to be forgotten), cookie consent 3 kategori, PII encryption at rest.</p>
            </div>
            <div class="docs-mini-card">
                <div class="icon-tile" style="background:rgba(34,197,94,0.15);color:#22c55e">🔍</div>
                <h4>Audit Trail</h4>
                <p>Setiap admin action + security event ditulis ke <code>audit_logs</code>. Searchable, exportable CSV.</p>
            </div>
            <div class="docs-mini-card">
                <div class="icon-tile" style="background:rgba(168,85,247,0.15);color:#a855f7">🚨</div>
                <h4>Realtime Alerts</h4>
                <p>Critical security event (priv escalation, brute force, geo anomaly) → Slack/Discord webhook.</p>
            </div>
        </div>
    </div>

    {{-- ━━━━━━━━━━━━━━━━━━━ §10 DATA FLOW ━━━━━━━━━━━━━━━━━━━ --}}
    <div class="docs-section" id="sec-data">
        <div class="docs-section-title">
            <span class="num-pill">10</span>
            <h2>Data Flow Overview</h2>
        </div>
        <p class="docs-section-desc">
            70+ database tables organized by concern. Berikut alur data utama.
        </p>

        <div class="docs-card">
            <div class="mermaid">
erDiagram
    USER ||--o{ SUBSCRIPTION : has
    USER ||--o{ WATCH_HISTORY : watches
    USER ||--o{ COMMENT : posts
    USER ||--o{ RATING : gives
    USER ||--o{ WATCHLIST : saves
    USER ||--o{ USER_LIST : curates
    USER ||--o{ AI_CHAT_SESSION : converses
    USER ||--o{ ROLE_USER : assigned
    USER ||--|| WATCH_STREAK : has

    MOVIE ||--o{ SEASON : "TV series"
    SEASON ||--o{ EPISODE : contains
    MOVIE ||--o{ MOVIE_SUBTITLE : has
    MOVIE ||--o{ COMMENT : "discussed in"
    MOVIE ||--o{ MOVIE_VIEW : tracked
    MOVIE }o--o{ GENRE : tagged
    MOVIE }o--o{ CAST : "stars in"

    ROLE }o--o{ PERMISSION : grants
    ROLE_USER }o--|| ROLE : "is"

    SUBSCRIPTION }o--|| SUBSCRIPTION_PLAN : "subscribes to"

    AI_CHAT_SESSION ||--o{ AI_CHAT_MESSAGE : contains
            </div>
        </div>

        <div class="docs-grid-3">
            <div class="docs-mini-card">
                <div class="icon-tile" style="background:rgba(197,165,90,0.15);color:#C5A55A">🎬</div>
                <h4>Content (15 table)</h4>
                <p>movies, seasons, episodes, genres, casts, banners, subtitles, encoding_jobs, drm_sessions, dll.</p>
            </div>
            <div class="docs-mini-card">
                <div class="icon-tile" style="background:rgba(59,130,246,0.15);color:#3b82f6">👥</div>
                <h4>User & Auth (12 table)</h4>
                <p>users, sessions, login_attempts, known_devices, roles, permissions, role_user, follows, user_lists.</p>
            </div>
            <div class="docs-mini-card">
                <div class="icon-tile" style="background:rgba(168,85,247,0.15);color:#a855f7">🧠</div>
                <h4>AI & Analytics (20+ table)</h4>
                <p>ai_providers, ai_usage_logs, recommendations, churn_predictions, ab_experiments, trending_movies, dll.</p>
            </div>
        </div>
    </div>

    {{-- ━━━━━━━━━━━━━━━━━━━ TECH COMPARISON & WHY ━━━━━━━━━━━━━━━━━━━ --}}
    <div class="docs-section" id="sec-tech-why">
        <div class="docs-section-title">
            <span class="num-pill" style="background:linear-gradient(135deg,#a855f7,#d8b4fe);color:#0a0a0a">⚖</span>
            <h2>Tech Choices &amp; Why — Perbandingan Tiap Komponen</h2>
        </div>
        <p class="docs-section-desc">
            Tiap pilihan teknologi punya trade-off. Section ini menjelaskan
            <strong>kenapa kita pilih A</strong> dibanding alternatif lain,
            kelebihan + kekurangan, dan kapan harus upgrade. Untuk client meeting:
            scroll ke tab yang relevan saat ada pertanyaan "kenapa gak pakai X?"
        </p>

        {{-- Live dynamic-switch banner --}}
        <div style="background:linear-gradient(135deg,rgba(34,197,94,0.12),rgba(34,197,94,0.04));border:1px solid rgba(34,197,94,0.35);border-radius:12px;padding:16px 20px;margin-bottom:18px;display:flex;align-items:center;gap:14px">
            <div style="flex-shrink:0;width:40px;height:40px;background:linear-gradient(135deg,#22c55e,#86efac);border-radius:10px;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 12px -4px rgba(34,197,94,0.5)">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#0a0a0a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
            </div>
            <div style="flex:1">
                <div style="color:#86efac;font-weight:700;font-size:13.5px;letter-spacing:-0.2px">✓ Provider berikut sekarang BISA DIGANTI dari admin tanpa redeploy</div>
                <div style="color:#bbb;font-size:12.5px;line-height:1.55;margin-top:4px">
                    Tab <strong>CDN, DRM, Storage, Realtime, Payment, Email</strong> + <strong>Integrations</strong> — semua provider di dropdown bisa di-switch live.
                    Setting disimpan di <code style="background:#0a0a0a;padding:2px 6px;border-radius:4px;color:#86efac;font-size:11px">settings</code> table, kemudian
                    <code style="background:#0a0a0a;padding:2px 6px;border-radius:4px;color:#86efac;font-size:11px">DynamicInfrastructureProvider</code> override
                    config() saat request berikutnya. Tidak perlu edit .env, tidak perlu restart worker.
                </div>
                <a href="{{ route('admin.infrastructure.index') }}"
                   style="display:inline-block;margin-top:8px;font-size:12px;color:#22c55e;font-weight:700;text-decoration:none">
                    → Buka /admin/infrastructure untuk switch sekarang
                </a>
            </div>
        </div>

        <div class="docs-card" x-data="{ tab: 'cdn' }" style="padding:0;overflow:hidden">

            {{-- Tab strip --}}
            <div style="display:flex;overflow-x:auto;border-bottom:1px solid #2a2a2a;background:#141414;scrollbar-width:thin">
                @php
                    $tabs = [
                        'cdn' => '☁️ CDN',
                        'drm' => '🔐 DRM',
                        'db' => '🗄️ Database',
                        'cache' => '⚡ Cache',
                        'queue' => '📬 Queue',
                        'ai' => '🤖 AI',
                        'payment' => '💳 Payment',
                        'realtime' => '📡 Realtime',
                        'search' => '🔍 Search',
                        'email' => '📧 Email',
                        'auth' => '🔑 Auth',
                        'framework' => '⚙️ Framework',
                        'frontend' => '🎨 Frontend',
                        'mobile' => '📱 Mobile',
                        'monitoring' => '📊 Monitoring',
                    ];
                @endphp
                @foreach($tabs as $key => $label)
                    <button @click="tab = '{{ $key }}'"
                            :class="tab === '{{ $key }}' ? 'tech-tab is-active' : 'tech-tab'"
                            type="button">{{ $label }}</button>
                @endforeach
            </div>

            <div style="padding:24px">

            {{-- ─── CDN ─── --}}
            <div x-show="tab === 'cdn'" x-cloak>
                <h3 class="why-h3"><span class="why-pick">Pilihan kita: Bunny CDN</span></h3>
                <p class="why-reason">CDN murah tier-2 dengan 100+ edge node global. Khusus optimal untuk streaming HLS (segment .ts kecil). 5-10× lebih murah dari CloudFront untuk traffic volume sama.</p>

                <div class="why-grid">
                    <div class="why-pros">
                        <h4>✅ Kelebihan</h4>
                        <ul>
                            <li>$0.01/GB egress (CloudFront $0.085, ~8× lebih mahal)</li>
                            <li>Built-in HLS optimization + Token Authentication signed URL</li>
                            <li>API simple, single SDK call untuk upload directory</li>
                            <li>Edge node Asia Tenggara bagus (Singapore + Jakarta)</li>
                            <li>Free TLS, free storage zone, no hidden cost</li>
                        </ul>
                    </div>
                    <div class="why-cons">
                        <h4>❌ Kekurangan</h4>
                        <ul>
                            <li>Tidak punya WAF + DDoS protection sekuat Cloudflare</li>
                            <li>Support standard (bukan dedicated TAM)</li>
                            <li>Statistik dashboard lebih basic dari Akamai/CloudFront</li>
                            <li>Tidak ada Lambda@Edge / serverless compute</li>
                        </ul>
                    </div>
                </div>

                <h4 class="why-compare-h">Perbandingan vendor:</h4>
                <table class="why-table">
                    <thead><tr><th>Vendor</th><th>Harga /GB</th><th>Edge</th><th>HLS-optimized</th><th>Best for</th></tr></thead>
                    <tbody>
                        <tr style="background:rgba(197,165,90,0.06)"><td><strong style="color:#C5A55A">Bunny CDN ★</strong></td><td>$0.01</td><td>119</td><td>✅</td><td>Streaming yang butuh murah + fast</td></tr>
                        <tr><td>Cloudflare R2 + CDN</td><td>$0.015</td><td>300+</td><td>⚠️ Basic</td><td>Static asset + page</td></tr>
                        <tr><td>AWS CloudFront</td><td>$0.085</td><td>450+</td><td>✅</td><td>Enterprise dengan budget</td></tr>
                        <tr><td>AWS S3 standalone</td><td>$0.09</td><td>—</td><td>❌</td><td>Origin only, bukan delivery</td></tr>
                        <tr><td>Akamai</td><td>Custom $$$</td><td>4000+</td><td>✅</td><td>MPAA Tier 1 broadcaster</td></tr>
                        <tr><td>Fastly</td><td>$0.12</td><td>80+</td><td>✅</td><td>Real-time edge logic</td></tr>
                        <tr><td>Self-host (nginx + Origin Pull)</td><td>Bandwidth ISP</td><td>1</td><td>✅</td><td>Skala kecil, full control</td></tr>
                    </tbody>
                </table>

                <div class="callout">
                    <strong>Kapan migrate ke CloudFront/Akamai?</strong> Kalau (a) konten dapat lisensi studio Hollywood yang mensyaratkan DDoS-protected CDN dengan SLA 99.99%, atau (b) traffic &gt; 500TB/bulan (volume discount Akamai mulai menarik).
                </div>

                <h4 class="why-compare-h" style="margin-top:28px">📋 Detail per Provider — Pilih yang cocok untuk kasus kamu</h4>
                <div class="provider-grid">
                    {{-- Bunny --}}
                    <div class="provider-card" style="border-color:#C5A55A">
                        <div class="provider-header"><span class="provider-name">Bunny CDN</span><span class="provider-tag" style="background:#C5A55A;color:#0a0a0a">DEFAULT</span></div>
                        <div class="provider-fact">$0.01/GB · 119 edge · HLS-optimized</div>
                        <div class="prov-mini">✅ Termurah untuk streaming · ✅ HLS Token Auth signed URL built-in · ✅ Edge SG+Jakarta dekat · ✅ Free TLS + storage</div>
                        <div class="prov-mini neg">❌ WAF/DDoS lebih lemah · ❌ Support standard saja · ❌ No Lambda@Edge</div>
                        <div class="provider-when">Pakai saat: startup-mid, traffic &lt; 500TB/mo, butuh murah</div>
                    </div>

                    {{-- Cloudflare R2 --}}
                    <div class="provider-card">
                        <div class="provider-header"><span class="provider-name">Cloudflare R2</span><span class="provider-tag" style="background:rgba(59,130,246,0.18);color:#93c5fd">Hybrid</span></div>
                        <div class="provider-fact">$0.015/GB · 300+ edge · zero egress fee</div>
                        <div class="prov-mini">✅ Zero egress cost (revolution) · ✅ Cloudflare WAF terbaik · ✅ Workers AI integration · ✅ S3-compatible API</div>
                        <div class="prov-mini neg">❌ R2 + CDN integration butuh setup ekstra · ❌ Pull zone behavior beda dari Bunny · ❌ Region selection terbatas</div>
                        <div class="provider-when">Pakai saat: traffic spike unpredictable, sudah pakai Cloudflare</div>
                    </div>

                    {{-- AWS CloudFront --}}
                    <div class="provider-card">
                        <div class="provider-header"><span class="provider-name">AWS S3 + CloudFront</span><span class="provider-tag" style="background:rgba(234,179,8,0.18);color:#fde68a">Enterprise</span></div>
                        <div class="provider-fact">$0.085/GB · 450+ edge · Lambda@Edge support</div>
                        <div class="prov-mini">✅ Edge terbanyak globally · ✅ Integrasi penuh dengan AWS services · ✅ Lambda@Edge untuk edge compute · ✅ Field-level encryption</div>
                        <div class="prov-mini neg">❌ 8× lebih mahal dari Bunny · ❌ Pricing complex (multi-axis) · ❌ Setup signed URL lebih ribet</div>
                        <div class="provider-when">Pakai saat: sudah AWS-heavy, butuh edge compute, budget enterprise</div>
                    </div>

                    {{-- Akamai --}}
                    <div class="provider-card">
                        <div class="provider-header"><span class="provider-name">Akamai</span><span class="provider-tag" style="background:rgba(239,68,68,0.18);color:#fca5a5">Studio-grade</span></div>
                        <div class="provider-fact">Custom $$$ · 4000+ edge · MPAA Tier-1 compliant</div>
                        <div class="prov-mini">✅ Standar broadcaster Hollywood · ✅ Edge node terbanyak · ✅ Dedicated TAM (technical account manager) · ✅ Bisa pasangkan dengan Widevine</div>
                        <div class="prov-mini neg">❌ Mahal — minimum commitment $5K+/mo · ❌ Contract negotiation lama · ❌ Overkill untuk startup</div>
                        <div class="provider-when">Pakai saat: dapat licensing Disney/Warner/Universal, traffic 1PB+/mo</div>
                    </div>

                    {{-- DO Spaces --}}
                    <div class="provider-card">
                        <div class="provider-header"><span class="provider-name">DigitalOcean Spaces</span><span class="provider-tag" style="background:rgba(168,85,247,0.18);color:#d8b4fe">Simple</span></div>
                        <div class="provider-fact">$5/mo flat 250GB + 1TB transfer included</div>
                        <div class="prov-mini">✅ Flat pricing, predictable bill · ✅ S3-compatible · ✅ Edge node SGP1 dekat Indonesia · ✅ Dashboard sederhana</div>
                        <div class="prov-mini neg">❌ Edge cuma 13 lokasi · ❌ Tidak HLS-optimized seperti Bunny · ❌ Throughput per object terbatas</div>
                        <div class="provider-when">Pakai saat: dev/staging, MVP launch, simplicity > feature</div>
                    </div>

                    {{-- Local --}}
                    <div class="provider-card" style="opacity:0.7">
                        <div class="provider-header"><span class="provider-name">Local (No CDN)</span><span class="provider-tag" style="background:#3a3a3a;color:#aaa">Dev only</span></div>
                        <div class="provider-fact">Serve dari server origin → bandwidth ISP</div>
                        <div class="prov-mini">✅ Setup 0 menit · ✅ No external dep · ✅ Free</div>
                        <div class="prov-mini neg">❌ Tidak ada edge → latency tinggi untuk user jauh · ❌ Bandwidth ISP mahal · ❌ Origin server kebanjiran traffic saat spike · ❌ Tidak realistic untuk scale</div>
                        <div class="provider-when">Pakai saat: development local, LAN-only, demo internal</div>
                    </div>
                </div>
            </div>

            {{-- ─── DRM ─── --}}
            <div x-show="tab === 'drm'" x-cloak>
                <h3 class="why-h3"><span class="why-pick">Pilihan kita: DIY HLS + AES-128</span></h3>
                <p class="why-reason">DRM yang kita tulis sendiri (~800 LOC PHP) berbasis standar HLS+AES-128. Cukup untuk konten indie Indonesia, $0 license cost.</p>

                <div class="why-grid">
                    <div class="why-pros">
                        <h4>✅ Kelebihan</h4>
                        <ul>
                            <li>$0 license fee (Widevine $20K+/tahun)</li>
                            <li>Full source code, debug + custom apa saja</li>
                            <li>Bekerja di semua browser (Safari, Chrome, Firefox sama)</li>
                            <li>Tidak butuh hardware-backed device (CDM)</li>
                            <li>Setup 1 hari, bukan 2-3 bulan integration</li>
                            <li>Plus layer keamanan tambahan: JWT key, signed URL, geo-block, concurrent limit, forensic watermark</li>
                        </ul>
                    </div>
                    <div class="why-cons">
                        <h4>❌ Kekurangan</h4>
                        <ul>
                            <li>Bukan MPAA Tier 1/2/3 compliant — tidak bisa lisensi Hollywood</li>
                            <li>Tidak HDCP-enforced (screen recorder masih bisa nge-capture)</li>
                            <li>Tidak ada offline DRM (no persistent license)</li>
                            <li>Kunci ada di browser memory — attacker advanced bisa extract</li>
                            <li>Tidak ada studio audit certification</li>
                        </ul>
                    </div>
                </div>

                <h4 class="why-compare-h">Perbandingan vendor:</h4>
                <table class="why-table">
                    <thead><tr><th>DRM</th><th>Vendor</th><th>Browser</th><th>Biaya</th><th>Studio compliance</th><th>Hardware-backed</th></tr></thead>
                    <tbody>
                        <tr style="background:rgba(197,165,90,0.06)"><td><strong style="color:#C5A55A">HLS+AES-128 ★</strong></td><td>DIY</td><td>Semua</td><td>$0</td><td>❌</td><td>❌</td></tr>
                        <tr><td>Widevine L3</td><td>Google</td><td>Chrome, Android, Edge</td><td>$5K/year</td><td>Tier 3</td><td>❌</td></tr>
                        <tr><td>Widevine L1</td><td>Google</td><td>Android L1 devices</td><td>$20K+/year</td><td>Tier 1-2</td><td>✅</td></tr>
                        <tr><td>PlayReady</td><td>Microsoft</td><td>Edge, Xbox, Windows</td><td>Microsoft license</td><td>Tier 1-2</td><td>✅</td></tr>
                        <tr><td>FairPlay</td><td>Apple</td><td>Safari, iOS, tvOS</td><td>Apple Dev + special</td><td>Tier 1-2</td><td>✅</td></tr>
                        <tr><td>PallyCon Multi-DRM</td><td>3rd party (Widevine+PlayReady+FairPlay)</td><td>Semua</td><td>$1,500/bulan</td><td>Tier 1-3</td><td>Depends</td></tr>
                        <tr><td>ezDRM Multi-DRM</td><td>3rd party</td><td>Semua</td><td>$3K-10K/bulan</td><td>Tier 1-3</td><td>Depends</td></tr>
                        <tr><td>BuyDRM KeyOS</td><td>3rd party</td><td>Semua</td><td>Enterprise $$$</td><td>Tier 1</td><td>✅</td></tr>
                    </tbody>
                </table>

                <div class="callout">
                    <strong>Kapan upgrade ke Multi-DRM?</strong> Kalau dapat content licensing deal yang mensyaratkan studio audit (Disney, Warner, Universal, Sony). Atau mau 4K UHD content. Migration: 1-2 minggu dengan PallyCon, struktur kita modular (Shaka Player support multi-DRM out-of-box).
                </div>

                <h4 class="why-compare-h" style="margin-top:28px">📋 Detail per DRM — Pilih sesuai konten + budget</h4>
                <div class="provider-grid">
                    {{-- AES-128 --}}
                    <div class="provider-card" style="border-color:#C5A55A">
                        <div class="provider-header"><span class="provider-name">HLS AES-128 (DIY)</span><span class="provider-tag" style="background:#C5A55A;color:#0a0a0a">DEFAULT</span></div>
                        <div class="provider-fact">$0 lifetime · 800 LOC PHP · Semua browser</div>
                        <div class="prov-mini">✅ Zero license cost · ✅ Browser-agnostic · ✅ Setup 1 hari · ✅ Plus JWT key + signed URL + geo-block + watermark</div>
                        <div class="prov-mini neg">❌ Tidak MPAA-compliant · ❌ Tidak HDCP-enforced · ❌ No offline DRM · ❌ Tidak bisa lisensi Hollywood</div>
                        <div class="provider-when">Pakai saat: konten indie Indonesia, indie production, budget tipis</div>
                    </div>

                    {{-- Widevine L3 --}}
                    <div class="provider-card">
                        <div class="provider-header"><span class="provider-name">Widevine L3</span><span class="provider-tag" style="background:rgba(59,130,246,0.18);color:#93c5fd">Software DRM</span></div>
                        <div class="provider-fact">$5K/year · Chrome/Android/Edge · Software CDM</div>
                        <div class="prov-mini">✅ Standar industry untuk Chrome/Android · ✅ Software-only (no hardware) · ✅ Tier 3 studio audit pass · ✅ Banyak vendor (PallyCon $1500/mo)</div>
                        <div class="prov-mini neg">❌ Tidak HDCP-enforced di L3 · ❌ Cuma Chromium/Android/Edge · ❌ License negotiation Google</div>
                        <div class="provider-when">Pakai saat: konten butuh entry-level studio compliance, budget cukup</div>
                    </div>

                    {{-- Widevine L1 --}}
                    <div class="provider-card">
                        <div class="provider-header"><span class="provider-name">Widevine L1</span><span class="provider-tag" style="background:rgba(168,85,247,0.18);color:#d8b4fe">Hardware DRM</span></div>
                        <div class="provider-fact">$20K+/year · Android L1 devices · TEE-backed</div>
                        <div class="prov-mini">✅ Hardware-backed (TEE) keamanan tinggi · ✅ Tier 1-2 studio compliant · ✅ Bisa 4K UHD · ✅ HDCP enforced</div>
                        <div class="prov-mini neg">❌ Mahal · ❌ Cuma Android L1 devices · ❌ Audit ketat (Conviva, Eurofins) · ❌ Setup 2-3 bulan</div>
                        <div class="provider-when">Pakai saat: konten studio Hollywood, 4K UHD, mobile-focused</div>
                    </div>

                    {{-- FairPlay --}}
                    <div class="provider-card">
                        <div class="provider-header"><span class="provider-name">Apple FairPlay</span><span class="provider-tag" style="background:rgba(168,85,247,0.18);color:#d8b4fe">Hardware DRM</span></div>
                        <div class="provider-fact">Apple Dev + special license · Safari/iOS/tvOS</div>
                        <div class="prov-mini">✅ Satu-satunya DRM untuk Safari/iOS · ✅ Hardware-backed di Apple chip · ✅ Tier 1-2 compliant · ✅ Native player support</div>
                        <div class="prov-mini neg">❌ Cuma Apple devices · ❌ Setup license dari Apple ribet · ❌ Butuh dedicated FairPlay server</div>
                        <div class="provider-when">Pakai saat: pasar iOS Apple-heavy, butuh native iOS app</div>
                    </div>

                    {{-- PlayReady --}}
                    <div class="provider-card">
                        <div class="provider-header"><span class="provider-name">Microsoft PlayReady</span><span class="provider-tag" style="background:rgba(168,85,247,0.18);color:#d8b4fe">Hardware DRM</span></div>
                        <div class="provider-fact">Microsoft license · Edge/Xbox/Windows</div>
                        <div class="prov-mini">✅ Native Edge + Xbox support · ✅ Tier 1-2 compliant · ✅ Standard untuk smart TV Samsung/LG</div>
                        <div class="prov-mini neg">❌ Cuma Microsoft + smart TV ecosystem · ❌ Tidak Chrome/Android/iOS native · ❌ Microsoft licensing</div>
                        <div class="provider-when">Pakai saat: smart TV deployment, Xbox/Windows-heavy user</div>
                    </div>

                    {{-- Multi-CENC PallyCon --}}
                    <div class="provider-card">
                        <div class="provider-header"><span class="provider-name">PallyCon Multi-DRM</span><span class="provider-tag" style="background:rgba(234,179,8,0.18);color:#fde68a">All-in-One</span></div>
                        <div class="provider-fact">$1,500/mo · Widevine+PlayReady+FairPlay · 1 SDK</div>
                        <div class="prov-mini">✅ Cover semua browser/device · ✅ Single integration single billing · ✅ Vendor handle license server · ✅ Setup 1-2 minggu</div>
                        <div class="prov-mini neg">❌ Vendor lock-in · ❌ Setup awal masih audit-heavy · ❌ Mahal untuk MVP</div>
                        <div class="provider-when">Pakai saat: ready buat lisensi studio, butuh universal coverage</div>
                    </div>

                    {{-- ezDRM --}}
                    <div class="provider-card">
                        <div class="provider-header"><span class="provider-name">ezDRM</span><span class="provider-tag" style="background:rgba(234,179,8,0.18);color:#fde68a">Enterprise SaaS</span></div>
                        <div class="provider-fact">$3K-10K/mo · Multi-DRM · Dedicated support</div>
                        <div class="prov-mini">✅ White-glove enterprise support · ✅ Tier 1 ready · ✅ Custom contract</div>
                        <div class="prov-mini neg">❌ Lebih mahal dari PallyCon · ❌ Sales-led pricing (no public price)</div>
                        <div class="provider-when">Pakai saat: studio konten contract sudah deal, butuh enterprise SLA</div>
                    </div>

                    {{-- BuyDRM --}}
                    <div class="provider-card">
                        <div class="provider-header"><span class="provider-name">BuyDRM KeyOS</span><span class="provider-tag" style="background:rgba(239,68,68,0.18);color:#fca5a5">Premium</span></div>
                        <div class="provider-fact">Enterprise $$$ · Full Tier-1 audit pass</div>
                        <div class="prov-mini">✅ Used by Hulu/Disney+/etc · ✅ White-label license server · ✅ Hardware-backed all DRMs</div>
                        <div class="prov-mini neg">❌ Pricing tidak transparan · ❌ Minimum commitment besar · ❌ Overkill untuk &lt; 1M MAU</div>
                        <div class="provider-when">Pakai saat: scale Netflix/Hulu/Disney+, content premium high-value</div>
                    </div>
                </div>
            </div>

            {{-- ─── Database ─── --}}
            <div x-show="tab === 'db'" x-cloak>
                <h3 class="why-h3"><span class="why-pick">Pilihan kita: MySQL 8</span></h3>
                <p class="why-reason">Default Laravel, paling matang ecosystem PHP, MariaDB-compatible, banyak hosting murah support, JSON column support cukup untuk kebutuhan kita.</p>

                <div class="why-grid">
                    <div class="why-pros">
                        <h4>✅ Kelebihan</h4>
                        <ul>
                            <li>Setiap hosting Indonesia support (cPanel, shared hosting)</li>
                            <li>Replication + read replica mudah</li>
                            <li>InnoDB transactional + foreign key</li>
                            <li>JSON_EXTRACT cukup untuk metadata column (drm_config, ai_tags)</li>
                            <li>FULLTEXT index untuk help search</li>
                            <li>Tooling lengkap (DBeaver, Sequel Pro, phpMyAdmin)</li>
                        </ul>
                    </div>
                    <div class="why-cons">
                        <h4>❌ Kekurangan</h4>
                        <ul>
                            <li>JSON operation lebih lemah dari Postgres (no GIN index pada JSON)</li>
                            <li>Tidak punya proper array column (harus pakai JSON)</li>
                            <li>Tidak punya CTE recursive sekuat Postgres untuk graph query</li>
                            <li>Vendor history: Oracle ownership, ada konsen license licensing</li>
                        </ul>
                    </div>
                </div>

                <h4 class="why-compare-h">Perbandingan:</h4>
                <table class="why-table">
                    <thead><tr><th>DB</th><th>Setup</th><th>JSON</th><th>Full-text</th><th>Cocok untuk</th></tr></thead>
                    <tbody>
                        <tr style="background:rgba(197,165,90,0.06)"><td><strong style="color:#C5A55A">MySQL 8 ★</strong></td><td>Trivial</td><td>OK</td><td>Built-in</td><td>App umum, OLTP</td></tr>
                        <tr><td>MariaDB 10</td><td>Trivial</td><td>OK</td><td>Built-in</td><td>Drop-in MySQL replace</td></tr>
                        <tr><td>PostgreSQL 16</td><td>Sedikit lebih ribet</td><td>★ Native</td><td>Built-in + GIN</td><td>Heavy JSON, complex query</td></tr>
                        <tr><td>SQLite</td><td>0 setup</td><td>OK</td><td>FTS5</td><td>Dev, mobile, single-server</td></tr>
                        <tr><td>MongoDB</td><td>Lain dunia</td><td>★ Native</td><td>Text search</td><td>Schema-less data</td></tr>
                    </tbody>
                </table>
            </div>

            {{-- ─── Cache ─── --}}
            <div x-show="tab === 'cache'" x-cloak>
                <h3 class="why-h3"><span class="why-pick">Pilihan kita: Redis 7</span></h3>
                <p class="why-reason">Multi-purpose: cache, session, queue, concurrent stream counter. Single tool, sederhana ops.</p>

                <div class="why-grid">
                    <div class="why-pros">
                        <h4>✅ Kelebihan</h4>
                        <ul>
                            <li>Sub-ms latency (in-memory)</li>
                            <li>Native data structure (counter, set, sorted set, hash) untuk concurrent limiter, leaderboard, dll</li>
                            <li>Pub/sub built-in untuk realtime fallback</li>
                            <li>Persistent (RDB + AOF) → tidak hilang saat restart</li>
                            <li>Default Laravel cache + session + queue driver</li>
                        </ul>
                    </div>
                    <div class="why-cons">
                        <h4>❌ Kekurangan</h4>
                        <ul>
                            <li>RAM intensif (1GB RAM = ~1GB data, bukan disk)</li>
                            <li>Single-thread per node — write-heavy workload butuh cluster</li>
                            <li>Lisensi BSL 1.1 sekarang (Redis Inc) — gunakan Valkey atau KeyDB jika konsen</li>
                        </ul>
                    </div>
                </div>

                <h4 class="why-compare-h">Perbandingan:</h4>
                <table class="why-table">
                    <thead><tr><th>Cache</th><th>Latency</th><th>Persistent</th><th>Use case</th></tr></thead>
                    <tbody>
                        <tr style="background:rgba(197,165,90,0.06)"><td><strong style="color:#C5A55A">Redis ★</strong></td><td>&lt;1ms</td><td>RDB+AOF</td><td>Multi-purpose</td></tr>
                        <tr><td>Valkey (Redis fork)</td><td>&lt;1ms</td><td>Sama</td><td>Drop-in Redis open-source</td></tr>
                        <tr><td>Memcached</td><td>&lt;1ms</td><td>❌</td><td>Pure ephemeral cache</td></tr>
                        <tr><td>File cache (Laravel default)</td><td>~5ms</td><td>✅</td><td>Dev / single-server</td></tr>
                        <tr><td>Database cache</td><td>~20ms</td><td>✅</td><td>No Redis available</td></tr>
                    </tbody>
                </table>
            </div>

            {{-- ─── Queue ─── --}}
            <div x-show="tab === 'queue'" x-cloak>
                <h3 class="why-h3"><span class="why-pick">Pilihan kita: Redis queue + Supervisor workers</span></h3>
                <p class="why-reason">4 logical queue (default, ai-realtime, ai-batch, transcoding) supaya AI batch tidak block transactional jobs.</p>

                <div class="why-grid">
                    <div class="why-pros">
                        <h4>✅ Kelebihan</h4>
                        <ul>
                            <li>Pakai Redis yang sudah ada</li>
                            <li>Worker scale-out gampang (tambah container/process)</li>
                            <li>Failed jobs auto-retry dengan backoff</li>
                            <li>Tidak butuh AWS account / managed service</li>
                            <li>Horizon-lite dashboard kita sendiri</li>
                        </ul>
                    </div>
                    <div class="why-cons">
                        <h4>❌ Kekurangan</h4>
                        <ul>
                            <li>Tidak ada dead-letter queue native</li>
                            <li>Tidak ada cross-region replication</li>
                            <li>Worker monitoring manual (vs Horizon GUI / DataDog)</li>
                            <li>Supervisor config harus dimaintain manual</li>
                        </ul>
                    </div>
                </div>

                <h4 class="why-compare-h">Perbandingan:</h4>
                <table class="why-table">
                    <thead><tr><th>Driver</th><th>Setup</th><th>Throughput</th><th>Cost</th><th>Cocok untuk</th></tr></thead>
                    <tbody>
                        <tr style="background:rgba(197,165,90,0.06)"><td><strong style="color:#C5A55A">Redis ★</strong></td><td>Sudah ada</td><td>10K+/s</td><td>$0</td><td>App umum</td></tr>
                        <tr><td>Database</td><td>0 ekstra</td><td>~500/s</td><td>$0</td><td>Dev / volume rendah</td></tr>
                        <tr><td>AWS SQS</td><td>Manage AWS</td><td>Unlimited</td><td>$0.40/M msg</td><td>Pay-per-use, cloud-native</td></tr>
                        <tr><td>RabbitMQ</td><td>Ribet setup</td><td>Tinggi</td><td>Self-host</td><td>Complex routing, AMQP</td></tr>
                        <tr><td>Laravel Horizon (Redis)</td><td>Plug-in</td><td>Sama Redis</td><td>$0</td><td>GUI dashboard penuh</td></tr>
                    </tbody>
                </table>
            </div>

            {{-- ─── AI ─── --}}
            <div x-show="tab === 'ai'" x-cloak>
                <h3 class="why-h3"><span class="why-pick">Pilihan kita: Multi-provider abstraction (AiClient)</span></h3>
                <p class="why-reason">Tidak lock-in ke 1 vendor. Admin bisa switch dari OpenAI ke Gemini ke Deepseek tanpa restart aplikasi. Hemat cost dengan pakai provider yang murah untuk task bulky (tagging, sentiment) dan provider mahal untuk task strategic (chatbot, plot explain).</p>

                <div class="why-grid">
                    <div class="why-pros">
                        <h4>✅ Kelebihan</h4>
                        <ul>
                            <li>No vendor lock-in — kalau OpenAI naik harga 2× bisa switch</li>
                            <li>Cost mix: Deepseek $0.14/M token vs OpenAI $30/M (200× lebih murah)</li>
                            <li>Failover: provider down → switch instan</li>
                            <li>Function calling support universal (OpenAI-compatible API)</li>
                            <li>Usage tracker per task per provider untuk cost insight</li>
                        </ul>
                    </div>
                    <div class="why-cons">
                        <h4>❌ Kekurangan</h4>
                        <ul>
                            <li>Subtle prompt drift antar provider (perlu testing)</li>
                            <li>Bukan semua model support feature sama (vision, audio, tools)</li>
                            <li>Admin perlu manage multiple API key</li>
                        </ul>
                    </div>
                </div>

                <h4 class="why-compare-h">Perbandingan provider (per Mei 2026):</h4>
                <table class="why-table">
                    <thead><tr><th>Provider</th><th>Best for</th><th>Cost/M token</th><th>Speed</th><th>Indonesian quality</th></tr></thead>
                    <tbody>
                        <tr><td>OpenAI GPT-4o</td><td>Reasoning, chatbot</td><td>$5 in / $15 out</td><td>Medium</td><td>★★★★★</td></tr>
                        <tr><td>OpenAI GPT-4o-mini</td><td>Bulk task</td><td>$0.15 in / $0.60 out</td><td>Fast</td><td>★★★★</td></tr>
                        <tr style="background:rgba(197,165,90,0.06)"><td><strong style="color:#C5A55A">Deepseek V3 ★</strong></td><td>Default kita, bulk + chatbot</td><td>$0.14 in / $0.28 out</td><td>Fast</td><td>★★★★</td></tr>
                        <tr><td>Gemini 1.5 Flash</td><td>Vision, thumbnail pick</td><td>$0.075 in / $0.30 out</td><td>Very fast</td><td>★★★★</td></tr>
                        <tr><td>Claude 3.5 Sonnet</td><td>Long context, writing</td><td>$3 in / $15 out</td><td>Medium</td><td>★★★★★</td></tr>
                        <tr><td>Groq Llama 3</td><td>Realtime chat</td><td>$0.05/$0.08</td><td>★ Fastest</td><td>★★★</td></tr>
                        <tr><td>Mistral Large</td><td>European jurisdiction</td><td>$2/$6</td><td>Medium</td><td>★★★</td></tr>
                        <tr><td>OpenRouter</td><td>Test berbagai model</td><td>Markup 10%</td><td>Varies</td><td>Varies</td></tr>
                    </tbody>
                </table>
            </div>

            {{-- ─── Payment ─── --}}
            <div x-show="tab === 'payment'" x-cloak>
                <h3 class="why-h3"><span class="why-pick">Pilihan kita: Midtrans Snap</span></h3>
                <p class="why-reason">Aggregator payment gateway Indonesia paling lengkap. Mendukung semua metode lokal: GoPay, OVO, ShopeePay, kartu kredit, bank transfer (BCA/Mandiri/BNI/BRI virtual account), Indomaret, Alfamart.</p>

                <div class="why-grid">
                    <div class="why-pros">
                        <h4>✅ Kelebihan</h4>
                        <ul>
                            <li>Snap = single integration, semua metode bayar</li>
                            <li>Owned by GoTo Group — terpercaya + stabilitas tinggi</li>
                            <li>Settlement T+1 untuk e-wallet, T+2 untuk bank</li>
                            <li>Sandbox lengkap untuk test</li>
                            <li>Recurring/subscription support native</li>
                        </ul>
                    </div>
                    <div class="why-cons">
                        <h4>❌ Kekurangan</h4>
                        <ul>
                            <li>Fee per transaksi cukup tinggi: 1.5-3% + Rp 2.000</li>
                            <li>Dashboard reporting basic</li>
                            <li>Customer support response lambat untuk plan free/standard</li>
                            <li>Tidak punya BNPL (paylater) sehebat Atome/Kredivo</li>
                        </ul>
                    </div>
                </div>

                <h4 class="why-compare-h">Perbandingan:</h4>
                <table class="why-table">
                    <thead><tr><th>Provider</th><th>Fee</th><th>Settlement</th><th>Subscription</th><th>Cocok untuk</th></tr></thead>
                    <tbody>
                        <tr style="background:rgba(197,165,90,0.06)"><td><strong style="color:#C5A55A">Midtrans ★</strong></td><td>1.5-3%</td><td>T+1/T+2</td><td>✅</td><td>Indonesia general</td></tr>
                        <tr><td>Xendit</td><td>1.5-3%</td><td>T+1</td><td>✅</td><td>Asia Tenggara, dashboard bagus</td></tr>
                        <tr><td>Doku</td><td>~2%</td><td>T+1</td><td>✅</td><td>Enterprise lokal</td></tr>
                        <tr><td>Stripe</td><td>2.9% + $0.30</td><td>T+2</td><td>✅</td><td>Global, tidak include GoPay/OVO</td></tr>
                        <tr><td>Mayar / iPaymu</td><td>1-2%</td><td>T+1</td><td>⚠️</td><td>Murah, fitur lebih basic</td></tr>
                    </tbody>
                </table>

                <h4 class="why-compare-h" style="margin-top:28px">📋 Detail per Payment Gateway</h4>
                <div class="provider-grid">
                    {{-- Midtrans --}}
                    <div class="provider-card" style="border-color:#C5A55A">
                        <div class="provider-header"><span class="provider-name">Midtrans</span><span class="provider-tag" style="background:#C5A55A;color:#0a0a0a">DEFAULT</span></div>
                        <div class="provider-fact">Fee 1.5-3% · T+1/T+2 · Owned by GoTo</div>
                        <div class="prov-mini">✅ GoPay/OVO/ShopeePay/DANA built-in · ✅ Snap = single integration semua metode · ✅ VA Bank Mandiri/BCA/BNI/BRI/Permata · ✅ Indomaret/Alfamart cash · ✅ Reputation tinggi (GoTo Group)</div>
                        <div class="prov-mini neg">❌ Fee tinggi (3% kartu kredit) · ❌ Dashboard reporting basic · ❌ Customer support lambat di plan standard</div>
                        <div class="provider-when">Pakai saat: target user Indonesia, butuh semua metode bayar lokal</div>
                    </div>

                    {{-- Xendit --}}
                    <div class="provider-card">
                        <div class="provider-header"><span class="provider-name">Xendit</span><span class="provider-tag" style="background:rgba(59,130,246,0.18);color:#93c5fd">SE Asia</span></div>
                        <div class="provider-fact">Fee 1.5-3% · T+1 · Multi-country (ID/PH/MY/TH/VN)</div>
                        <div class="prov-mini">✅ Dashboard reporting jauh lebih bagus · ✅ Multi-country dari satu account · ✅ Recurring billing canggih · ✅ Customer support responsif · ✅ Developer docs solid</div>
                        <div class="prov-mini neg">❌ Coverage e-wallet lokal kalah sedikit dari Midtrans · ❌ Customer base lebih kecil</div>
                        <div class="provider-when">Pakai saat: ekspansi ke Asia Tenggara, butuh dashboard analytics bagus</div>
                    </div>

                    {{-- Doku --}}
                    <div class="provider-card">
                        <div class="provider-header"><span class="provider-name">Doku</span><span class="provider-tag" style="background:rgba(168,85,247,0.18);color:#d8b4fe">Enterprise ID</span></div>
                        <div class="provider-fact">Fee ~2% · T+1 · Used by Tokopedia/Tiket.com</div>
                        <div class="prov-mini">✅ Salah satu PSP tertua di Indonesia · ✅ Enterprise contract negotiable · ✅ Custom integration support · ✅ B2B billing</div>
                        <div class="prov-mini neg">❌ Setup lebih ribet · ❌ Documentation kurang modern · ❌ Less startup-friendly</div>
                        <div class="provider-when">Pakai saat: enterprise contract, B2B billing, custom kebutuhan</div>
                    </div>

                    {{-- Stripe --}}
                    <div class="provider-card">
                        <div class="provider-header"><span class="provider-name">Stripe</span><span class="provider-tag" style="background:rgba(34,197,94,0.18);color:#86efac">Global</span></div>
                        <div class="provider-fact">Fee 2.9% + $0.30 · T+2 · Global standard</div>
                        <div class="prov-mini">✅ Developer experience #1 di dunia · ✅ Subscription billing terbaik · ✅ Global card coverage · ✅ Invoicing, tax, marketplace built-in · ✅ Stripe Atlas (incorp US)</div>
                        <div class="prov-mini neg">❌ Tidak support GoPay/OVO/ShopeePay/DANA · ❌ Tidak ada VA bank Indonesia · ❌ Currency lebih ribet untuk IDR · ❌ Cuma kartu + bank transfer global</div>
                        <div class="provider-when">Pakai saat: target user global, B2B SaaS, fokus subscription</div>
                    </div>

                    {{-- iPaymu / Mayar --}}
                    <div class="provider-card">
                        <div class="provider-header"><span class="provider-name">Mayar / iPaymu</span><span class="provider-tag" style="background:rgba(234,179,8,0.18);color:#fde68a">Indie</span></div>
                        <div class="provider-fact">Fee 1-2% · T+1 · Bootstrap-friendly</div>
                        <div class="prov-mini">✅ Fee paling murah · ✅ Onboarding cepat (1 hari) · ✅ Cocok creator economy</div>
                        <div class="prov-mini neg">❌ Tidak semua metode bayar tersedia · ❌ Volume capacity lebih kecil · ❌ Smart-contract level features kurang · ❌ Dashboard basic</div>
                        <div class="provider-when">Pakai saat: MVP/bootstrap, indie product, volume kecil</div>
                    </div>

                    {{-- None --}}
                    <div class="provider-card" style="opacity:0.7">
                        <div class="provider-header"><span class="provider-name">Disabled</span><span class="provider-tag" style="background:#3a3a3a;color:#aaa">Free-only</span></div>
                        <div class="provider-fact">Payment dimatikan total — cuma free plan</div>
                        <div class="prov-mini">✅ Zero PCI compliance burden · ✅ No transaction fee · ✅ Setup 0 menit</div>
                        <div class="prov-mini neg">❌ No revenue model · ❌ Free plan only · ❌ Tidak bisa monetize</div>
                        <div class="provider-when">Pakai saat: free service, ad-supported, B2B free trial period</div>
                    </div>
                </div>
            </div>

            {{-- ─── Realtime ─── --}}
            <div x-show="tab === 'realtime'" x-cloak>
                <h3 class="why-h3"><span class="why-pick">Pilihan kita: Pusher Channels</span></h3>
                <p class="why-reason">Managed WebSocket broker. Plug-in dengan Laravel Echo. Free tier 200K message/day cukup untuk early stage.</p>

                <div class="why-grid">
                    <div class="why-pros">
                        <h4>✅ Kelebihan</h4>
                        <ul>
                            <li>Setup 5 menit — Laravel Echo support native</li>
                            <li>Presence channel untuk watch party</li>
                            <li>Free tier sangat generous</li>
                            <li>Dashboard live debug per channel</li>
                            <li>SDK lengkap (JS, iOS, Android)</li>
                        </ul>
                    </div>
                    <div class="why-cons">
                        <h4>❌ Kekurangan</h4>
                        <ul>
                            <li>Vendor lock-in (proprietary API)</li>
                            <li>Pricing naik exponentially di tier tinggi</li>
                            <li>Bandwidth not unlimited</li>
                            <li>Latency tergantung region (mostly US/EU)</li>
                        </ul>
                    </div>
                </div>

                <h4 class="why-compare-h">Perbandingan:</h4>
                <table class="why-table">
                    <thead><tr><th>Vendor</th><th>Type</th><th>Free tier</th><th>Pricing</th><th>Best for</th></tr></thead>
                    <tbody>
                        <tr style="background:rgba(197,165,90,0.06)"><td><strong style="color:#C5A55A">Pusher ★</strong></td><td>Managed SaaS</td><td>200K msg/day</td><td>$49/mo+</td><td>Quick start</td></tr>
                        <tr><td>Ably</td><td>Managed SaaS</td><td>6M msg/mo</td><td>$25/mo+</td><td>Higher reliability SLA</td></tr>
                        <tr><td>Soketi</td><td>Self-host (Pusher-compatible)</td><td>Free</td><td>VPS cost</td><td>Drop-in Pusher replacement</td></tr>
                        <tr><td>Laravel Reverb</td><td>Self-host (official Laravel)</td><td>Free</td><td>VPS cost</td><td>Laravel-native, full control</td></tr>
                        <tr><td>Socket.io self-host</td><td>Self-host (Node.js)</td><td>Free</td><td>VPS cost</td><td>Custom protocol</td></tr>
                    </tbody>
                </table>

                <div class="callout">
                    <strong>Roadmap:</strong> Saat traffic Pusher menyentuh $200/bulan, migrate ke <strong>Laravel Reverb</strong> (self-host) — drop-in tanpa code change karena pakai Pusher protocol.
                </div>

                <h4 class="why-compare-h" style="margin-top:28px">📋 Detail per Realtime Broker</h4>
                <div class="provider-grid">
                    <div class="provider-card" style="border-color:#C5A55A">
                        <div class="provider-header"><span class="provider-name">Pusher</span><span class="provider-tag" style="background:#C5A55A;color:#0a0a0a">DEFAULT</span></div>
                        <div class="provider-fact">200K msg/day free · $49/mo+ · Managed SaaS</div>
                        <div class="prov-mini">✅ Setup 5 menit · ✅ Laravel Echo plug-in · ✅ Presence channel untuk watch party · ✅ Free tier generous</div>
                        <div class="prov-mini neg">❌ Vendor lock-in · ❌ Pricing exponential di scale · ❌ Latency US/EU-biased</div>
                        <div class="provider-when">Pakai saat: MVP, &lt; 200K message/day, butuh quick start</div>
                    </div>

                    <div class="provider-card">
                        <div class="provider-header"><span class="provider-name">Laravel Reverb</span><span class="provider-tag" style="background:rgba(34,197,94,0.18);color:#86efac">Self-host</span></div>
                        <div class="provider-fact">$5+ VPS · Official Laravel · Pusher-protocol compatible</div>
                        <div class="prov-mini">✅ Free (cuma VPS cost) · ✅ Drop-in replace Pusher tanpa code change · ✅ Official Laravel maintained · ✅ Self-host = full control</div>
                        <div class="prov-mini neg">❌ Harus operate VPS sendiri · ❌ Scaling horizontal butuh setup · ❌ Tidak ada dashboard built-in</div>
                        <div class="provider-when">Pakai saat: Pusher cost &gt; $200/mo, ingin self-host</div>
                    </div>

                    <div class="provider-card">
                        <div class="provider-header"><span class="provider-name">Soketi</span><span class="provider-tag" style="background:rgba(34,197,94,0.18);color:#86efac">Self-host</span></div>
                        <div class="provider-fact">$5+ VPS · Node.js · Pusher-protocol compatible</div>
                        <div class="prov-mini">✅ Free + open source · ✅ Drop-in Pusher replacement · ✅ Horizontal scale via Redis adapter · ✅ Lebih ringan dari socket.io</div>
                        <div class="prov-mini neg">❌ Maintained by community (bukan official Laravel) · ❌ Node.js dep · ❌ Documentation lebih tipis</div>
                        <div class="provider-when">Pakai saat: butuh self-host tapi tim sudah ada Node.js</div>
                    </div>

                    <div class="provider-card">
                        <div class="provider-header"><span class="provider-name">Ably</span><span class="provider-tag" style="background:rgba(59,130,246,0.18);color:#93c5fd">Premium SaaS</span></div>
                        <div class="provider-fact">6M msg/mo free · $25/mo+ · Highest SLA</div>
                        <div class="prov-mini">✅ SLA 99.999% (5 nines) · ✅ Push notification + realtime di satu service · ✅ Global edge · ✅ Free tier lebih besar</div>
                        <div class="prov-mini neg">❌ API tidak compatible dengan Echo native (perlu wrapper) · ❌ Vendor lock-in lebih dalam</div>
                        <div class="provider-when">Pakai saat: butuh enterprise SLA, mission-critical realtime</div>
                    </div>

                    <div class="provider-card" style="opacity:0.7">
                        <div class="provider-header"><span class="provider-name">Polling Only</span><span class="provider-tag" style="background:#3a3a3a;color:#aaa">Fallback</span></div>
                        <div class="provider-fact">Fetch tiap 30s · No WebSocket · $0</div>
                        <div class="prov-mini">✅ Zero dependency · ✅ Bekerja di network apapun (no WS) · ✅ Free</div>
                        <div class="prov-mini neg">❌ Bukan real realtime · ❌ Server load lebih tinggi (polling overhead) · ❌ Tidak cocok watch party</div>
                        <div class="provider-when">Pakai saat: Pusher belum di-setup, atau emergency fallback</div>
                    </div>
                </div>
            </div>

            {{-- ─── Search ─── --}}
            <div x-show="tab === 'search'" x-cloak>
                <h3 class="why-h3"><span class="why-pick">Pilihan kita: MySQL LIKE + AI Smart Search</span></h3>
                <p class="why-reason">Untuk catalog &lt; 10K film, MySQL LIKE + indexed `title` column sudah cukup cepat. Smart Search bar pakai AI intent classifier yang route ke search service yang sesuai (title / actor / vibe / decade).</p>

                <div class="why-grid">
                    <div class="why-pros">
                        <h4>✅ Kelebihan</h4>
                        <ul>
                            <li>No extra service to run</li>
                            <li>$0 extra cost</li>
                            <li>Bisa search by intent (vibe, mood, decade) via AI</li>
                            <li>Help center pakai FULLTEXT MATCH AGAINST</li>
                        </ul>
                    </div>
                    <div class="why-cons">
                        <h4>❌ Kekurangan</h4>
                        <ul>
                            <li>Tidak ada typo tolerance (search "evangelyn" gagal find "evangeline")</li>
                            <li>Tidak ada faceted filter UI yang canggih</li>
                            <li>Tidak punya synonym ("film romance" tidak find "romcom")</li>
                            <li>Performance degrade saat catalog &gt; 50K row</li>
                        </ul>
                    </div>
                </div>

                <h4 class="why-compare-h">Perbandingan:</h4>
                <table class="why-table">
                    <thead><tr><th>Search</th><th>Typo tolerant</th><th>Setup</th><th>Cost</th><th>Best for catalog size</th></tr></thead>
                    <tbody>
                        <tr style="background:rgba(197,165,90,0.06)"><td><strong style="color:#C5A55A">MySQL LIKE + AI ★</strong></td><td>⚠️ via AI</td><td>0</td><td>$0</td><td>&lt; 10K</td></tr>
                        <tr><td>Meilisearch</td><td>✅</td><td>1 binary</td><td>Self-host VPS</td><td>10K - 1M</td></tr>
                        <tr><td>Algolia</td><td>✅ ★</td><td>SaaS</td><td>$1/1K record/mo</td><td>1K - 1M (cepat)</td></tr>
                        <tr><td>Typesense</td><td>✅</td><td>Cloud / self</td><td>$0.02/hr+</td><td>10K - 5M</td></tr>
                        <tr><td>Elasticsearch</td><td>✅</td><td>Berat (Java)</td><td>VPS 4GB+</td><td>100K+</td></tr>
                    </tbody>
                </table>
            </div>

            {{-- ─── Email ─── --}}
            <div x-show="tab === 'email'" x-cloak>
                <h3 class="why-h3"><span class="why-pick">Pilihan kita: SMTP standar + Mailchimp untuk newsletter</span></h3>
                <p class="why-reason">SMTP via mail provider (Gmail Workspace / Zoho Mail) untuk transactional. Mailchimp untuk marketing campaign batch.</p>

                <h4 class="why-compare-h">Perbandingan:</h4>
                <table class="why-table">
                    <thead><tr><th>Provider</th><th>Free tier</th><th>Per email cost</th><th>Best for</th></tr></thead>
                    <tbody>
                        <tr><td>Gmail Workspace SMTP</td><td>500/day per account</td><td>—</td><td>Personal scale dev</td></tr>
                        <tr style="background:rgba(197,165,90,0.06)"><td><strong style="color:#C5A55A">Mailchimp ★</strong></td><td>500 contacts</td><td>$13/mo @ 500</td><td>Marketing campaign</td></tr>
                        <tr><td>AWS SES</td><td>62K/mo free (in EC2)</td><td>$0.10/1K</td><td>Volume tinggi, hemat</td></tr>
                        <tr><td>Mailgun</td><td>100/day</td><td>$15/mo @ 10K</td><td>Transactional fokus</td></tr>
                        <tr><td>SendGrid</td><td>100/day</td><td>$20/mo @ 50K</td><td>Twilio ecosystem</td></tr>
                        <tr><td>Postmark</td><td>100/mo</td><td>$15/mo @ 10K</td><td>Highest deliverability</td></tr>
                        <tr><td>Resend</td><td>3K/mo, 100/day</td><td>$20/mo @ 50K</td><td>Developer-friendly</td></tr>
                    </tbody>
                </table>

                <div class="callout">
                    <strong>Kapan migrate ke AWS SES?</strong> Saat email transactional &gt; 100K/bulan. SES paling hemat di volume tinggi.
                </div>

                <h4 class="why-compare-h" style="margin-top:28px">📋 Detail per Email Provider</h4>
                <div class="provider-grid">
                    <div class="provider-card" style="border-color:#C5A55A">
                        <div class="provider-header"><span class="provider-name">SMTP (Gmail/Zoho)</span><span class="provider-tag" style="background:#C5A55A;color:#0a0a0a">DEFAULT</span></div>
                        <div class="provider-fact">$6/mo Workspace · 500/day per account · Universal</div>
                        <div class="prov-mini">✅ Setup trivial · ✅ Inbox-friendly (from Gmail = trusted) · ✅ Free tier ada · ✅ Bekerja di mana saja</div>
                        <div class="prov-mini neg">❌ Limit 500 email/day per account · ❌ Tidak cocok untuk volume tinggi · ❌ No per-recipient analytics</div>
                        <div class="provider-when">Pakai saat: MVP, &lt; 500 email/day, transactional saja</div>
                    </div>

                    <div class="provider-card">
                        <div class="provider-header"><span class="provider-name">AWS SES</span><span class="provider-tag" style="background:rgba(234,179,8,0.18);color:#fde68a">Volume King</span></div>
                        <div class="provider-fact">62K/mo free (di EC2) · $0.10/1K · Sandbox limit awal</div>
                        <div class="prov-mini">✅ Paling murah di volume tinggi · ✅ Reputation IP shared dengan AWS · ✅ Built-in bounce/complaint handler · ✅ DKIM auto-config</div>
                        <div class="prov-mini neg">❌ Setup awal ribet (sandbox + production limit request) · ❌ Tidak ada UI campaign · ❌ Inbox deliverability variable</div>
                        <div class="provider-when">Pakai saat: email transactional &gt; 100K/mo</div>
                    </div>

                    <div class="provider-card">
                        <div class="provider-header"><span class="provider-name">Mailgun</span><span class="provider-tag" style="background:rgba(59,130,246,0.18);color:#93c5fd">Pro</span></div>
                        <div class="provider-fact">100/day free · $15/mo @ 10K · API-first</div>
                        <div class="prov-mini">✅ Transactional fokus · ✅ Analytics per-email tracking lengkap · ✅ Webhook untuk event (open/click/bounce) · ✅ EU + US region pilihan</div>
                        <div class="prov-mini neg">❌ Free tier kecil (100/day) · ❌ Setup DNS lebih ribet · ❌ Bounce rate strict (auto-suspend)</div>
                        <div class="provider-when">Pakai saat: butuh tracking analytics rinci per email</div>
                    </div>

                    <div class="provider-card">
                        <div class="provider-header"><span class="provider-name">Postmark</span><span class="provider-tag" style="background:rgba(168,85,247,0.18);color:#d8b4fe">Highest Deliverability</span></div>
                        <div class="provider-fact">100/mo free · $15/mo @ 10K · Inbox-first</div>
                        <div class="prov-mini">✅ Inbox rate tertinggi di industry · ✅ Separate stream untuk transactional vs marketing · ✅ Email templates dengan branching · ✅ Customer support responsif</div>
                        <div class="prov-mini neg">❌ Lebih mahal per email dibanding SES · ❌ Marketing campaign features tidak ada · ❌ Strict acceptable use policy</div>
                        <div class="provider-when">Pakai saat: kritikal email harus masuk inbox (verify, password reset)</div>
                    </div>

                    <div class="provider-card">
                        <div class="provider-header"><span class="provider-name">Resend</span><span class="provider-tag" style="background:rgba(34,197,94,0.18);color:#86efac">Modern</span></div>
                        <div class="provider-fact">3K/mo, 100/day free · $20/mo @ 50K · DX-focused</div>
                        <div class="prov-mini">✅ API termodern, developer-friendly · ✅ React Email integration · ✅ Free tier decent · ✅ Dashboard cantik · ✅ Built by ex-Vercel team</div>
                        <div class="prov-mini neg">❌ Relatif baru (2023) · ❌ Lebih sedikit reputation IP pool · ❌ Marketing tools belum lengkap</div>
                        <div class="provider-when">Pakai saat: tim suka modern stack, butuh React Email template</div>
                    </div>

                    <div class="provider-card">
                        <div class="provider-header"><span class="provider-name">SendGrid</span><span class="provider-tag" style="background:rgba(59,130,246,0.18);color:#93c5fd">Twilio</span></div>
                        <div class="provider-fact">100/day free · $20/mo @ 50K · Twilio-owned</div>
                        <div class="prov-mini">✅ Bagian Twilio ecosystem (SMS/voice integrate) · ✅ Marketing + transactional di satu platform · ✅ Email designer UI · ✅ Inbox sangat solid</div>
                        <div class="prov-mini neg">❌ UI overkill kalau cuma transactional · ❌ Pricing kompleks (multiple tiers) · ❌ Marketing tools cenderung kalah Mailchimp</div>
                        <div class="provider-when">Pakai saat: sudah pakai Twilio SMS/voice</div>
                    </div>
                </div>
            </div>

            {{-- ─── Auth ─── --}}
            <div x-show="tab === 'auth'" x-cloak>
                <h3 class="why-h3"><span class="why-pick">Pilihan kita: Laravel built-in + Socialite (Google OAuth)</span></h3>
                <p class="why-reason">Laravel sudah punya semua: session, password reset, email verify, remember-me, 2FA (TOTP custom). Socialite untuk Google login. Bonus: full ownership data user, no monthly fee per active user.</p>

                <h4 class="why-compare-h">Perbandingan:</h4>
                <table class="why-table">
                    <thead><tr><th>Solution</th><th>Setup</th><th>Cost</th><th>Data ownership</th><th>SSO</th></tr></thead>
                    <tbody>
                        <tr style="background:rgba(197,165,90,0.06)"><td><strong style="color:#C5A55A">Laravel + Socialite ★</strong></td><td>Sudah ada</td><td>$0</td><td>✅ Full</td><td>Manual</td></tr>
                        <tr><td>Auth0</td><td>1 hari</td><td>$23/mo @ 1K MAU</td><td>3rd party</td><td>✅ Built-in</td></tr>
                        <tr><td>Clerk</td><td>1 hari</td><td>$25/mo @ 5K MAU</td><td>3rd party</td><td>✅ Built-in</td></tr>
                        <tr><td>Firebase Auth</td><td>2 hari</td><td>Free 50K MAU</td><td>Google</td><td>✅</td></tr>
                        <tr><td>AWS Cognito</td><td>2 hari</td><td>$0.0055/MAU</td><td>AWS</td><td>✅</td></tr>
                    </tbody>
                </table>
            </div>

            {{-- ─── Framework ─── --}}
            <div x-show="tab === 'framework'" x-cloak>
                <h3 class="why-h3"><span class="why-pick">Pilihan kita: Laravel 12 (PHP 8.2)</span></h3>
                <p class="why-reason">Mature ecosystem. Tooling lengkap (Eloquent, Migrations, Queue, Mail, Validation, Auth). Community Indonesia paling besar. Hosting murah where-where.</p>

                <h4 class="why-compare-h">Perbandingan:</h4>
                <table class="why-table">
                    <thead><tr><th>Framework</th><th>Bahasa</th><th>Learning curve</th><th>Hosting</th><th>Best for</th></tr></thead>
                    <tbody>
                        <tr style="background:rgba(197,165,90,0.06)"><td><strong style="color:#C5A55A">Laravel 12 ★</strong></td><td>PHP 8.2</td><td>Mudah</td><td>Murah, $2/mo cPanel</td><td>SaaS Indonesia</td></tr>
                        <tr><td>Next.js 14</td><td>TypeScript</td><td>Sedang</td><td>Vercel $20/mo+</td><td>SPA, edge function</td></tr>
                        <tr><td>Django 5</td><td>Python 3.12</td><td>Sedang</td><td>$5+ VPS</td><td>Data-heavy app</td></tr>
                        <tr><td>Rails 7</td><td>Ruby 3.3</td><td>Mudah</td><td>$5+ Heroku</td><td>Productivity focus</td></tr>
                        <tr><td>NestJS</td><td>TypeScript</td><td>Steep</td><td>$5+ VPS</td><td>Microservices</td></tr>
                        <tr><td>Spring Boot</td><td>Java/Kotlin</td><td>Steep</td><td>Tinggi RAM</td><td>Enterprise Java</td></tr>
                    </tbody>
                </table>
            </div>

            {{-- ─── Frontend ─── --}}
            <div x-show="tab === 'frontend'" x-cloak>
                <h3 class="why-h3"><span class="why-pick">Pilihan kita: Blade + Alpine.js + Tailwind (SSR-first)</span></h3>
                <p class="why-reason">Server-side render dari Laravel. Alpine.js untuk interaksi kecil (dropdown, modal, tabs). Tailwind untuk styling. Tidak SPA — page reload tradisional (lebih sederhana, SEO bagus, no API duplication).</p>

                <div class="why-grid">
                    <div class="why-pros">
                        <h4>✅ Kelebihan</h4>
                        <ul>
                            <li>1 codebase, no separate API</li>
                            <li>SEO friendly out-of-box (server-rendered HTML)</li>
                            <li>Alpine 5 KB vs React 45 KB</li>
                            <li>No build complexity (semua Vite-bundled)</li>
                            <li>Sangat fast time-to-first-byte</li>
                        </ul>
                    </div>
                    <div class="why-cons">
                        <h4>❌ Kekurangan</h4>
                        <ul>
                            <li>Tidak ada offline-first experience seperti SPA</li>
                            <li>Page reload per navigation (vs SPA instant)</li>
                            <li>Lebih sulit untuk complex client-side state</li>
                            <li>Alpine kurang ecosystem dibanding React</li>
                        </ul>
                    </div>
                </div>

                <h4 class="why-compare-h">Perbandingan:</h4>
                <table class="why-table">
                    <thead><tr><th>Approach</th><th>Bundle size</th><th>SEO</th><th>DX complexity</th><th>Best for</th></tr></thead>
                    <tbody>
                        <tr style="background:rgba(197,165,90,0.06)"><td><strong style="color:#C5A55A">Blade + Alpine ★</strong></td><td>~50 KB</td><td>★ Native</td><td>Mudah</td><td>Server-rendered app</td></tr>
                        <tr><td>Livewire 3</td><td>~80 KB</td><td>★ Native</td><td>Mudah</td><td>Realtime UI tanpa SPA</td></tr>
                        <tr><td>Vue 3 SPA</td><td>~150 KB</td><td>Butuh SSR</td><td>Sedang</td><td>Complex client state</td></tr>
                        <tr><td>React SPA</td><td>~250 KB</td><td>Butuh Next.js</td><td>Tinggi</td><td>Ecosystem React</td></tr>
                        <tr><td>HTMX</td><td>~14 KB</td><td>★ Native</td><td>Mudah</td><td>Server-rendered + lazy</td></tr>
                    </tbody>
                </table>
            </div>

            {{-- ─── Mobile ─── --}}
            <div x-show="tab === 'mobile'" x-cloak>
                <h3 class="why-h3"><span class="why-pick">Pilihan kita: PWA + NativePHP Android (deferred)</span></h3>
                <p class="why-reason">PWA = Progressive Web App. Web app yang bisa di-install ke home screen, jalan offline (sebagian), kirim push notif. NativePHP untuk build Android app dari PHP code (deferred — belum prioritas).</p>

                <h4 class="why-compare-h">Perbandingan:</h4>
                <table class="why-table">
                    <thead><tr><th>Approach</th><th>Effort</th><th>iOS</th><th>Android</th><th>App Store</th><th>Best for</th></tr></thead>
                    <tbody>
                        <tr style="background:rgba(197,165,90,0.06)"><td><strong style="color:#C5A55A">PWA ★</strong></td><td>1 minggu</td><td>⚠️ Limited</td><td>✅</td><td>❌</td><td>MVP, web-first</td></tr>
                        <tr><td>React Native</td><td>2-3 bulan</td><td>✅</td><td>✅</td><td>✅</td><td>Cross-platform native</td></tr>
                        <tr><td>Flutter</td><td>2-3 bulan</td><td>✅</td><td>✅</td><td>✅</td><td>Pixel-perfect UI</td></tr>
                        <tr><td>NativePHP</td><td>1 bulan</td><td>—</td><td>✅</td><td>✅</td><td>PHP team, no JS</td></tr>
                        <tr><td>Native Swift + Kotlin</td><td>6+ bulan</td><td>★ Best</td><td>★ Best</td><td>✅</td><td>Performance ultimate</td></tr>
                        <tr><td>Capacitor (PWA wrap)</td><td>1 minggu</td><td>✅</td><td>✅</td><td>✅</td><td>PWA + native shell</td></tr>
                    </tbody>
                </table>
            </div>

            {{-- ─── Monitoring ─── --}}
            <div x-show="tab === 'monitoring'" x-cloak>
                <h3 class="why-h3"><span class="why-pick">Pilihan kita: Custom dashboard (admin/performance + admin/queues + admin/health)</span></h3>
                <p class="why-reason">Kita build dashboard sendiri yang spesifik untuk FLiK (AI usage cost, queue depth, p95 latency per AI task, encoding job status). Free + zero vendor lock-in.</p>

                <h4 class="why-compare-h">Perbandingan:</h4>
                <table class="why-table">
                    <thead><tr><th>Tool</th><th>Type</th><th>Free tier</th><th>Cost (small)</th><th>Best for</th></tr></thead>
                    <tbody>
                        <tr style="background:rgba(197,165,90,0.06)"><td><strong style="color:#C5A55A">Custom dashboard ★</strong></td><td>DIY</td><td>$0</td><td>$0</td><td>App-specific KPI</td></tr>
                        <tr><td>Laravel Horizon</td><td>Queue monitor</td><td>$0</td><td>$0</td><td>Queue-focused</td></tr>
                        <tr><td>Laravel Telescope</td><td>Dev debugger</td><td>$0</td><td>$0</td><td>Local development</td></tr>
                        <tr><td>Sentry</td><td>Error tracking</td><td>5K events/mo</td><td>$26/mo @ 50K</td><td>Production error</td></tr>
                        <tr><td>DataDog</td><td>Full APM</td><td>14-day trial</td><td>$15+/host/mo</td><td>Enterprise</td></tr>
                        <tr><td>Grafana + Prometheus</td><td>Self-host metrics</td><td>$0</td><td>VPS cost</td><td>Full control infra</td></tr>
                        <tr><td>New Relic</td><td>APM SaaS</td><td>100GB/mo data</td><td>$25/user/mo</td><td>Enterprise APM</td></tr>
                    </tbody>
                </table>

                <div class="callout">
                    <strong>Roadmap:</strong> Tambahkan <strong>Sentry</strong> (free tier 5K events) untuk error tracking + alert real-time. Cheap insurance untuk catch issue sebelum user complain.
                </div>
            </div>

            </div>{{-- /tab content --}}
        </div>{{-- /docs-card --}}

        <style>
            /* Per-provider cards (used in CDN/DRM/Payment/Realtime/Email tabs) */
            .provider-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
                gap: 12px;
                margin-top: 12px;
            }
            .provider-card {
                background: linear-gradient(180deg, #1a1a1a, #141414);
                border: 1px solid #2a2a2a;
                border-radius: 12px;
                padding: 16px 18px;
                transition: all 0.15s ease;
            }
            .provider-card:hover {
                border-color: #3a3a3a;
                transform: translateY(-1px);
            }
            .provider-header {
                display: flex; align-items: center; justify-content: space-between;
                gap: 8px; margin-bottom: 6px; flex-wrap: wrap;
            }
            .provider-name {
                color: #fff; font-weight: 700; font-size: 14px;
            }
            .provider-tag {
                font-size: 10px; font-weight: 700;
                padding: 3px 9px; border-radius: 12px;
                letter-spacing: 0.5px;
                text-transform: uppercase;
            }
            .provider-fact {
                color: #888; font-size: 11.5px; margin-bottom: 10px;
                font-family: ui-monospace, Menlo, monospace;
            }
            .prov-mini {
                color: #ccc; font-size: 12px; line-height: 1.55;
                margin: 4px 0;
            }
            .prov-mini.neg { color: #999; }
            .provider-when {
                margin-top: 10px;
                padding-top: 10px;
                border-top: 1px dashed #2a2a2a;
                color: #C5A55A;
                font-size: 11.5px;
                font-weight: 600;
            }
            .tech-tab {
                padding: 12px 18px;
                background: transparent; border: none;
                color: #888; cursor: pointer;
                font-size: 12.5px; font-weight: 600;
                white-space: nowrap;
                border-bottom: 2px solid transparent;
                transition: all 0.15s ease;
            }
            .tech-tab:hover { color: #C5A55A; }
            .tech-tab.is-active {
                color: #C5A55A;
                border-bottom-color: #C5A55A;
                background: rgba(197,165,90,0.05);
            }
            .why-h3 {
                font-size: 17px; color: #fff; margin: 0 0 8px;
            }
            .why-pick {
                background: linear-gradient(135deg, #C5A55A, #E8D5A3);
                color: #0a0a0a; padding: 4px 12px; border-radius: 8px;
                font-size: 13px; font-weight: 700;
            }
            .why-reason {
                color: #bbb; font-size: 13.5px; line-height: 1.65; margin: 0 0 18px;
            }
            .why-grid {
                display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
                gap: 14px; margin-bottom: 18px;
            }
            .why-pros, .why-cons {
                padding: 16px 18px; border-radius: 10px; font-size: 13px;
            }
            .why-pros {
                background: rgba(34,197,94,0.06); border: 1px solid rgba(34,197,94,0.2);
            }
            .why-pros h4 { color: #22c55e; font-size: 13px; margin: 0 0 10px; }
            .why-cons {
                background: rgba(239,68,68,0.06); border: 1px solid rgba(239,68,68,0.2);
            }
            .why-cons h4 { color: #ef4444; font-size: 13px; margin: 0 0 10px; }
            .why-pros ul, .why-cons ul { padding-left: 16px; color: #ccc; line-height: 1.6; margin: 0; }
            .why-pros ul li, .why-cons ul li { margin: 4px 0; }
            .why-compare-h {
                color: #C5A55A; font-size: 13px; text-transform: uppercase;
                letter-spacing: 1px; margin: 18px 0 10px;
            }
            .why-table {
                width: 100%; border-collapse: collapse;
                font-size: 12.5px;
                background: #0f0f0f; border-radius: 8px; overflow: hidden;
                border: 1px solid #2a2a2a;
            }
            .why-table th {
                background: #1f1f1f; color: #aaa;
                padding: 10px 12px; text-align: left;
                font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px;
                border-bottom: 1px solid #2a2a2a;
            }
            .why-table td {
                padding: 10px 12px; color: #ddd;
                border-bottom: 1px solid #1a1a1a;
            }
            .why-table tr:last-child td { border-bottom: none; }
        </style>
    </div>

    {{-- ━━━━━━━━━━━━━━━━━━━ §11 DEPLOYMENT ━━━━━━━━━━━━━━━━━━━ --}}
    <div class="docs-section" id="sec-deploy">
        <div class="docs-section-title">
            <span class="num-pill">11</span>
            <h2>Deployment & Operations</h2>
        </div>
        <p class="docs-section-desc">
            Stack production yang direkomendasikan + monitoring.
        </p>

        <div class="docs-card">
            <h3 style="font-size:15px;color:#fff;margin-bottom:12px">Recommended Production Stack</h3>
            <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:18px">
                <span class="tech-pill blue">Nginx 1.24+</span>
                <span class="tech-pill blue">PHP-FPM 8.2</span>
                <span class="tech-pill blue">Laravel 12</span>
                <span class="tech-pill gold">MySQL 8</span>
                <span class="tech-pill gold">Redis 7</span>
                <span class="tech-pill green">FFmpeg 6</span>
                <span class="tech-pill purple">Bunny CDN</span>
                <span class="tech-pill purple">Pusher</span>
                <span class="tech-pill purple">Midtrans Snap</span>
                <span class="tech-pill red">Cloudflare</span>
                <span class="tech-pill red">Supervisor (queue workers)</span>
                <span class="tech-pill">Composer 2.x</span>
                <span class="tech-pill">Node 20 + Vite</span>
            </div>

            <h3 style="font-size:15px;color:#fff;margin:24px 0 12px">Monitoring & Health</h3>
            <ul style="padding-left:20px;color:#bbb;line-height:1.8;font-size:13px">
                <li><code>/healthz</code> liveness probe — load balancer cek tiap 10s</li>
                <li><code>/healthz/ready</code> readiness probe — cek DB, Redis, queue depth</li>
                <li><code>/admin/health</code> dashboard 25+ check kategori (system, db, queue, AI, storage, security)</li>
                <li><code>flik:doctor</code> CLI 25+ check (jalan hourly via scheduler)</li>
                <li><code>/admin/performance</code> dashboard — p95 latency per AI task, queue lag</li>
                <li><code>/admin/queues</code> Horizon-lite dashboard — failed jobs retry/delete</li>
            </ul>

            <h3 style="font-size:15px;color:#fff;margin:24px 0 12px">CI/CD (GitHub Actions)</h3>
            <ul style="padding-left:20px;color:#bbb;line-height:1.8;font-size:13px">
                <li><strong>static-analysis.yml</strong> — PHPStan + Psalm + Pint pada setiap PR</li>
                <li><strong>dep-security.yml</strong> — composer audit + npm audit + Trivy weekly</li>
                <li><strong>codeql.yml</strong> — CodeQL semantic analysis</li>
                <li><strong>dast.yml</strong> — OWASP ZAP scan staging URL weekly</li>
                <li><strong>dependabot.yml</strong> — auto-PR untuk dep updates (composer + npm + actions)</li>
            </ul>
        </div>
    </div>

    {{-- ━━━━━━━━━━━━━━━━━━━ FOOTER ━━━━━━━━━━━━━━━━━━━ --}}
    <div style="margin-top:48px;padding:24px;text-align:center;color:#666;font-size:12px;border-top:1px solid #2a2a2a">
        <p>Document ini auto-generated dari live system. Update terakhir: {{ now()->format('d M Y H:i') }}</p>
        <p style="margin-top:6px">Detail teknis per domain: lihat <code>docs/audit/01..20-*.md</code> + <code>docs/SATISFACTION_REPORT.md</code></p>
    </div>

</x-admin.layout>
