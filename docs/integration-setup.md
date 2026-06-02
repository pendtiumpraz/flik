# Panduan Koneksi Integrasi (API Keys & Infrastruktur)

Dokumen ini menjelaskan **langkah-demi-langkah menghubungkan setiap layanan eksternal** yang
dipakai FLiK: payment, storage/CDN, email, realtime, OAuth, AI, dan lain-lain.

> Ringkas: sebagian besar kredensial bisa diisi lewat **UI admin** di `/admin/infrastructure`
> (tanpa edit `.env`, tanpa redeploy). Sebagian lagi hanya lewat `.env`. Tabel di bawah
> menandai mana yang mana.

---

## 1. Dua cara konfigurasi & urutan prioritas

| Cara | Lokasi | Kapan dipakai |
|---|---|---|
| **Admin UI** | `/admin/infrastructure` (tab DRM/CDN/Storage/Realtime/Payment/Email/Integrations/Queue) | Mayoritas key — bisa diubah on-the-fly |
| **`.env`** | file `.env` di root | Nilai dasar / fallback, dan beberapa key yang belum ada di UI |

**Cara kerjanya** (`app/Providers/DynamicInfrastructureProvider.php`):
- Saat boot, nilai dari tabel `settings` (yang diisi via UI) **menimpa** `config()` yang
  berasal dari `.env`.
- Kalau row DB kosong/`null`, nilai `.env` tetap dipakai. Jadi **DB menang bila diisi**,
  kalau dikosongkan → balik ke `.env`.
- Perubahan di UI berlaku pada **request berikutnya** (cache di-bust otomatis saat save).

> ⚠️ **Catatan keamanan**: field bertanda "secret" di UI **disamarkan tampilannya** tapi
> **disimpan plaintext** di tabel `settings`. Untuk secret paling sensitif di produksi
> (mis. `MIDTRANS_SERVER_KEY` live, `APP_KEY`), lebih aman taruh di `.env`. Khusus
> **AI provider** key disimpan **terenkripsi** (`ai_providers` table) — lihat §12.

Setelah mengubah `.env`, selalu jalankan:
```bash
php artisan config:clear
```

---

## 2. Tabel ringkas: integrasi → di mana set → untuk fitur apa

| Integrasi | Set di | Wajib untuk | Catatan |
|---|---|---|---|
| **Midtrans** | UI Payment **atau** `.env` | Checkout langganan Premium | composer `midtrans/midtrans-php` sudah terpasang |
| Xendit / Doku / Stripe | UI Payment | Alternatif gateway | belum dipakai aktif |
| **Google OAuth** | UI Integrations (id+secret) + `.env` (redirect) | Login "Sign in with Google" | redirect hanya di `.env` |
| **TMDB** | UI Integrations **atau** `.env` | Wizard `/admin/tmdb-import` | read-only, gratis |
| **Mailchimp** | UI Integrations (key) + `.env` (list id) | Newsletter signup | list id hanya di `.env` |
| **Bunny CDN** | UI CDN **atau** `.env` | Distribusi HLS publik | stream-library hanya di `.env` |
| **S3 / GCS** | UI CDN (key/secret/bucket) + `.env` (endpoint) | Storage master + upload langsung | GCS = endpoint S3-compat (§9) |
| Cloudflare R2 / DO Spaces | UI CDN | Storage alternatif | S3-compatible |
| **SMTP / SES / Mailgun / dst** | UI Email | Kirim email (verifikasi, notif) | pilih driver di tab Email |
| **Pusher / Ably / Reverb** | UI Realtime | Notifikasi realtime | fallback `polling` |
| **Cloudflare Turnstile** | UI Integrations | CAPTCHA login/register/komentar | opsional, no-op jika kosong |
| **MaxMind GeoLite2** | UI Integrations | GeoIP / geo-block | composer `geoip2/geoip2` terpasang |
| **AI Providers** | `/admin/ai-settings` (terpisah) | Semua fitur AI | key terenkripsi |
| **Web Push (VAPID)** | `.env` + command | Push notification browser | §13 |
| **Queue driver** | UI Queue | Background jobs | §8 |
| **NativePHP** | `.env` | Build mobile Android | §14 |

---

## 3. Midtrans (Payment Gateway — default)

**Untuk apa:** tombol checkout di `/plans`. Kalau `MIDTRANS_SERVER_KEY` kosong → tombol jadi
"Coming Soon" (pola env-gating). Webhook tetap aktif di `POST /payment/webhook`.

