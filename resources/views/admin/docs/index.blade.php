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
        <ol>
            <li><a href="#sec-overview">Overview System</a></li>
            <li><a href="#sec-stack">Tech Stack 7-Layer</a></li>
            <li><a href="#sec-user-journey">User Journey</a></li>
            <li><a href="#sec-upload">Content Upload & Distribution</a></li>
            <li><a href="#sec-drm">DRM & Playback Flow</a></li>
            <li><a href="#sec-ai">AI Feature Stack</a></li>
            <li><a href="#sec-payment">Payment Flow</a></li>
            <li><a href="#sec-notif">Notification System</a></li>
            <li><a href="#sec-security">Security & Compliance</a></li>
            <li><a href="#sec-data">Data Flow Overview</a></li>
            <li><a href="#sec-deploy">Deployment & Ops</a></li>
        </ol>
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
