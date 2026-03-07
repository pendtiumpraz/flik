# 🎬 FLiK OTT Platform — Master Plan

> **FLiK — Rumah Sinema Indonesia**
> Platform streaming film premium Indonesia dengan desain emas mewah, content protection, gamification, dan pengalaman menonton layaknya bioskop digital.

---

## 📊 Analisis Kondisi Saat Ini vs Target

### State Saat Ini ❌

| Aspek | Status | Detail |
|-------|--------|--------|
| **Branding** | ❌ Masih "Velflix" | Perlu rename ke FLiK, ganti warna merah → emas |
| **Database** | ✅ Partial | Genre, Movie, Cast sudah ada, tapi belum lengkap |
| **Admin Panel** | ❌ Kosong | Hanya tulisan "This is Admin Page" |
| **Video Player** | ❌ Tidak ada | Hanya embed YouTube, bukan player sendiri |
| **Storage** | ❌ Tidak ada | Poster pakai URL TMDB, belum ada video storage |
| **Auth** | ✅ Basic | Login/Register ada, tapi belum ada profil |
| **Subscription** | ❌ Tidak ada | Belum ada sistem langganan/paket |
| **Content Protection** | ❌ Tidak ada | Konten bisa screenshot/download/record |
| **Gamification** | ❌ Tidak ada | Belum ada coin, achievement, dll |
| **Dark/Light Mode** | ❌ Tidak ada | Fixed dark theme saja |
| **Responsive** | ❌ Partial | Banyak komponen belum responsive |
| **SEO/PWA** | ❌ Tidak ada | Belum ada meta tags, manifest, dll |

### Target ✅ Platform OTT Sempurna

Setara dengan: **Netflix + Disney+ + Vidio** fitur-fitur terbaik, dengan keunikan **Gamification** dan branding **gold premium**.

---

## 🏗️ Arsitektur Sistem

### Tech Stack

| Layer | Teknologi |
|-------|-----------|
| **Backend** | Laravel 9 (upgrade ke 11 nanti), PHP 8.2 |
| **Frontend** | Blade Components + Alpine.js + Tailwind CSS |
| **Database** | MySQL 8 |
| **Video Player** | Video.js 8 + videojs-contrib-quality-levels + videojs-hls-quality-selector |
| **Storage** | Laravel Storage (local → S3 upgrade path) |
| **Cache** | Redis (production) / File (dev) |
| **Queue** | Laravel Queue (video processing jobs) |
| **Real-time** | Laravel Echo + Pusher/Soketi |
| **Image Processing** | Intervention Image / Spatie Media Library |
| **Video Processing** | FFmpeg (HLS transcoding) |

### Deployment Architecture (Future)
```
┌─────────────┐     ┌──────────┐     ┌─────────────┐
│   Cloudflare │────▶│  Nginx   │────▶│   Laravel    │
│   CDN + WAF  │     │  Proxy   │     │   App Server │
└─────────────┘     └──────────┘     └──────┬──────┘
                                             │
                    ┌────────────────────────┼────────────────────────┐
                    ▼                        ▼                        ▼
             ┌──────────┐           ┌──────────────┐         ┌──────────┐
             │  MySQL   │           │ S3/R2 Storage │         │  Redis   │
             │ Database │           │ (Videos/Img)  │         │  Cache   │
             └──────────┘           └──────────────┘         └──────────┘
```

---

## 📐 ERD (Entity Relationship Diagram)