**Langkah:**
1. Daftar di <https://dashboard.midtrans.com>. Untuk tes pakai **Environment: Sandbox**.
2. Buka **Settings → Access Keys**. Catat: **Server Key**, **Client Key**, **Merchant ID**.
3. Buka `/admin/infrastructure` → tab **Payment**:
   - Payment Gateway: `Midtrans`
   - Midtrans Production Mode: **OFF** (sandbox dulu)
   - Server Key / Client Key / Merchant ID: tempel
   - **Save**.
   (Alternatif `.env`: `MIDTRANS_SERVER_KEY=`, `MIDTRANS_CLIENT_KEY=`, `MIDTRANS_IS_PRODUCTION=false`)
4. Di dashboard Midtrans → **Settings → Configuration → Payment Notification URL**, isi:
   `https://DOMAIN-KAMU/payment/webhook`
   (Finish/Unfinish/Error redirect → arahkan ke `https://DOMAIN-KAMU/plans`.)

**Verifikasi:** buka `/plans` → pilih paket → modal Snap muncul → bayar pakai
[kartu tes sandbox](https://docs.midtrans.com/docs/testing-payment) → transaksi muncul di
dashboard, langganan user jadi aktif setelah webhook masuk.

**Troubleshoot:**
- Tombol "Coming Soon" → server key masih kosong / belum `config:clear`.
- Webhook tidak update status → cek Notification URL benar + lihat `storage/logs/laravel.log`.
- Mau production → verifikasi merchant dulu, baru nyalakan **Production Mode ON** + ganti ke key live.

---

## 4. Google OAuth (Login Socialite)

**Untuk apa:** tombol "Sign in with Google". Route: `GET /login/google` →
`GET /login/google/callback`.

**Langkah:**
1. <https://console.cloud.google.com> → buat project → **APIs & Services → Credentials**.
2. **Create Credentials → OAuth client ID → Web application**.
3. **Authorized redirect URIs**, tambahkan persis:
   `https://DOMAIN-KAMU/login/google/callback`
4. Catat **Client ID** + **Client Secret**.
5. `/admin/infrastructure` → tab **Integrations**: isi Google OAuth Client ID + Client Secret → Save.
6. **Redirect WAJIB di `.env`** (belum ada di UI):
   ```
   GOOGLE_REDIRECT=https://DOMAIN-KAMU/login/google/callback
   ```
   lalu `php artisan config:clear`.

**Verifikasi:** klik tombol Google di halaman login → consent screen Google → balik login.

**Troubleshoot:** `redirect_uri_mismatch` → URI di Google Console harus identik (termasuk
http/https, trailing slash) dengan `GOOGLE_REDIRECT`.

---

## 5. TMDB (import metadata film)

**Untuk apa:** wizard `/admin/tmdb-import` untuk menarik judul/poster/sinopsis.

**Langkah:**
1. Daftar gratis di <https://www.themoviedb.org> → **Settings → API** → buat API key.
2. Ada dua jenis: **API Key (v3)** atau **Read Access Token (v4 Bearer)**. Salah satu cukup;
   client memprioritaskan bearer bila keduanya diisi.
3. `/admin/infrastructure` → tab **Integrations** → **TMDB API Key** → tempel → Save.
   (Alternatif `.env`: `TMDB_KEY=` untuk v3, `TMDB_BEARER=` untuk v4.)

**Verifikasi:** buka `/admin/tmdb-import`, cari judul → hasil muncul.

---

## 6. Storage & CDN

Ada beberapa backend. Pilih satu di tab **CDN** (`cdn.driver`) dan/atau tab **Storage**.

### 6a. Bunny CDN (rekomendasi untuk delivery HLS)
1. <https://dash.bunny.net> → buat **Storage Zone** + **Pull Zone**.
2. `/admin/infrastructure` → tab **CDN** → driver `Bunny`:
   - Storage Zone, Storage Access Key, Pull Zone URL, Token Authentication Key → Save.
3. **Stream Library** (opsional, hanya `.env`):
   `BUNNY_STREAM_LIBRARY_ID=`, `BUNNY_STREAM_API_KEY=`.

### 6b. AWS S3 + CloudFront
- Tab **CDN** driver `S3`: Region, Bucket, Access Key, Secret, (opsional) CloudFront Domain.
- Atau `.env`: `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_BUCKET`, `AWS_DEFAULT_REGION`.

### 6c. Cloudflare R2 / DigitalOcean Spaces
- Tab **CDN** driver `R2`/`Spaces`: isi account/region, bucket, access key, secret. Keduanya
  S3-compatible.

### 6d. Google Cloud Storage → lihat §9 (langkah lengkap + CORS).

> Catatan: driver storage butuh package S3 (`league/flysystem-aws-s3-v3`). Jalankan
> `composer install` setelah deploy. Tanpa package, disk S3/GCS/R2/Spaces tidak aktif
> dan otomatis fallback ke lokal.

---

## 7. Email (verifikasi akun, notifikasi)

Pilih driver di tab **Email** (`email.driver`):

| Driver | Yang perlu diisi | Dapat key dari |
|---|---|---|
| **SMTP** | Host, Port (587), Username, Password, Encryption (tls) | provider email kamu (Gmail App Password, Zoho, dst) |
| **AWS SES** | Region, Access Key, Secret Key | AWS IAM (SES verified domain) |
| **Mailgun** | Domain, API Secret | dashboard Mailgun |
| **Postmark** | Server Token | dashboard Postmark |
| **Resend** | API Key (`re_...`) | resend.com |
| **SendGrid** | API Key (`SG....`) | sendgrid.com |
| **log** | — | dev: email ditulis ke `storage/logs/laravel.log` |

Set juga **From Address** + **From Name**. **Verifikasi:** trigger email verifikasi register,
cek inbox / `laravel.log` (kalau driver `log`).

---

## 8. Queue (Sync vs Async) — tab **Queue**

**Penjelasan singkat:**
- **`sync`** — tiap job (transcode, email, AI, notifikasi) dikerjakan **langsung saat request**.
  Tidak butuh worker. Cocok **shared hosting**. Kelemahan: tugas berat (transcode) bisa
  timeout, dan request user menunggu sampai job selesai.
- **`database`** — job dimasukkan ke tabel `jobs`, dikerjakan **worker di background**. Butuh
  `php artisan queue:work` berjalan terus (VPS/VM atau cron `--stop-when-empty` di shared host).
- **`redis`** — sama seperti database tapi throughput tinggi; butuh server Redis + worker.

**Langkah:** `/admin/infrastructure` → tab **Queue** → pilih driver → Save.

**Penting:** ganti driver hanya berlaku untuk job **baru**. Worker yang sedang jalan perlu:
```bash
php artisan queue:restart
```
Untuk async, jalankan worker (VPS/VM):
```bash
php artisan queue:work --queue=transcoding,ai-realtime,ai-batch,notifications,audit,default --timeout=7200
```
Di shared hosting (cron tiap menit, ramah host):
```
* * * * * cd /path/app && php artisan queue:work --stop-when-empty --max-time=50 >/dev/null 2>&1
```

> Default sekarang `sync` (aman shared hosting). Transcoding tetap butuh `ffmpeg` + worker —
> realistis dijalankan di VM, bukan shared hosting.

---

## 9. Google Cloud Storage (via S3-compatible / HMAC)

Dipakai untuk menyimpan file master dan **upload langsung browser → GCS** (tanpa lewat server).

**Langkah:**
1. Buat **bucket** di <https://console.cloud.google.com/storage>.
2. **Cloud Storage → Settings → Interoperability → Create a key** (untuk sebuah service
   account). Catat **Access key** & **Secret**.
3. Isi `.env` (endpoint & path-style hanya bisa di `.env`):
   ```
   AWS_ACCESS_KEY_ID=<HMAC access key>
   AWS_SECRET_ACCESS_KEY=<HMAC secret>
   AWS_BUCKET=<nama-bucket>
   AWS_DEFAULT_REGION=auto
   AWS_ENDPOINT=https://storage.googleapis.com
   AWS_USE_PATH_STYLE_ENDPOINT=true
   ```
   (Key/secret/bucket juga bisa via UI tab CDN driver S3; endpoint tetap dari `.env`.)
4. **Set CORS bucket** (WAJIB untuk upload langsung dari browser). Buat `cors.json`:
   ```json
   [{ "origin": ["https://DOMAIN-KAMU"],
      "method": ["PUT", "GET", "HEAD"],
      "responseHeader": ["Content-Type"],
      "maxAgeSeconds": 3600 }]
   ```
   Terapkan:
   ```bash
   gcloud storage buckets update gs://NAMA-BUCKET --cors-file=cors.json
   ```
5. `composer install` (agar `league/flysystem-aws-s3-v3` terpasang) → `php artisan config:clear`.

**Cara kerja upload:** browser minta presigned PUT (`/admin/movies/{id}/sign-upload`) → PUT file
**langsung ke GCS** → `/admin/movies/{id}/finalize-upload` mencatat object ke movie. File besar
**tidak transit server** (penting untuk shared hosting).

**Verifikasi:** buka halaman upload master sebuah film → pilih file → progress jalan → object
muncul di bucket GCS.

**Troubleshoot:**
- PUT gagal / CORS error di console browser → CORS bucket belum diset (langkah 4).
- `403 SignatureDoesNotMatch` → cek region `auto` + `AWS_USE_PATH_STYLE_ENDPOINT=true`. Bila
  tetap, coba set region ke lokasi bucket (mis. `asia-southeast2`).
- "Cloud storage belum dikonfigurasi" → key/secret/bucket kosong, atau `composer install`
  belum dijalankan.

---

## 10. Realtime (notifikasi live)

Tab **Realtime** (`realtime.driver`):
- **Pusher** (SaaS): App ID, App Key, App Secret, Cluster (`ap1` = Singapore, terdekat ID).
  Dapat dari <https://dashboard.pusher.com>.
- **Reverb / Soketi** (self-host, protokol Pusher): Host, Port, Scheme.
- **Ably** (SaaS): API Key.
- **polling**: tanpa realtime, fallback interval detik.

---

## 11. Cloudflare Turnstile (CAPTCHA) & MaxMind (GeoIP)

**Turnstile** (opsional — kalau kosong, CAPTCHA jadi no-op):
1. <https://dash.cloudflare.com> → Turnstile → Add site → catat **Site Key** + **Secret Key**.
2. Tab **Integrations** → isi keduanya → Save.

**MaxMind GeoLite2** (untuk geo-block):
1. Daftar gratis di <https://www.maxmind.com> → buat **License Key** + catat **Account ID**.
2. Tab **Integrations** → isi Account ID + License Key → Save.
3. Update database: `php artisan flik:geoip:update` (dijadwalkan mingguan via scheduler).

---

## 12. AI Providers (OpenAI / Anthropic / DeepSeek / Gemini / dst)

**Terpisah** dari `/admin/infrastructure`. Dikelola di **`/admin/ai-settings`** dan key disimpan
**terenkripsi** di tabel `ai_providers`.

**Langkah:**
1. `/admin/ai-settings` → **Add Provider**.
2. Pilih provider, isi **API Key** + (kalau perlu) **Base URL** + model default.
3. Tandai satu provider sebagai **default/active** — semua task AI (chatbot, tagging, sinopsis,
   subtitle, rekomendasi, dll.) otomatis memakai provider aktif itu.
4. Klik **Test Connection** untuk memastikan key valid.

> Semua task AI degrade dengan anggun (mengembalikan fallback, bukan error) bila tidak ada
> provider aktif. Biaya/pemakaian terlihat di `/admin/ai-usage`.

---

## 13. Web Push (VAPID) — `.env` + command

**Untuk apa:** push notification ke browser. Kalau kosong → banner opt-in sembunyi, endpoint
subscribe balas 503 (no-op).

**Langkah:**
1. Generate keypair:
   ```bash
   php artisan flik:push:generate-vapid-keys
   ```
2. Salin output ke `.env`:
   ```
   VAPID_PUBLIC_KEY=...
   VAPID_PRIVATE_KEY=...
   VAPID_SUBJECT=mailto:admin@domain-kamu
   ```
3. `php artisan config:clear`.

---

## 14. NativePHP (build mobile Android) — `.env`

Hanya relevan kalau build APK. Di `.env`:
```
NATIVEPHP_APP_ID=com.flik.app
NATIVEPHP_APP_VERSION="1.0.0"
NATIVEPHP_APP_VERSION_CODE="1"
```
Build via wrapper: `./native <command>` (= `php artisan native:<command>`).

---

## 15. Checklist cepat per skenario

**Dev lokal minimum (tanpa bayar apa-apa):**
- `FILESYSTEM_DISK=local`, `QUEUE_CONNECTION=sync`, mail driver `log`.
- AI: tambah 1 provider di `/admin/ai-settings` (mis. key OpenAI/DeepSeek) — opsional.
- TMDB key (gratis) kalau mau import katalog.

**Shared hosting + storage GCS (skenario kamu sekarang):**
- §9 (GCS HMAC + CORS), `composer install`, `php artisan config:clear`.
- Queue: tab **Queue** = `sync` (default). Transcoding ditunda sampai punya VM ber-ffmpeg.
- Midtrans (§3) kalau sudah mau jualan.

**Produksi penuh (VPS/VM):**
- Queue `database`/`redis` + worker (`queue:work` via systemd/supervisor).
- Scheduler cron: `* * * * * php artisan schedule:run`.
- Bunny/S3 untuk delivery, ffmpeg terpasang untuk transcoding.
