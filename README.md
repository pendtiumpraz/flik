<h1 align="center">
  <img src="public/img/flik-logo.png" alt="FLiK" width="200">
  <br>
  FLiK — Rumah Sinema Indonesia
</h1>

<p align="center">
  Platform streaming OTT premium Indonesia dengan koleksi film klasik & modern,
  AI assistant berbasis RAG, multi-tier subscription, dan gamification.
</p>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.2+-777BB4?style=flat-square&logo=php" alt="PHP">
  <img src="https://img.shields.io/badge/Laravel-12.x-FF2D20?style=flat-square&logo=laravel" alt="Laravel">
  <img src="https://img.shields.io/badge/Livewire-3.x-FB70A9?style=flat-square&logo=livewire" alt="Livewire">
  <img src="https://img.shields.io/badge/TailwindCSS-3.x-38B2AC?style=flat-square&logo=tailwind-css" alt="Tailwind">
  <img src="https://img.shields.io/badge/Alpine.js-3.x-8BC0D0?style=flat-square&logo=alpine.js" alt="Alpine">
  <img src="https://img.shields.io/badge/MySQL-8.0+-4479A1?style=flat-square&logo=mysql" alt="MySQL">
  <img src="https://img.shields.io/badge/License-MIT-green?style=flat-square" alt="License">
</p>

---

## Daftar Isi