```mermaid
erDiagram
    USERS {
        bigint id PK
        string username UK
        string name
        string email UK
        string password
        string avatar
        string phone
        enum role "user,admin,content_manager"
        boolean is_admin
        boolean is_active
        json preferences "language,subtitle_lang,quality"
        integer coins
        enum subscription_tier "free,basic,premium,vip"
        datetime subscription_expires_at
        string provider_id
        string provider_name
        datetime email_verified_at
        timestamps
    }

    GENRES {
        bigint id PK
        string name
        string slug UK
        string icon
        string color
        integer sort_order
        boolean is_active
        timestamps
    }

    MOVIES {
        bigint id PK
        string title
        string original_title
        string slug UK
        text overview
        text synopsis
        string poster_path
        string backdrop_path
        string trailer_url
        string video_path
        string video_url_hls
        integer duration_minutes
        date release_date
        decimal vote_average
        integer vote_count
        decimal popularity
        string content_rating "SU,R13,D17,R21"
        string country
        string language
        enum type "movie,series,short_film,documentary"
        enum status "draft,published,scheduled,archived"
        boolean is_featured
        boolean is_popular
        boolean is_trending
        boolean is_premium
        boolean is_free
        integer view_count
        integer coin_cost "for rent/buy model"
        datetime published_at
        datetime scheduled_at
        bigint uploaded_by FK
        timestamps
        softDeletes
    }

    SERIES {
        bigint id PK
        bigint movie_id FK
        integer season_number
        string title
        text overview
        string poster_path
        timestamps
    }

    EPISODES {
        bigint id PK
        bigint series_id FK
        integer episode_number
        string title
        text overview
        string thumbnail_path
        string video_path
        string video_url_hls
        integer duration_minutes
        boolean is_free
        integer coin_cost
        timestamps
    }

    CASTS {
        bigint id PK
        string name
        string slug
        string profile_path
        text biography
        date birth_date
        string birth_place
        timestamps
    }

    CAST_MOVIE {
        bigint id PK
        bigint cast_id FK
        bigint movie_id FK
        string character
        enum role_type "actor,director,producer,writer"
        integer sort_order
    }

    GENRE_MOVIE {
        bigint id PK
        bigint genre_id FK
        bigint movie_id FK
    }

    WATCHLISTS {
        bigint id PK
        bigint user_id FK
        bigint movie_id FK
        timestamps
    }

    WATCH_HISTORIES {
        bigint id PK
        bigint user_id FK
        bigint movie_id FK
        bigint episode_id FK "nullable"
        integer progress_seconds
        integer total_duration_seconds
        boolean is_completed
        datetime last_watched_at
        timestamps
    }

    RATINGS {
        bigint id PK
        bigint user_id FK
        bigint movie_id FK
        integer rating "1-10"
        text review
        boolean is_approved
        timestamps
    }

    SUBSCRIPTIONS {
        bigint id PK
        bigint user_id FK
        bigint plan_id FK
        enum status "active,expired,cancelled,paused"
        datetime starts_at
        datetime expires_at
        string payment_method
        string transaction_id
        timestamps
    }

    SUBSCRIPTION_PLANS {
        bigint id PK
        string name "Free,Basic,Premium,VIP"
        string slug
        text description
        decimal price_monthly
        decimal price_yearly
        integer max_devices
        string video_quality "SD,HD,FHD,4K"
        boolean can_download
        boolean ad_free
        integer monthly_coins
        json features
        boolean is_active
        timestamps
    }

    COINS {
        bigint id PK
        bigint user_id FK
        integer amount
        enum type "earned,spent,purchased,bonus,refund"
        string description
        bigint reference_id "nullable - movie_id, achievement_id, etc"
        string reference_type "nullable"
        timestamps
    }

    ACHIEVEMENTS {
        bigint id PK
        string name
        string slug UK
        text description
        string icon
        string badge_color
        enum category "watching,social,collection,streak"
        json criteria "JSON rules for earning"
        integer coin_reward
        integer xp_reward
        timestamps
    }

    USER_ACHIEVEMENTS {
        bigint id PK
        bigint user_id FK
        bigint achievement_id FK
        datetime earned_at
        boolean is_claimed
    }

    USER_LEVELS {
        bigint id PK
        bigint user_id FK
        integer level
        integer xp_current
        integer xp_next_level
        string rank_title "Penonton Baru,Cinephile,Film Buff,Master Critic"
        timestamps
    }

    DAILY_REWARDS {
        bigint id PK
        bigint user_id FK
        date reward_date
        integer day_streak
        integer coins_earned
        string bonus_item "nullable"
        timestamps
    }

    COMMENTS {
        bigint id PK
        bigint user_id FK
        bigint movie_id FK
        bigint parent_id FK "nullable, for replies"
        text body
        integer likes_count
        boolean is_approved
        boolean is_pinned
        timestamps
        softDeletes
    }

    NOTIFICATIONS {
        bigint id PK
        bigint user_id FK
        string type
        string title
        text message
        json data
        boolean is_read
        datetime read_at
        timestamps
    }

    BANNERS {
        bigint id PK
        string title
        text description
        string image_path
        string link_url
        enum position "hero,sidebar,popup,footer"
        integer sort_order
        boolean is_active
        datetime starts_at
        datetime expires_at
        timestamps
    }

    CONTENT_REPORTS {
        bigint id PK
        bigint user_id FK
        bigint movie_id FK
        enum reason "broken_video,wrong_subtitle,inappropriate,copyright"
        text description
        enum status "pending,reviewed,resolved"
        timestamps
    }

    USERS ||--o{ WATCHLISTS : "has"
    USERS ||--o{ WATCH_HISTORIES : "has"
    USERS ||--o{ RATINGS : "writes"
    USERS ||--o{ SUBSCRIPTIONS : "subscribes"
    USERS ||--o{ COINS : "has"
    USERS ||--o{ USER_ACHIEVEMENTS : "earns"
    USERS ||--o{ USER_LEVELS : "has"
    USERS ||--o{ DAILY_REWARDS : "claims"
    USERS ||--o{ COMMENTS : "writes"
    USERS ||--o{ NOTIFICATIONS : "receives"
    USERS ||--o{ CONTENT_REPORTS : "reports"
    MOVIES ||--o{ CAST_MOVIE : "has"
    MOVIES ||--o{ GENRE_MOVIE : "categorized"
    MOVIES ||--o{ SERIES : "has seasons"
    MOVIES ||--o{ WATCHLISTS : "in"
    MOVIES ||--o{ WATCH_HISTORIES : "watched"
    MOVIES ||--o{ RATINGS : "rated"
    MOVIES ||--o{ COMMENTS : "discussed"
    SERIES ||--o{ EPISODES : "has"
    CASTS ||--o{ CAST_MOVIE : "acts"
    GENRES ||--o{ GENRE_MOVIE : "tags"
    SUBSCRIPTION_PLANS ||--o{ SUBSCRIPTIONS : "used"
    ACHIEVEMENTS ||--o{ USER_ACHIEVEMENTS : "awarded"
```

---

## 🎨 UI/UX Design System

### Color Palette — Gold Premium Theme

