# Deploy ke Google Cloud (GCP) + Database

Panduan langkah deploy FLiK ke GCP. **Database produksi: Cloud SQL for PostgreSQL** (rekomendasi) —
satu codebase yang sama dengan dev (MySQL), beda `.env`. Postgres dipilih agar siap `pgvector`
untuk AI semantic search nanti **tanpa pindah DB** (lihat §4b).

> Untuk cara dapat & isi API key tiap layanan (Midtrans, GCS, OAuth, dll), lihat
> **[integration-setup.md](integration-setup.md)** atau menu admin **System → Panduan Koneksi**.

---

## 0. Pilih arsitektur dulu

| Komponen | Pilihan | Rekomendasi |
|---|---|---|
| **Compute** | Compute Engine (VM) / Cloud Run / App Engine | **Compute Engine VM** — bisa jalankan worker + `ffmpeg` (transcoding). Cloud Run/App Engine = ephemeral, tak cocok untuk worker & ffmpeg. |
| **Database** | **Cloud SQL for PostgreSQL** (rekomendasi) / Neon | **Cloud SQL Postgres** region sama — latency rendah + siap `pgvector` (AI semantic search) tanpa pindah DB. Neon = alternatif serverless (beda cloud → latency lintas-cloud). |
| **Storage** | GCS (sudah disiapkan) | GCS via S3-compat (lihat integration-setup §9). |
| **Queue** | sync / database / redis | VM → `database` atau `redis` + worker. |

**Catatan dual-DB**: codebase sudah portable MySQL + PostgreSQL + SQLite. Pindah DB = cukup
ganti `DB_CONNECTION` + kredensial di `.env`. Semua search sudah case-insensitive di Postgres
(pakai `whereLike`/ILIKE), FULLTEXT help-search auto-fallback, migrasi sudah driver-aware.

---

## 1. Provision VM (Compute Engine)

```bash
# Contoh: e2-standard-2 (2 vCPU, 8GB) Debian 12, region dekat target user (mis. asia-southeast2 = Jakarta)
gcloud compute instances create flik-app \
  --machine-type=e2-standard-2 \
  --image-family=debian-12 --image-project=debian-cloud \
  --zone=asia-southeast2-a \
  --tags=http-server,https-server
```
Buka firewall HTTP/HTTPS (atau pakai Load Balancer + managed TLS).

## 2. Install dependency di VM

```bash
sudo apt update
sudo apt install -y php8.2 php8.2-fpm php8.2-cli php8.2-mbstring php8.2-xml \
  php8.2-curl php8.2-zip php8.2-gd php8.2-bcmath php8.2-pgsql php8.2-mysql php8.2-redis \
  nginx git unzip ffmpeg redis-server
# Composer
curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer
# Node (untuk build aset) — pakai nvm atau nodesource
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash - && sudo apt install -y nodejs
```
> `ffmpeg` wajib untuk transcoding. `php8.2-pgsql` untuk Postgres/Neon, `php8.2-mysql` untuk MySQL.

## 3. Deploy kode

```bash
cd /var/www
sudo git clone <repo-url> flik && cd flik
composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp .env.example .env && php artisan key:generate
sudo chown -R www-data:www-data storage bootstrap/cache
php artisan storage:link
```

## 4. Konfigurasi `.env` produksi

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://domain-kamu.com

# ── Database: Cloud SQL for PostgreSQL (REKOMENDASI) ────────
# Koneksi via Cloud SQL Auth Proxy (lihat §4b) → host 127.0.0.1.
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=velflix
DB_USERNAME=velflix
DB_PASSWORD=<pass>
DB_SSLMODE=disable                                 # Auth Proxy sudah enkripsi; pakai 'require' bila koneksi langsung tanpa proxy

# ── Alternatif: Neon Postgres (serverless) ──────────────────
# DB_HOST=<project>-pooler.<region>.aws.neon.tech  # WAJIB endpoint POOLED (PgBouncer)
# DB_SSLMODE=require
# (atau DATABASE_URL=postgres://user:pass@host/db?sslmode=require)

# ── Queue (VM punya worker) ──────────────────────────────────
QUEUE_CONNECTION=database          # atau redis
CACHE_DRIVER=redis
SESSION_DRIVER=database