1. [Highlights](#-highlights)
2. [Tech Stack](#-tech-stack)
3. [Quickstart](#-quickstart)
4. [Konfigurasi Environment](#%EF%B8%8F-konfigurasi-environment)
5. [Default Accounts (Seeded)](#-default-accounts-seeded)
6. [Halaman & Fitur](#-halaman--fitur)
7. [AI Assistant — RAG + Tool Calling](#-ai-assistant--rag--tool-calling)
8. [Role & Permission System](#-role--permission-system)
9. [Subscription Plans](#-subscription-plans)
10. [Multi-Thumbnail Rotation](#-multi-thumbnail-rotation)
11. [Movie URL — Slug Routing](#-movie-url--slug-routing)
12. [Admin Panel](#%EF%B8%8F-admin-panel)
13. [Asset Build & Deployment](#-asset-build--deployment)
14. [Architecture & Planning Docs](#-architecture--planning-docs)
15. [Testing](#-testing)
16. [Troubleshooting](#-troubleshooting)
17. [Contributing](#-contributing)
18. [License](#-license)

---

## ✨ Highlights

- 🎬 **Film Library** dengan slug-based URLs (`/movie/the-avengers`) — SEO friendly, no 404 dari AI hallucinations
- 🤖 **AI Chat Assistant** powered by DeepSeek V4 Flash (atau provider lain) dengan **RAG + agentic tool use** (Wikipedia + DuckDuckGo) — film catalog jadi knowledge base, AI bisa search web kalau perlu detail lebih
- 🛡️ **Multi-role auth** — Super Admin, Content Manager, Customer Support, Finance, User (auto-redirect setelah login)
- 💳 **Multi-tier subscription** (Free, Basic, Premium, Family) dengan **Bulanan/Tahunan toggle** (-20% annual) via Midtrans
- 🎮 **Gamification** — XP, levels, coins, achievements, daily check-in streak, leaderboard
- 🔍 **Quick Filter** — search, genre, country, year, type, status, language, sort (collapsible di mobile)
- 🎯 **Hover Preview Cards** (Netflix "Bob" style) — popover muncul di luar thumbnail saat hover
- 🏆 **Top 10** — tabs Today/Week/Month, real-time ranking by watch_history
- 🅰️ **A-Z Browse** — alphabet navigation (filter trending by judul awal huruf)
- 🎨 **Multi-thumbnail rotation** per film — variant slider/poster/backdrop, time-bucketed (CDN-cacheable)
- 📺 **Adaptive Hero Slider** — cinematic 2.4:1, auto-rotate carousel
- 🌟 **Gold premium theme** dengan reusable `<x-icon>` library (30+ Heroicons-style SVG)
- 📱 **Fully Responsive** — mobile collapsible menus, density-aware grid
- 🚀 **PWA Ready** — manifest, service worker, installable
- 🛠️ **Admin Panel** — Movies CRUD, AI Settings (encrypted API key), Pitch Deck presentation
- 🎬 **NativePHP Mobile** — wrapper Android via `./native` script

---

## 🛠️ Tech Stack

| Layer | Technology |
|-------|-----------|
| **Backend** | PHP 8.2+, Laravel 12.x |
| **Frontend** | Blade Components, Livewire 3, Alpine.js, TailwindCSS, Vite |
| **Video** | Video.js (gold theme skin) |
| **Database** | MySQL 8.0+ |
| **AI** | DeepSeek V4 Flash (default), supports OpenAI / Anthropic / Gemini / Groq / Mistral / OpenRouter |
| **Search** | Free Wikipedia API + DuckDuckGo (no API key) |
| **Payment** | Midtrans Snap (env-gated) |
| **Mobile** | NativePHP Mobile (Android) |
| **Build** | Vite 7.x |

---

## 🚀 Quickstart

### Prerequisites

| Tool | Version |
|------|---------|
| PHP | 8.2+ (with `pdo_mysql`, `bcmath`, `gd`, `mbstring`, `xml`, `zip`) |
| Composer | 2.x |
| Node.js | 18+ |
| MySQL | 8.0+ |

### Install

```bash
# 1. Clone
git clone https://github.com/pendtiumpraz/flik.git
cd flik

# 2. Copy env & generate app key
cp .env.example .env
php artisan key:generate

# 3. Install deps
composer install
npm install

# 4. Buat database `flik` di MySQL
mysql -u root -p -e "CREATE DATABASE flik CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
# (atau via phpMyAdmin)

# 5. Edit .env: set DB_DATABASE=flik, DB_USERNAME, DB_PASSWORD

# 6. Run migrations + seeders (idempotent — aman re-run)
php artisan migrate
php artisan db:seed

# 7. Storage symlink (untuk upload poster, video)
php artisan storage:link

# 8. Build assets
npm run build       # production
# ATAU untuk development dengan HMR:
npm run dev

# 9. Start server
php artisan serve
```

Buka: http://localhost:8000

---

## ⚙️ Konfigurasi Environment

FLiK pakai **ENV-first approach** — fitur auto-enable/disable berdasarkan presence env variables.

### Database (Required)
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=flik
DB_USERNAME=root
DB_PASSWORD=
```

### Payment Gateway — Midtrans (Optional)
```env
# Kosong = tombol "Coming Soon" otomatis
MIDTRANS_SERVER_KEY=
MIDTRANS_CLIENT_KEY=
MIDTRANS_IS_PRODUCTION=false
```

### Video Storage
```env
FILESYSTEM_DISK=public      # public | s3 | azure | alibaba

# Untuk S3 / DigitalOcean Spaces / MinIO:
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_BUCKET=
AWS_DEFAULT_REGION=ap-southeast-1
AWS_ENDPOINT=
```

### Web Search (untuk AI tool calling — Optional, default enabled)
```env
WEB_SEARCH_ENABLED=true
# Tidak butuh API key — pakai Wikipedia + DuckDuckGo gratis
```

### External APIs (Optional)
```env
TMDB_TOKEN=                 # Untuk fetch poster dari TMDB
GOOGLE_CLIENT_ID=           # Google OAuth
GOOGLE_CLIENT_SECRET=
MAILCHIMP_KEY=              # Newsletter
```

### AI Provider (set via Admin Panel, bukan .env)
API key AI provider **TIDAK** disimpan di .env. Konfigurasi di `/admin/ai-settings` — disimpan terenkripsi (AES-256 via APP_KEY) di tabel `ai_providers`.

Lihat [`.env.example`](.env.example) untuk lengkapnya.

---

## 👤 Default Accounts (Seeded)

| Role | Email | Password |
|------|-------|----------|
| Super Admin | `admin@gmail.com` | `password` |
| User biasa | `user@gmail.com` | `password` |

**⚠️** Saat re-seed, password **harus plain text** — User model punya mutator yang auto-bcrypt. Jangan pakai `Hash::make()` di seeder (akan double-bcrypt → login broken).

---

## 📺 Halaman & Fitur

### Public
- `/` — Landing + newsletter signup
- `/login`, `/register` — Auth (+ Google OAuth)
- `/login/google`, `/login/google/callback` — OAuth flow

### Authenticated
- `/movies` — **Home page**: hero slider + quick filter sidebar + trending (paginated) + coming soon + top 10 sidebar + A-Z browse + chatbot widget
- `/movie/{slug}` — Movie detail dengan player (Video.js / YouTube embed), rating, comments, watchlist toggle
- `/my-list` — Watchlist user
- `/rewards` — Gamification dashboard (XP, coins, streak, achievements, leaderboard)
- `/profile` — User profile + recent activity
- `/notifications` — Notification center
- `/plans` — Subscription plans dengan toggle Bulanan/Tahunan
- `/checkout/{plan}` — Payment via Midtrans Snap

---

## 🤖 AI Assistant — RAG + Tool Calling

Chatbot floating di pojok bawah-kanan setiap page authenticated.

### Pipeline
```
User query
  ↓
1. Pre-flight: nothing (relies on AI prompt + post-flight)
  ↓
2. RAG: search FLiK catalog (keyword/relevance score)
  → Inject top 8 relevant films + full slug whitelist as JSON
  ↓
3. AI call (DeepSeek V4 Flash by default) dengan tools=[search_web]
  ↓
4. Agentic loop (max 2 iterations):
  a. AI may invoke search_web with query
  b. WebSearchService → Wikipedia + DuckDuckGo
  c. Tool result sent back to AI
  d. AI synthesizes catalog + web data
  ↓
5. Post-flight:
  - Off-topic detector (code blocks, pure math) → override
  - Link validator: parse [text](/movie/slug), check DB
    → Valid: keep
    → Invalid: fuzzy-match real film → swap, OR strip with disclaimer
  ↓
6. Frontend renders markdown (XSS-safe whitelist)
```

### Capabilities
- ✅ Rekomendasi film berdasarkan mood/genre/aktor
- ✅ Detail spesifik (cast, year, rating, sinopsis)
- ✅ Pencarian via aktor/sutradara/era
- ✅ Bandingkan film (similar films)
- ✅ Detail lebih dalam via web search (awards, behind-the-scenes, biografi aktor)

### Strict Scope (refuses gracefully)
- ❌ Coding/programming
- ❌ Matematika
- ❌ Cuaca, berita, politik, resep, kesehatan, crypto
- ❌ Translate kata umum (kecuali istilah perfilman)
- ❌ Roleplay jadi AI lain

7 few-shot examples di system prompt mengajarkan AI cara tolak yang sopan + redirect ke topik film.

### Konfigurasi
1. Buka `/admin/ai-settings`
2. Add provider (default: DeepSeek)
3. Paste API key (auto-encrypted dengan APP_KEY)
4. Set as default + active

Models supported (per Mei 2026):
- DeepSeek V4 Flash ($0.14/$0.28 per MTok) — **default**, paling murah
- DeepSeek V4 Pro, OpenAI GPT-5.5/5.4, Anthropic Claude Opus 4.7/Sonnet 4.6/Haiku 4.5
- Google Gemini 2.5 Flash-Lite/Pro/3.0 Flash, Groq Llama 4 Maverick, Mistral, OpenRouter

---

## 🛡️ Role & Permission System

5 roles, defined di `App\Models\User`:

| Role | Slug | Default URL setelah login |
|------|------|---------------------------|
| Super Admin | `super_admin` | `/admin` |
| Content Manager | `content_manager` | `/admin/movies` |
| Customer Support | `customer_support` | `/admin/users` |
| Finance | `finance` | `/admin/plans` |
| User | `user` | `/movies` |

### Gates (di `AuthServiceProvider`)
```php
Gate::allows('admin')          // Super Admin OR any staff
Gate::allows('super-admin')    // Super Admin only
Gate::allows('manage-content') // Super Admin + Content Manager
Gate::allows('manage-users')   // Super Admin + Customer Support
Gate::allows('manage-finance') // Super Admin + Finance
Gate::allows('manage-system')  // Super Admin only
```

### Helper di User model
```php
$user->isStaff()           // role !== 'user'
$user->isSuperAdmin()      // role === 'super_admin' OR is_admin === true
$user->hasRole('finance')  // exact match
$user->hasRole(['super_admin', 'finance'])  // any of
$user->adminDashboardUrl() // smart redirect URL
$user->role_label          // "Super Admin", "Content Manager", etc
```

---

## 💳 Subscription Plans

Pricing per [PITCH_DECK.md](PITCH_DECK.md) v2.1:

| Plan | Monthly | Annual (-20%) | Quality | Devices | Ads | Download |
|------|---------|---------------|---------|---------|-----|----------|
| Free | Rp 0 | — | 480p | 1 | ✓ | ✗ |
| Basic | Rp 39rb | Rp 374rb | 480p | 2 | ✓ | ✗ |
| Premium ⭐ | Rp 79rb | Rp 758rb | 1080p | 4 | ✗ | ✓ |
| Family | Rp 129rb | Rp 1.238rb | 4K | 6 | ✗ | ✓ |

UI: 4 cards + toggle Bulanan/Tahunan (annual price calculated client-side via Alpine.js, no separate plan rows).

---

## 🎨 Multi-Thumbnail Rotation

Setiap film bisa punya **multiple variant** image per type — auto-rotate by time bucket untuk marketing (user yg balik dalam beberapa jam lihat poster baru, mengira film baru).

### Schema
| Type | Aspect | Recommended Size | Use |
|------|--------|------------------|-----|
| `slider` | ~2.4:1 cinematic | 1920×800 | Top hero auto-rotate |
| `poster` | 2:3 portrait | 500×750 | Grid card + popover |
| `backdrop` | 16:9 | 1920×1080 | Detail page bg blur |

### Cara Kerja
- Multiple variants aktif → time bucket: `floor(now / (rotation_hours × 3600)) % count`
- Same hour = same image untuk SEMUA user → CDN-cacheable
- Different hour = different variant
- Backward compat: kalau gak ada `MovieAsset` → fallback ke kolom legacy `slider_path`/`poster_path`/`backdrop_path` di tabel `movies`

### Method
```php
$movie->effective_slider_url    // rotating OR fallback
$movie->effective_poster_url
$movie->effective_backdrop_url
$movie->currentAssetUrl('slider')  // explicit
```

---

## 🔗 Movie URL — Slug Routing

URLs pakai slug, bukan ID:
- ❌ `/movie/1`
- ✅ `/movie/the-avengers`

Implementation:
- `Movie` model punya `getRouteKeyName()` returning `slug`
- Auto-generated slug saat create (`saving` event)
- Backfilled untuk semua existing film via migration

**Hallucination protection**: ChatController parse semua `[text](/movie/slug)` di AI response, validate slug exists. Invalid → fuzzy match real film & swap, atau strip dengan disclaimer "_(belum tersedia di FLiK)_". Zero 404 dari AI links.

---

## 🛠️ Admin Panel

| Path | Required Role |
|------|---------------|
| `/admin` | super-admin |
| `/admin/movies` | manage-content |
| `/admin/movies/create`, `/edit/{id}` | manage-content |
| `/admin/genres` | manage-content |
| `/admin/casts` | manage-content |
| `/admin/banners` | manage-content |
| `/admin/users` | manage-users |
| `/admin/ai-settings` | super-admin |
| `/admin/pitch-deck` | super-admin |

### AI Settings (`/admin/ai-settings`)
CRUD encrypted API keys per provider:
- Daftar provider (OpenAI, Anthropic, DeepSeek, Gemini, Groq, Mistral, OpenRouter, Custom)
- Pilih model dari dropdown atau custom
- API key disimpan dengan `encrypted` cast (AES-256-CBC pakai APP_KEY)
- Set priority + default
- Total cost & token tracker per provider

### Pitch Deck (`/admin/pitch-deck`)
17-slide presentation HTML view dari `PITCH_DECK.md` v2.1 — DRM tier comparison, AI feature catalog, AWS cost matrix (1K/10K/100K/1M MAU), CAC/LTV analysis.

---

## 📦 Asset Build & Deployment

### Konsep Penting
| Command | Output | Purpose |
|---------|--------|---------|
| `npm run dev` | Memory only (HMR via Vite dev server) | Local development |
| `npm run build` | `public/build/` files | Production assets |

⚠️ **`npm run dev` TIDAK menulis file**. Untuk produksi WAJIB `npm run build`.

### Build Workflow
- **Local development**: `npm run dev` (background) — auto-reload saat edit
- **Production deploy**: `npm run build` (di local atau di server)

### Strategy untuk Deployment

**Pilihan A**: Build di server (kalau Node.js available di server)
```bash
git pull
npm ci --omit=dev
npm run build
```

**Pilihan B**: Build di local, commit, push (kalau server gak punya Node.js)
```bash
# Local
npm run build
git add public/build/
git commit -m "rebuild assets"
git push

# Server
git pull
# Done — public/build/ sudah include compiled assets
```

`public/build/` **TIDAK** di-gitignore — sudah tracked supaya Pilihan B bisa.

### One-shot Deploy Script
[`deploy.sh`](deploy.sh) — bash script untuk run di server:
```bash
bash deploy.sh
```
Steps: git pull → composer install --no-dev → rm public/hot → npm ci + build → migrate → cache clear+rebuild → storage:link → permissions.

### Common Pitfalls
1. **`public/hot` file leftover** dari Vite dev di server → Laravel `@vite()` coba load asset dari `http://localhost:5173` → 404. **Fix**: `rm -f public/hot` saat deploy.
2. **Tailwind class missing dari compiled CSS** → ada safelist di `tailwind.config.js` untuk grid/layout classes critical sebagai failsafe.
3. **Build stale** (file `public/build/` lama) → user lihat UI lama. **Fix**: rebuild + commit, atau build di server.

---

## 📚 Architecture & Planning Docs

| File | Isi |
|------|-----|
| [`PITCH_DECK.md`](PITCH_DECK.md) | Business pitch v2.1 — DRM tiers, AI catalog (162 features), AWS costs (1K-1M MAU), CAC/LTV, P&L |
| [`RANCANGAN.md`](RANCANGAN.md) | Implementation plan — 3 phases, file-by-file breakdown, AI feature catalog |
| [`ARCHITECTURE.md`](ARCHITECTURE.md) | Technical design — ER diagrams, service hierarchy, sequence diagrams, security model |
| [`CLAUDE.md`](CLAUDE.md) | Codebase guide untuk Claude Code (AI dev tool) |

---

## 🧪 Testing

```bash
# Run all tests (PHPUnit + Pest)
./vendor/bin/phpunit
./vendor/bin/pest

# Single test file
./vendor/bin/phpunit tests/Feature/AuthenticationTest.php

# Lint & static analysis (CI runs these)
./vendor/bin/pint --test --preset laravel  # formatting (drop --test to fix)
./vendor/bin/psalm
./vendor/bin/phpstan analyse

# E2E (Cypress)
npx cypress open
```

---

## 🔧 Troubleshooting

### CSS gak match local di server
```bash
# Cek penyebab umum
ls public/hot 2>&1                    # harus "no such file"
ls public/build/manifest.json 2>&1    # harus exists, mtime recent

# Fix
rm -f public/hot
npm run build       # atau pull build dari local
php artisan optimize:clear
```
Hard refresh browser (`Ctrl+Shift+R`).

### Login: "credentials could not be verified"
Bukan email verification. Biasanya **double-bcrypt bug** di seeder (lihat note di [Default Accounts](#-default-accounts-seeded)). Re-run seeder:
```bash
php artisan db:seed --class=DatabaseSeeder
```

### AI chatbot gak respond
1. Cek `/admin/ai-settings` — ada provider aktif & default?
2. Cek API key valid (paste di `https://platform.deepseek.com/api_keys` test)
3. Cek `storage/logs/laravel.log` untuk error
4. Cek browser DevTools → Network → `/chat` request status

### Movie link 404
AI hallucinated slug. Sudah ada validator di `ChatController::validateMovieLinks()` — invalid slug auto-stripped/swapped. Kalau masih 404, cek log:
```bash
grep "AI link" storage/logs/laravel.log
```

### Cache stuck (perubahan gak nampak)
```bash
php artisan optimize:clear
```

### Re-seed duplicates
Semua seeder pakai `updateOrCreate`. Kalau ada duplicate, drop database & migrate ulang:
```bash
php artisan migrate:fresh --seed
```

---

## 🤝 Contributing

PRs welcome. Lihat [`CONTRIBUTING.md`](CONTRIBUTING.md).

Code style: Laravel Pint (`./vendor/bin/pint`).

---

## 📄 License

[MIT License](LICENSE) — open source.

---

<p align="center">
  <strong>FLiK</strong> — Rumah Sinema Indonesia 🇮🇩<br>
  <sub>Built with ❤️ untuk film klasik & modern Indonesia</sub>
</p>