```
┌─────────────────────────────────────────────────────────┐
│  FLIK DESIGN TOKENS                                     │
├─────────────────────────────────────────────────────────┤
│                                                         │
│  🌑 DARK MODE (Default)                                │
│  ─────────────────────                                  │
│  Background Primary:    #0A0A0A (deep black)            │
│  Background Secondary:  #141414 (card bg)               │
│  Background Tertiary:   #1E1E1E (elevated surfaces)     │
│  Surface:               #252525 (inputs, modals)        │
│                                                         │
│  🌕 LIGHT MODE                                          │
│  ─────────────────                                      │
│  Background Primary:    #FAFAFA (warm white)            │
│  Background Secondary:  #F0F0F0                         │
│  Background Tertiary:   #E8E8E8                         │
│  Surface:               #FFFFFF                         │
│                                                         │
│  ✨ GOLD ACCENT (Both Modes)                            │
│  ─────────────────────────                              │
│  Gold Primary:         #C5A55A (main gold)              │
│  Gold Light:           #D4B96E (hover states)           │
│  Gold Dark:            #8B7340 (pressed states)         │
│  Gold Gradient:        linear(135deg, #F0D78C → #8B6914)│
│  Gold Subtle:          #C5A55A1A (10% opacity bg)       │
│                                                         │
│  📝 TEXT COLORS                                         │
│  ──────────────                                         │
│  Dark Mode Primary:    #F5F5F5                          │
│  Dark Mode Secondary:  #A0A0A0                          │
│  Dark Mode Muted:      #666666                          │
│  Light Mode Primary:   #1A1A1A                          │
│  Light Mode Secondary: #555555                          │
│  Light Mode Muted:     #999999                          │
│                                                         │
│  🚦 STATUS COLORS                                       │
│  ───────────────                                        │
│  Success:   #22C55E                                     │
│  Warning:   #EAB308                                     │
│  Error:     #EF4444                                     │
│  Info:      #3B82F6                                     │
│  Premium:   linear(135deg, #F0D78C → #C5A55A)           │
│                                                         │
│  🔤 TYPOGRAPHY                                          │
│  ─────────────                                          │
│  Heading Font:  'Outfit', sans-serif                    │
│  Body Font:     'Inter', sans-serif                     │
│  Mono Font:     'JetBrains Mono', monospace             │
│                                                         │
│  📐 SPACING & RADIUS                                   │
│  ───────────────────                                    │
│  Border Radius SM: 6px                                  │
│  Border Radius MD: 10px                                 │
│  Border Radius LG: 16px                                 │
│  Border Radius XL: 24px                                 │
│  Card Shadow: 0 4px 24px rgba(0,0,0,0.3)               │
│  Gold Glow:   0 0 20px rgba(197,165,90,0.3)            │
│                                                         │
└─────────────────────────────────────────────────────────┘
```

### Responsive Breakpoints

| Breakpoint | Width | Target |
|-----------|-------|--------|
| `xs` | < 480px | Mobile Potrait |
| `sm` | 480-639px | Mobile Landscape |
| `md` | 640-767px | Tablet Portrait |
| `lg` | 768-1023px | Tablet Landscape |
| `xl` | 1024-1279px | Desktop |
| `2xl` | ≥ 1280px | Large Desktop |

---

## 📱 Wireframes & Page Flow

### Page Map (Sitemap)

```
FLiK Platform
├── 🏠 Landing Page (/)
│   ├── Hero Section (featured movie + CTA)
│   ├── Feature Highlights
│   ├── Pricing Plans
│   ├── FAQ
│   └── Footer
│
├── 🔐 Auth
│   ├── Login (/login)
│   ├── Register (/register)
│   ├── Forgot Password (/forgot-password)
│   ├── Reset Password (/reset-password)
│   └── Social Login (Google, etc.)
│
├── 🎬 Browse (/browse) [AUTH REQUIRED]
│   ├── Hero Carousel (featured/trending)
│   ├── Category Rows (Popular, Trending, by Genre...)
│   ├── Continue Watching Row
│   ├── My Watchlist Row
│   └── Recommended for You
│
├── 🔍 Search (/search)
│   ├── Real-time search
│   ├── Filter by Genre, Year, Rating
│   └── Sort options
│
├── 🎥 Movie Detail (/movie/{slug})
│   ├── Hero Backdrop + Trailer autoplay
│   ├── Info (rating, duration, genre, year)
│   ├── Cast & Crew
│   ├── Episodes (if series)
│   ├── Similar Movies
│   ├── Reviews & Comments
│   └── Share / Add to Watchlist
│
├── ▶️ Player (/watch/{slug}) [PREMIUM GATE]
│   ├── Video.js Player (fullscreen capable)
│   ├── Quality selector (SD/HD/FHD)
│   ├── Subtitle selector
│   ├── Progress save (auto-resume)
│   ├── Next episode button
│   └── Content protection layer
│
├── 👤 Profile (/profile)
│   ├── Account Info
│   ├── Edit Profile (avatar, name, preferences)
│   ├── Watch History
│   ├── My Watchlist
│   ├── My Reviews
│   ├── Subscription Status
│   └── Settings (theme, language, notification)
│
├── 🪙 Rewards (/rewards) [GAMIFICATION]
│   ├── Coin Balance & History
│   ├── Daily Check-in Calendar
│   ├── Achievement Gallery
│   ├── Level & XP Progress
│   ├── Coin Store (buy coins)
│   └── Leaderboard
│
├── 💳 Subscription (/subscription)
│   ├── Plan Comparison
│   ├── Payment Flow
│   ├── Transaction History
│   └── Cancel/Change Plan
│
├── ⚙️ Admin Panel (/admin) [ADMIN ONLY]
│   ├── Dashboard (stats overview)
│   ├── Movie Management (CRUD)
│   ├── Series & Episode Management
│   ├── Genre Management
│   ├── Cast Management
│   ├── User Management
│   ├── Subscription Management
│   ├── Banner Management
│   ├── Content Reports
│   ├── Achievement Config
│   ├── Analytics & Reports
│   └── Settings (site config)
│
└── 📄 Static Pages
    ├── About Us
    ├── Terms of Service
    ├── Privacy Policy
    ├── Contact
    └── Help Center
```