# ── Storage: GCS (lihat integration-setup §9) ───────────────
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=<HMAC>
AWS_SECRET_ACCESS_KEY=<HMAC-secret>
AWS_BUCKET=<bucket>
AWS_DEFAULT_REGION=auto
AWS_ENDPOINT=https://storage.googleapis.com
AWS_USE_PATH_STYLE_ENDPOINT=true
```
Lalu:
```bash
php artisan config:clear
```

## 4b. Cloud SQL for PostgreSQL (+ pgvector opsional)

**Provision instance** (region sama dengan VM):
```bash
gcloud sql instances create flik-pg \
  --database-version=POSTGRES_16 \
  --cpu=2 --memory=8GB \
  --region=asia-southeast2 --storage-auto-increase
gcloud sql databases create velflix --instance=flik-pg
gcloud sql users create velflix --instance=flik-pg --password=<pass>
```

**Sizing (patokan, 1k–100k user):**
| User | Instance | Catatan |
|---|---|---|
| 1k–10k | `--cpu=1 --memory=3840MB` | cukup |
| 10k–50k | `--cpu=2 --memory=8GB` | + automated backups |
| 50k–100k | `--cpu=4 --memory=16GB` + **read replica** | + HA regional (`--availability-type=REGIONAL`) |

> Bottleneck di skala 100k streaming **bukan DB** tapi bandwidth (CDN) + transcoding (CPU worker).

**Koneksi via Cloud SQL Auth Proxy** (aman, tanpa expose IP publik) — jalankan di VM:
```bash
curl -o cloud-sql-proxy https://storage.googleapis.com/cloud-sql-connectors/cloud-sql-proxy/v2.11.0/cloud-sql-proxy.linux.amd64
chmod +x cloud-sql-proxy && sudo mv cloud-sql-proxy /usr/local/bin/
# Jalankan (idealnya sebagai systemd service) → listen 127.0.0.1:5432
cloud-sql-proxy --port 5432 <project>:asia-southeast2:flik-pg
```
Lalu `.env` cukup `DB_HOST=127.0.0.1 DB_PORT=5432` (lihat §4).

### pgvector — OPSIONAL, hanya saat butuh AI semantic search
**Belum perlu sekarang.** RAG film saat ini berbasis keyword/ILIKE dan sudah jalan. Saat nanti
mau upgrade ke semantic search (embedding), tinggal aktifkan extension — **tanpa migrasi / pindah DB**:
```sql
-- Cloud SQL Postgres & Neon dua-duanya mendukung:
CREATE EXTENSION IF NOT EXISTS vector;
```
lalu tambah kolom `embedding vector(1536)` saat Phase 2 diimplementasikan. Memilih Postgres
sekarang = pintu pgvector terbuka tanpa biaya migrasi di kemudian hari.

## 5. Database: migrate + seed

```bash
php artisan migrate --force           # bangun semua tabel (portable MySQL/PG)
php artisan db:seed --force           # admin user, plans, settings (kalau perlu)
```
> Jalankan dulu `bash scripts/check-pg-migrations.sh` untuk memastikan semua migrasi (121) lolos
> di Postgres. Data dev (MySQL) **tidak** ikut pindah otomatis — prod mulai fresh + seed.

## 6. Web server (nginx + PHP-FPM)

> **Otomatis** bila pakai `scripts/vm-startup.sh`: nginx site + HTTPS (certbot, auto-renew) sudah
> dipasang saat boot — cukup set metadata `flik-domain` (+ `flik-letsencrypt-email`) lewat env
> `FLIK_DOMAIN`/`FLIK_LE_EMAIL` di `gcp-provision.sh`, dan arahkan DNS A domain → IP VM.
> Bagian manual di bawah hanya referensi.

`/etc/nginx/sites-available/flik`:
```nginx
server {
    listen 80;
    server_name domain-kamu.com;
    root /var/www/flik/public;
    index index.php;

    client_max_body_size 5G;   # samakan dgn upload limit (lihat public/.user.ini)

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```
```bash
sudo ln -s /etc/nginx/sites-available/flik /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```
> PHP-FPM membaca `public/.user.ini` (limit upload 5G). Untuk transcoding via worker (CLI),
> set `max_execution_time=0` di `php.ini` CLI.

## 7. HTTPS

Pakai **Let's Encrypt** (`certbot --nginx`) atau **GCP HTTPS Load Balancer** dengan managed cert.

## 8. Queue worker (systemd)

`/etc/systemd/system/flik-worker.service`:
```ini
[Unit]
Description=FLiK queue worker
After=network.target

[Service]
User=www-data
Restart=always
ExecStart=/usr/bin/php /var/www/flik/artisan queue:work \
  --queue=transcoding,ai-realtime,ai-batch,notifications,audit,default \
  --timeout=7200 --sleep=3 --tries=2

[Install]
WantedBy=multi-user.target
```
```bash
sudo systemctl enable --now flik-worker
# Setelah deploy/perubahan kode: php artisan queue:restart
```

## 9. Scheduler (cron)

```bash
sudo crontab -e
```
Tambahkan:
```
* * * * * cd /var/www/flik && php artisan schedule:run >> /dev/null 2>&1
```
Ini menjalankan tugas terjadwal: `flik:recommendations:recompute` (nightly), `flik:report:daily`,
`flik:ai:weekly-digest`, `flik:geoip:update`, dll.

## 10. Optimasi produksi

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```
> Ulangi tiap deploy. `DynamicInfrastructureProvider` tetap override config dari DB walau di-cache.

## 11. Auto-deploy (GitHub Actions)

Workflow `.github/workflows/deploy.yml` melakukan auto-deploy tiap push ke `main`: SSH ke VM →
`git pull` → `scripts/deploy.sh` (composer install, `npm run build`, `migrate --force`, `optimize`,
`queue:restart`, reload php-fpm).

**Setup sekali (di GitHub repo → Settings → Secrets and variables → Actions):**
| Secret | Isi |
|---|---|
| `VM_HOST` | IP eksternal VM (output `gcp-provision.sh`) |
| `VM_USER` | user SSH di VM (mis. `deploy`) |
| `VM_SSH_KEY` | **private** key SSH (PEM) — public-nya dipasang di `~/.ssh/authorized_keys` VM |
| `DISCORD_WEBHOOK` | *(opsional)* webhook channel Discord → notifikasi deploy **sukses/gagal**. Kosong = nonaktif. |

**Di VM (sekali):**
```bash
# tambah public key ke authorized_keys user deploy, lalu beri passwordless sudo:
echo '<VM_USER> ALL=(ALL) NOPASSWD:ALL' | sudo tee /etc/sudoers.d/flik-deploy
```

Setelah itu: tiap `git push origin main` → otomatis ter-deploy. Bisa juga jalankan manual via tab
**Actions → Deploy to VM → Run workflow** (`workflow_dispatch`).

> Worker tidak perlu di-restart manual — `deploy.sh` memanggil `queue:restart`.
> Untuk yang anti-static-key, workflow menyertakan alternatif **Workload Identity Federation +
> `gcloud compute ssh --tunnel-through-iap`** (lihat komentar di file workflow).

**Rollback otomatis:** sebelum deploy, workflow menyimpan commit yang sedang berjalan. Setelah
build/migrate ia health-check `GET /healthz`. Jika **build/migrate gagal ATAU health-check merah**,
workflow otomatis `git reset` balik ke commit sebelumnya + rebuild + restart, lalu menandai job GAGAL.

> ⚠️ Rollback ini **kode saja, BUKAN database**. Migrasi Laravel bersifat additif
> (forward-compatible) → kode lama tetap jalan dengan skema baru. Auto-undo migrasi
> (`migrate:rollback`) sengaja TIDAK dilakukan karena `down()` bisa lossy. Kalau sebuah rilis
> butuh perubahan skema yang breaking, tangani migrasinya manual.

---

## Catatan penting (Neon vs Cloud SQL)

- **Neon** auto-suspend setelah idle → request pertama setelah nganggur kena cold-start. Wajib pakai
  endpoint **pooled** (PgBouncer) karena PHP bikin koneksi baru tiap request. Neon di AWS/Azure →
  **latency lintas-cloud** dari VM GCP. OK untuk dev/staging; untuk produksi DB-heavy kurang ideal.
- **Cloud SQL (MySQL/PG)** di region sama dengan VM = latency rendah, koneksi via **Cloud SQL Auth
  Proxy** atau private IP. Untuk MySQL: zero perubahan skema. Ini pilihan paling mulus di GCP.
- **Transcoding** hanya jalan di VM ber-`ffmpeg` (poin 2). Shared hosting tidak bisa transcode —
  hanya simpan/serve. Lihat pembahasan upload & DRM di repo.

## Ringkasan alur deploy

1. VM + dependency (PHP 8.2, ffmpeg, nginx, redis, node)
2. clone + `composer install --no-dev` + `npm run build` + `key:generate` + `storage:link`
3. `.env` produksi (DB pgsql/Neon atau Cloud SQL + GCS + queue)
4. `migrate --force` + `db:seed --force`
5. nginx + PHP-FPM + HTTPS
6. systemd worker + cron scheduler
7. `config/route/view:cache`