### Wireframe: Browse Page (Dark Mode)

```
┌──────────────────────────────────────────────────────────────────┐
│ ┌─ NAVBAR ─────────────────────────────────────────────────────┐ │
│ │ [FLiK Logo]  Home  Movies  Series  My List    🔍  🪙124  👤│ │
│ └──────────────────────────────────────────────────────────────┘ │
│                                                                  │
│ ┌─ HERO CAROUSEL ──────────────────────────────────────────────┐ │
│ │                                                              │ │
│ │   ┌────────────┐                                             │ │
│ │   │            │  ★ 8.5   |  2024  |  Action, Drama         │ │
│ │   │  FEATURED  │                                             │ │
│ │   │  BACKDROP  │  "The most awaited film of the year..."     │ │
│ │   │  IMAGE     │                                             │ │
│ │   │            │  [▶ Play Now]   [＋ My List]   [ℹ More]    │ │
│ │   └────────────┘                                             │ │
│ │            ● ○ ○ ○ ○  (carousel dots)                        │ │
│ └──────────────────────────────────────────────────────────────┘ │
│                                                                  │
│  ▶ Continue Watching ›                                           │
│  ┌─────┐ ┌─────┐ ┌─────┐ ┌─────┐ ┌─────┐ ┌─────┐             │
│  │     │ │     │ │     │ │     │ │     │ │     │    →          │
│  │ 45% │ │ 72% │ │ 10% │ │     │ │     │ │     │             │
│  │ ███░│ │████░│ │█░░░░│ │     │ │     │ │     │             │
│  └─────┘ └─────┘ └─────┘ └─────┘ └─────┘ └─────┘             │
│                                                                  │
│  🔥 Trending on FLiK ›                                          │
│  ┌─────┐ ┌─────┐ ┌─────┐ ┌─────┐ ┌─────┐ ┌─────┐             │
│  │  1  │ │  2  │ │  3  │ │  4  │ │  5  │ │  6  │    →        │
│  │     │ │     │ │     │ │     │ │     │ │     │             │
│  │     │ │     │ │     │ │     │ │     │ │     │             │
│  └─────┘ └─────┘ └─────┘ └─────┘ └─────┘ └─────┘             │
│                                                                  │
│  ⭐ Popular Movies ›                                             │
│  ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐              │
│  │ Poster  │ │ Poster  │ │ Poster  │ │ Poster  │    →         │
│  │  Card   │ │  Card   │ │  Card   │ │  Card   │              │
│  │ +hover  │ │         │ │   🪙50  │ │         │              │
│  │ expand  │ │         │ │ PREMIUM │ │         │              │
│  └─────────┘ └─────────┘ └─────────┘ └─────────┘              │
│                                                                  │
│  🎭 Comedy ›  / 💀 Horror ›  / 🎬 Action ›  / etc.             │
│  (same card row pattern...)                                      │
│                                                                  │
│ ┌─ FOOTER ─────────────────────────────────────────────────────┐ │
│ │ [FLiK Logo]  About | Terms | Privacy | Help | Contact       │ │
│ │ © 2026 FLiK Rumah Sinema Indonesia                           │ │
│ └──────────────────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────────┘
```

### Wireframe: Video Player Page

```
┌──────────────────────────────────────────────────────────────────┐
│ ┌─ VIDEO.JS PLAYER (16:9) ─────────────────────────────────────┐│
│ │                                                              ││
│ │  ← Back to Browse                                           ││
│ │                                                              ││
│ │                                                              ││
│ │                    ▶  (big play button)                      ││
│ │                                                              ││
│ │                                                              ││
│ │ ┌───────────────────────────────────────────────────────┐    ││
│ │ │ ▶ ██████████░░░░░░░░ 45:23 / 1:52:00   🔊  📺  ⚙ ⛶│    ││
│ │ └───────────────────────────────────────────────────────┘    ││
│ │                           ⚙ Quality: [Auto|SD|HD|FHD]       ││
│ │                           📝 Subtitle: [Off|ID|EN]          ││
│ └──────────────────────────────────────────────────────────────┘│
│                                                                  │
│  ⚠️ CONTENT PROTECTION OVERLAY (invisible layer):               │
│  - CSS: user-select: none                                       │
│  - JS: disable right-click, PrintScreen, DevTools               │
│  - Watermark: semi-transparent user email overlay                │
│  - DRM: if available (Widevine / FairPlay)                      │
│                                                                  │
│ ┌─ MOVIE INFO ─────────────────────────────────────────────────┐│
│ │ Movie Title                    ★ 8.5  |  2024  |  148 min   ││
│ │ "Synopsis text here..."        Genre: Action, Drama          ││
│ │ [▶ Continue] [＋ Watchlist] [👍] [👎] [↗ Share]             ││
│ └──────────────────────────────────────────────────────────────┘│
│                                                                  │
│  Up Next / More Episodes ›                                       │
│  ┌─────┐ ┌─────┐ ┌─────┐ ┌─────┐                              │
│  │ Ep2 │ │ Ep3 │ │ Ep4 │ │ Ep5 │                              │
│  └─────┘ └─────┘ └─────┘ └─────┘                              │
└──────────────────────────────────────────────────────────────────┘
```

### Wireframe: Admin Dashboard

```
┌──────────────────────────────────────────────────────────────────┐
│ ┌─ ADMIN SIDEBAR ──┐ ┌─ MAIN CONTENT ─────────────────────────┐│
│ │                  │ │                                         ││
│ │  [FLiK Logo]     │ │  📊 Dashboard                          ││
│ │                  │ │                                         ││
│ │  📊 Dashboard    │ │  ┌──────┐ ┌──────┐ ┌──────┐ ┌──────┐  ││
│ │  🎬 Movies       │ │  │Users │ │Movies│ │Views │ │Revenue│  ││
│ │  📺 Series       │ │  │1.2K  │ │ 348  │ │45.6K │ │$2.3K │  ││
│ │  🎭 Genres       │ │  │+12%↑ │ │+5%↑  │ │+23%↑ │ │+8%↑  │  ││
│ │  👥 Cast         │ │  └──────┘ └──────┘ └──────┘ └──────┘  ││
│ │  👤 Users        │ │                                         ││
│ │  💳 Subscriptions│ │  ┌─ Chart: Views Last 30 Days ────────┐││
│ │  🪙 Coins        │ │  │  📈                                │││
│ │  🏆 Achievements │ │  │      ╱╲    ╱╲                      │││
│ │  🖼️ Banners      │ │  │     ╱  ╲  ╱  ╲___╱╲               │││
│ │  📊 Analytics    │ │  │    ╱    ╲╱        ╲              │││
│ │  ⚠️ Reports      │ │  │___╱                               │││
│ │  ⚙️ Settings     │ │  └────────────────────────────────────┘││
│ │                  │ │                                         ││
│ │  [Logout]        │ │  ┌─ Recent Movies ────────────────────┐││
│ │                  │ │  │ Title      │ Status  │ Views │ Date │││
│ │                  │ │  │ Dune 2     │ ✅ Live │ 1.2K  │ Today│││
│ │                  │ │  │ Oppenheimer│ ✅ Live │  856  │ Yday │││
│ │                  │ │  │ New Movie  │ 📝 Draft│   -   │ Today│││
│ └──────────────────┘ │  └────────────────────────────────────┘││
│                      └─────────────────────────────────────────┘│
└──────────────────────────────────────────────────────────────────┘
```

### Wireframe: Mobile Responsive (Browse)

```
┌──────────────────┐
│ [≡] FLiK    🔍 👤│
├──────────────────┤
│ ┌──────────────┐ │
│ │              │ │
│ │   FEATURED   │ │
│ │   BACKDROP   │ │
│ │              │ │
│ │ Movie Title  │ │
│ │ [▶ Play] [+] │ │
│ └──────────────┘ │
│                  │
│ Continue Watching│
│ ┌───┐ ┌───┐ ┌──││
│ │   │ │   │ │  ││ →
│ │45%│ │72%│ │  ││
│ └───┘ └───┘ └──││
│                  │
│ 🔥 Trending     │
│ ┌───┐ ┌───┐ ┌──││
│ │ 1 │ │ 2 │ │ 3││ →
│ │   │ │   │ │  ││
│ └───┘ └───┘ └──││
│                  │
│ ⭐ Popular       │
│ ┌─────┐ ┌─────┐ │
│ │     │ │     │ │ →
│ │     │ │     │ │
│ └─────┘ └─────┘ │
│                  │
│ 🪙 42 coins      │
│ [Daily reward!]  │
│                  │
│ ── Footer ────── │
└──────────────────┘
```

---

## 🔒 Content Protection Strategy

### Layer 1: Frontend Protection

```javascript
// Anti-Screenshot
document.addEventListener('keydown', (e) => {
    // Block PrintScreen
    if (e.key === 'PrintScreen') { e.preventDefault(); }
    // Block Ctrl+Shift+S (Screenshot tools)  
    if (e.ctrlKey && e.shiftKey && e.key === 'S') { e.preventDefault(); }
    // Block Ctrl+P (Print)
    if (e.ctrlKey && e.key === 'p') { e.preventDefault(); }
    // Block F12 / DevTools
    if (e.key === 'F12') { e.preventDefault(); }
    if (e.ctrlKey && e.shiftKey && e.key === 'I') { e.preventDefault(); }
});

// Anti Right-Click
document.addEventListener('contextmenu', e => e.preventDefault());

// CSS-based protections
.video-container {
    user-select: none;
    -webkit-user-select: none;
    pointer-events: none; /* on overlay */
}
```

### Layer 2: Video Protection

| Technique | Implementation |
|-----------|---------------|
| **Dynamic Watermark** | Semi-transparent overlay with user email/ID during playback |
| **Encrypted HLS** | AES-128 encrypted segments, key rotation every 10 segments |
| **Token-based URL** | Signed URLs for video segments, expire after 5 minutes |
| **Widevine DRM** | (Future) Google Widevine L3 for Chrome/Android |
| **FairPlay DRM** | (Future) Apple FairPlay for Safari/iOS |
| **Session Binding** | Max 1 concurrent stream per account (basic), 3 (VIP) |

### Layer 3: Server-Side Protection

| Technique | Detail |
|-----------|--------|
| **Signed URLs** | Video segment URLs signed with HMAC, expire in 5 min |
| **Referrer Check** | Only allow requests from FLiK domain |
| **Rate Limiting** | Max 100 segment requests per minute per user |
| **IP Geo-check** | Optional geo-restriction per content |
| **Device Fingerprint** | Track devices to enforce concurrent stream limits |

---

## 🪙 Gamification System Design

### Coin Economy

```
┌─────────────────────────────────────────────┐
│           💰 COIN ECONOMY                   │
├─────────────────────────────────────────────┤
│                                             │
│  EARNING COINS:                             │
│  ─────────────                              │
│  📅 Daily Check-in:    5-50 coins/day       │
│     Day 1: 5   Day 2: 10  Day 3: 15        │
│     Day 4: 20  Day 5: 25  Day 6: 35        │
│     Day 7: 50  (cycle resets)               │
│                                             │
│  🎬 Watch Movie:       10 coins/completion  │
│  ⭐ Write Review:      15 coins/review      │
│  👥 Refer Friend:      100 coins/signup     │
│  🏆 Achievement:       varies (25-500)      │
│  💳 Purchase:          Rp 1.000 = 10 coins  │
│  📺 Subscription:      Monthly bonus coins  │
│     Basic: 50/mo  Premium: 150/mo  VIP: 300 │
│                                             │
│  SPENDING COINS:                            │
│  ──────────────                             │
│  🎬 Rent Movie:        50-200 coins         │
│  🎥 Buy Movie:         500-1000 coins       │
│  👤 Profile Badge:     100-500 coins        │
│  🎨 Custom Theme:      200 coins            │
│  🎁 Gift to Friend:    any amount           │
│  ⏫ Early Access:      300 coins/movie      │
│                                             │
└─────────────────────────────────────────────┘
```

### Achievement System

| Category | Achievement | Criteria | Reward |
|----------|-------------|----------|--------|
| 🎬 Watching | First Watch | Watch 1 movie | 25 coins |
| 🎬 Watching | Movie Marathon | Watch 5 movies in 1 day | 100 coins |
| 🎬 Watching | Binge Watcher | Watch 10 episodes of a series | 75 coins |
| 🎬 Watching | Century Club | Watch 100 movies total | 500 coins |
| ⭐ Social | First Review | Write first review | 15 coins |
| ⭐ Social | Top Critic | 50+ reviews | 300 coins |
| ⭐ Social | Community Star | Get 100 likes on reviews | 200 coins |
| 📅 Streak | Week Warrior | 7-day login streak | 50 coins |
| 📅 Streak | Monthly Hero | 30-day login streak | 200 coins |
| 📅 Streak | Annual Legend | 365-day login streak | 2000 coins |
| 🎭 Collection | Genre Explorer | Watch movies from 10 genres | 150 coins |
| 🎭 Collection | Indonesia Pride | Watch 20 Indonesian films | 200 coins |
| 💳 Premium | VIP Member | Subscribe to VIP plan | 500 coins |

### Level & XP System

```
Level 1  (0-100 XP)      → Penonton Baru       🟤
Level 2  (100-300 XP)    → Penikmat Film        🟠
Level 3  (300-600 XP)    → Cinephile            🟡
Level 4  (600-1000 XP)   → Film Buff            🟢
Level 5  (1000-1500 XP)  → Kritikus Handal      🔵
Level 6  (1500-2500 XP)  → Master Sinema        🟣
Level 7  (2500-4000 XP)  → Grand Cinephile      🔴
Level 8  (4000-6000 XP)  → Legendary Viewer     ⭐
Level 9  (6000-10000 XP) → FLiK Ambassador      💎
Level 10 (10000+ XP)     → Hall of Fame         👑

XP Sources:
- Watch movie: 10 XP
- Complete movie: 25 XP  
- Write review: 15 XP
- Daily login: 5 XP
- Achievement earned: 50 XP
- Refer friend: 100 XP
```

---

## 🛠️ Implementation Phases

### Phase 1: Foundation & Rebranding 🏗️ (Week 1-2)

> [!IMPORTANT]
> Ini adalah fondasi. Semua harus benar sebelum lanjut.

| # | Task | Priority | Estimate |
|---|------|----------|----------|
| 1.1 | **Rebrand Velflix → FLiK** — rename semua text, title, class | 🔴 High | 2h |
| 1.2 | **Implement Gold Design System** — Tailwind config, CSS variables, dark/light toggle | 🔴 High | 4h |
| 1.3 | **Set favicon & logo** — update layout, meta tags | 🔴 High | 1h |
| 1.4 | **Responsive layout overhaul** — navbar, hero, cards, footer | 🔴 High | 6h |
| 1.5 | **Google Fonts setup** — Outfit + Inter | 🟡 Med | 30m |
| 1.6 | **Database migrations** — semua tabel baru dari ERD | 🔴 High | 4h |
| 1.7 | **Models & Relations** — Eloquent models, scopes, accessors | 🔴 High | 4h |
| 1.8 | **Seeders** — dummy data lengkap | 🟡 Med | 3h |

### Phase 2: Admin Panel CRUD 📋 (Week 2-3)

| # | Task | Priority | Estimate |
|---|------|----------|----------|
| 2.1 | **Admin layout** — sidebar, header, breadcrumb | 🔴 High | 4h |
| 2.2 | **Dashboard** — stats cards, charts (Chart.js) | 🟡 Med | 4h |
| 2.3 | **Movie CRUD** — form + table + upload poster/video | 🔴 High | 8h |
| 2.4 | **Series & Episode CRUD** | 🔴 High | 6h |
| 2.5 | **Genre CRUD** | 🟡 Med | 2h |
| 2.6 | **Cast CRUD** | 🟡 Med | 2h |
| 2.7 | **User Management** | 🟡 Med | 3h |
| 2.8 | **Banner Management** | 🟢 Low | 2h |

### Phase 3: Video Player & Storage 🎥 (Week 3-4)

| # | Task | Priority | Estimate |
|---|------|----------|----------|
| 3.1 | **Install Video.js** — npm + blade integration | 🔴 High | 2h |
| 3.2 | **Custom player skin** — gold theme, FLiK branding | 🔴 High | 4h |
| 3.3 | **Quality selector** — SD/HD/FHD switching | 🔴 High | 3h |
| 3.4 | **Progress tracking** — auto-save, resume playback | 🔴 High | 3h |
| 3.5 | **Laravel Storage setup** — disk config, upload routes | 🔴 High | 3h |
| 3.6 | **Video upload in admin** — chunked upload, progress bar | 🔴 High | 6h |
| 3.7 | **FFmpeg HLS transcoding** — queue job, multi-quality | 🟡 Med | 8h |
| 3.8 | **S3 adapter** — storage abstraction for future upgrade | 🟡 Med | 2h |

### Phase 4: Content Protection 🔒 (Week 4-5)

| # | Task | Priority | Estimate |
|---|------|----------|----------|
| 4.1 | **Anti-screenshot JS** — disable PrintScreen, DevTools | 🔴 High | 2h |
| 4.2 | **Anti right-click** — context menu disable | 🔴 High | 30m |
| 4.3 | **Dynamic watermark** — user email overlay on player | 🔴 High | 3h |
| 4.4 | **CSS protections** — no select, no drag | 🔴 High | 1h |
| 4.5 | **Signed video URLs** — HMAC expiring links | 🔴 High | 4h |
| 4.6 | **Session concurrency** — limit simultaneous streams | 🟡 Med | 4h |
| 4.7 | **HLS AES-128 encryption** — encrypt video segments | 🟡 Med | 6h |
| 4.8 | **Screen capture detection** — MediaDevices API check | 🟡 Med | 2h |

### Phase 5: User Features 👤 (Week 5-6)

| # | Task | Priority | Estimate |
|---|------|----------|----------|
| 5.1 | **User profile page** — avatar, preferences, edit | 🔴 High | 4h |
| 5.2 | **Watchlist** — add/remove, dedicated page | 🔴 High | 3h |
| 5.3 | **Watch history** — continue watching, history page | 🔴 High | 4h |
| 5.4 | **Rating & reviews** — star rating, comment system | 🟡 Med | 4h |
| 5.5 | **Search enhancement** — live search, filters, suggestions | 🟡 Med | 4h |
| 5.6 | **Notification system** — bell icon, notification center | 🟡 Med | 4h |
| 5.7 | **Share functionality** — social sharing | 🟢 Low | 2h |

### Phase 6: Subscription & Payment 💳 (Week 6-7)

| # | Task | Priority | Estimate |
|---|------|----------|----------|
| 6.1 | **Subscription plans** — model, admin config | 🔴 High | 3h |
| 6.2 | **Plan comparison page** — pricing UI | 🔴 High | 4h |
| 6.3 | **Payment gateway integration** — Midtrans/Xendit | 🔴 High | 8h |
| 6.4 | **Subscription middleware** — premium content gating | 🔴 High | 3h |
| 6.5 | **Subscription lifecycle** — activate, expire, renewal | 🟡 Med | 4h |
| 6.6 | **Invoice & receipt** — PDF generation | 🟢 Low | 3h |

### Phase 7: Gamification 🎮 (Week 7-8)

| # | Task | Priority | Estimate |
|---|------|----------|----------|
| 7.1 | **Coin system** — earn, spend, transaction log | 🔴 High | 6h |
| 7.2 | **Daily check-in** — calendar UI, streak tracking | 🔴 High | 4h |
| 7.3 | **Achievement system** — criteria evaluation, unlock | 🔴 High | 6h |
| 7.4 | **Level & XP** — progress bar, rank titles | 🟡 Med | 4h |
| 7.5 | **Rewards page UI** — coin store, achievements gallery | 🟡 Med | 6h |
| 7.6 | **Leaderboard** — top users, monthly reset | 🟢 Low | 3h |
| 7.7 | **Coin purchase flow** — buy coins with real money | 🟡 Med | 4h |

### Phase 8: Polish & Optimization ✨ (Week 8-9)

| # | Task | Priority | Estimate |
|---|------|----------|----------|
| 8.1 | **Micro-animations** — page transitions, hover effects | 🟡 Med | 4h |
| 8.2 | **Loading states** — skeleton screens, lazy loading | 🟡 Med | 3h |
| 8.3 | **Error pages** — custom 404, 403, 500 | 🟡 Med | 2h |
| 8.4 | **SEO optimization** — meta tags, Open Graph, sitemap | 🟡 Med | 3h |
| 8.5 | **PWA setup** — manifest, service worker | 🟡 Med | 4h |
| 8.6 | **Performance** — image optimization, lazy images, cache | 🟡 Med | 4h |
| 8.7 | **Accessibility** — keyboard nav, ARIA labels, contrast | 🟢 Low | 3h |
| 8.8 | **Testing** — feature tests, browser tests | 🟢 Low | 8h |

---

## 📁 Target File Structure

```
app/
├── Console/
│   └── Commands/
│       ├── TranscodeVideo.php
│       └── ProcessDailyRewards.php
├── Http/
│   ├── Controllers/
│   │   ├── Admin/
│   │   │   ├── DashboardController.php
│   │   │   ├── MovieController.php
│   │   │   ├── SeriesController.php
│   │   │   ├── EpisodeController.php
│   │   │   ├── GenreController.php
│   │   │   ├── CastController.php
│   │   │   ├── UserController.php
│   │   │   ├── SubscriptionController.php
│   │   │   ├── BannerController.php
│   │   │   ├── AchievementController.php
│   │   │   ├── AnalyticsController.php
│   │   │   └── SettingsController.php
│   │   ├── Auth/
│   │   │   ├── LoginController.php
│   │   │   ├── RegisterController.php
│   │   │   └── SocialAuthController.php
│   │   ├── BrowseController.php
│   │   ├── MovieDetailController.php
│   │   ├── PlayerController.php
│   │   ├── ProfileController.php
│   │   ├── WatchlistController.php
│   │   ├── SearchController.php
│   │   ├── SubscriptionController.php
│   │   ├── RewardController.php
│   │   └── NotificationController.php
│   ├── Livewire/
│   │   ├── SearchFlik.php
│   │   ├── MovieRating.php
│   │   ├── CommentSection.php
│   │   ├── WatchlistToggle.php
│   │   └── DailyCheckin.php
│   └── Middleware/
│       ├── CheckSubscription.php
│       ├── TrackWatchProgress.php
│       ├── ContentProtection.php
│       └── AdminOnly.php
├── Models/
│   ├── User.php
│   ├── Movie.php
│   ├── Series.php
│   ├── Episode.php
│   ├── Genre.php
│   ├── Cast.php
│   ├── Watchlist.php
│   ├── WatchHistory.php
│   ├── Rating.php
│   ├── Comment.php
│   ├── Subscription.php
│   ├── SubscriptionPlan.php
│   ├── Coin.php
│   ├── Achievement.php
│   ├── UserAchievement.php
│   ├── UserLevel.php
│   ├── DailyReward.php
│   ├── Banner.php
│   ├── Notification.php
│   └── ContentReport.php
├── Services/
│   ├── VideoService.php
│   ├── CoinService.php
│   ├── AchievementService.php
│   ├── SubscriptionService.php
│   ├── ContentProtectionService.php
│   └── StorageService.php
├── Jobs/
│   ├── TranscodeVideoJob.php
│   ├── ProcessAchievementsJob.php
│   └── ExpireSubscriptionsJob.php
├── Events/
│   ├── MovieWatched.php
│   ├── AchievementUnlocked.php
│   └── CoinEarned.php
└── Observers/
    ├── MovieObserver.php
    └── UserObserver.php

resources/views/
├── components/
│   ├── layout/
│   │   ├── app.blade.php (main layout)
│   │   ├── admin.blade.php (admin layout)
│   │   ├── navbar.blade.php
│   │   ├── footer.blade.php
│   │   ├── mobile-nav.blade.php
│   │   └── theme-toggle.blade.php
│   ├── ui/
│   │   ├── card.blade.php
│   │   ├── button.blade.php
│   │   ├── modal.blade.php
│   │   ├── badge.blade.php
│   │   ├── skeleton.blade.php
│   │   └── carousel.blade.php
│   ├── movie/
│   │   ├── card.blade.php (poster card)
│   │   ├── hero.blade.php (hero banner)
│   │   ├── row.blade.php (horizontal scroll)
│   │   ├── detail.blade.php
│   │   └── player.blade.php
│   └── gamification/
│       ├── coin-display.blade.php
│       ├── achievement-card.blade.php
│       ├── level-progress.blade.php
│       └── daily-checkin.blade.php
├── pages/
│   ├── landing.blade.php
│   ├── browse.blade.php
│   ├── movie-detail.blade.php
│   ├── player.blade.php
│   ├── search.blade.php
│   ├── profile.blade.php
│   ├── watchlist.blade.php
│   ├── history.blade.php
│   ├── rewards.blade.php
│   ├── subscription.blade.php
│   └── auth/
│       ├── login.blade.php
│       └── register.blade.php
├── admin/
│   ├── dashboard.blade.php
│   ├── movies/
│   │   ├── index.blade.php
│   │   ├── create.blade.php
│   │   └── edit.blade.php
│   ├── series/
│   ├── genres/
│   ├── casts/
│   ├── users/
│   ├── subscriptions/
│   ├── banners/
│   ├── achievements/
│   └── settings.blade.php
└── errors/
    ├── 404.blade.php
    ├── 403.blade.php
    └── 500.blade.php
```

---

## 🚀 Quick Start (Next Steps)

Untuk memulai implementasi, urutan eksekusi yang direkomendasikan:

```
Phase 1.1  → Rebrand (Velflix → FLiK)
Phase 1.2  → Gold Design System + Dark/Light Mode
Phase 1.3  → Favicon & Logo integration
Phase 1.6  → Database migrations (all tables)
Phase 1.7  → Models & Relations
Phase 2.1  → Admin Layout (sidebar)
Phase 2.3  → Movie CRUD (admin)
Phase 3.1  → Video.js Player
Phase 4.1  → Content Protection (basic)
Phase 5.2  → Watchlist
Phase 5.3  → Watch History
Phase 7.1  → Coin System
Phase 7.2  → Daily Check-in
```

> [!NOTE]
> Setiap phase bisa di-breakdown lebih detail lagi saat akan dieksekusi. Plan ini adalah **high-level blueprint** untuk FLiK OTT Platform.

> [!TIP]
> Untuk storage, saat ini gunakan `storage/app/public` Laravel. Nanti upgrade ke S3 hanya perlu ganti `FILESYSTEM_DISK=s3` di `.env` dan config di `config/filesystems.php`. Abstraksi Laravel Storage membuat migrasi ini sangat mudah.
