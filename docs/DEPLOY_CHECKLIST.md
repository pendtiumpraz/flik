# ✅ FLiK — Checklist Deploy ke GCP (step-by-step)

Runbook urut, tinggal centang. Ganti semua `<PLACEHOLDER>` dengan nilaimu.
Detail tiap bagian ada di [deploy-gcp.md](deploy-gcp.md) & [integration-setup.md](integration-setup.md).

> 💡 **Paling gampang**: jalankan semua perintah `gcloud`/`bash` dari **Google Cloud Shell**
> (console GCP → ikon `>_`). Sudah ada gcloud + gsutil + jq + bash dan sudah ter-login.

---

## Fase 0 — Prasyarat (sekali)

- [ ] Punya **akun Google Cloud** + **project** dengan **Billing AKTIF**
- [ ] Catat `PROJECT_ID` kamu
- [ ] Pilih region dekat user, mis. `asia-southeast2` (Jakarta)
- [ ] (Kalau TIDAK pakai Cloud Shell) install di mesinmu: `gcloud` SDK, `bash`, `jq`, `openssl`
- [ ] Login & set project:
  ```bash
  gcloud auth login                      # OAuth GCP (bukan Google-login aplikasi)
  gcloud config set project <PROJECT_ID>
  ```
- [ ] Pastikan rolemu di project = **Owner** (atau Compute Admin + Cloud SQL Admin + Service Account User + Project IAM Admin + Storage Admin)
- [ ] **Clone repo** (untuk menjalankan skrip provision):
  ```bash
  git clone <REPO_URL> flik && cd flik
  ```

---

## Fase 1 — Storage GCS (sekali)

- [ ] Buat **bucket GCS** (PRIVATE) untuk video + backup, mis. `flik-media`
- [ ] Cloud Storage → **Settings → Interoperability → Create key** (service account) → catat **Access key** + **Secret** (ini `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY`)
- [ ] Set **CORS** bucket (untuk upload langsung browser → GCS). Buat `cors.json`:
  ```json
  [{ "origin": ["https://<DOMAIN>"], "method": ["PUT","GET","HEAD"],
     "responseHeader": ["Content-Type"], "maxAgeSeconds": 3600 }]
  ```
  ```bash
  gcloud storage buckets update gs://<BUCKET> --cors-file=cors.json
  ```

---

## Fase 2 — Provision infra (VM + Cloud SQL Postgres)

- [ ] **Preview dulu** (tidak membuat apa-apa):
  ```bash
  GCP_PROJECT=<PROJECT_ID> GCP_REGION=asia-southeast2 \
  FLIK_DOMAIN=<DOMAIN> FLIK_LE_EMAIL=<EMAIL> \
  FLIK_GCS_BUCKET=<BUCKET> \
    bash scripts/gcp-provision.sh --dry-run
  ```
- [ ] **Eksekusi** (ketik `yes` saat diminta):
  ```bash
  GCP_PROJECT=<PROJECT_ID> GCP_REGION=asia-southeast2 \
  FLIK_DOMAIN=<DOMAIN> FLIK_LE_EMAIL=<EMAIL> \
  FLIK_GCS_BUCKET=<BUCKET> \
    bash scripts/gcp-provision.sh
  ```
- [ ] **CATAT output**: `Cloud SQL connection name`, `VM external IP`, dan **DB password** (digenerate sekali!)
- [ ] VM otomatis menjalankan startup-script (install PHP/nginx/ffmpeg/redis/proxy/worker/cron/HTTPS).
  Pantau:
  ```bash
  gcloud compute ssh flik-app --zone asia-southeast2-a --command 'sudo tail -f /var/log/flik-startup.log'
  ```
  (tunggu sampai muncul "✅ FLiK VM siap")

---

## Fase 3 — DNS + HTTPS (kalau pakai domain)

- [ ] Buat **DNS A record**: `<DOMAIN>` → `<VM_EXTERNAL_IP>`
- [ ] Tunggu propagasi (cek: `nslookup <DOMAIN>` mengarah ke IP VM)
- [ ] HTTPS terbit otomatis saat startup kalau DNS sudah aktif. Kalau belum sempat, jalankan di VM:
  ```bash
  sudo certbot --nginx -d <DOMAIN>
  ```

---

## Fase 4 — Deploy aplikasi pertama (di VM)

- [ ] SSH ke VM: `gcloud compute ssh flik-app --zone asia-southeast2-a`
- [ ] Clone kode ke `/var/www/flik`:
  ```bash
  sudo git clone <REPO_URL> /var/www/flik && cd /var/www/flik
  sudo chown -R www-data:www-data /var/www/flik
  ```
- [ ] Install deps + build:
  ```bash
  sudo -u www-data env HOME=/var/www/flik composer install --no-dev --optimize-autoloader
  sudo -u www-data env HOME=/var/www/flik bash -c 'npm ci && npm run build'
  ```
- [ ] Buat `.env` (lihat template di bawah), lalu:
  ```bash
  sudo -u www-data php artisan key:generate
  sudo -u www-data php artisan storage:link
  sudo -u www-data php artisan migrate --force
  sudo -u www-data php artisan db:seed --force      # admin/plans/settings awal
  sudo -u www-data php artisan optimize
  ```
- [ ] Start worker:
  ```bash
  sudo systemctl start flik-worker
  ```

### Template `.env` produksi (isi `<...>`)
```ini
APP_NAME=FLiK
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<DOMAIN>

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1            # via Cloud SQL Auth Proxy (systemd, sudah jalan)
DB_PORT=5432
DB_DATABASE=velflix
DB_USERNAME=velflix
DB_PASSWORD=<DB_PASSWORD dari output provision>
DB_SSLMODE=disable

QUEUE_CONNECTION=database
CACHE_DRIVER=redis
SESSION_DRIVER=database

FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=<HMAC_KEY>
AWS_SECRET_ACCESS_KEY=<HMAC_SECRET>
AWS_BUCKET=<BUCKET>
AWS_DEFAULT_REGION=auto
AWS_ENDPOINT=https://storage.googleapis.com
AWS_USE_PATH_STYLE_ENDPOINT=true
```

---

## Fase 5 — Verifikasi

- [ ] `curl -fsS https://<DOMAIN>/healthz` → balas OK (200)
- [ ] Buka `https://<DOMAIN>` di browser → homepage muncul
- [ ] Login admin di `https://<DOMAIN>/login` (seed default: `admin@gmail.com` / `password`)
- [ ] ⚠️ **GANTI password admin** + email default segera
- [ ] Cek worker jalan: `sudo systemctl status flik-worker`
- [ ] Cek proxy DB jalan: `sudo systemctl status cloud-sql-proxy`

---

## Fase 6 — CI/CD auto-deploy (GitHub Actions)

- [ ] Buat user/clone SSH untuk deploy + passwordless sudo di VM:
  ```bash
  echo '<VM_USER> ALL=(ALL) NOPASSWD:ALL' | sudo tee /etc/sudoers.d/flik-deploy
  ```
- [ ] Pasang **public** SSH key deploy ke `~/.ssh/authorized_keys` VM
- [ ] GitHub → **Settings → Environments** → buat `production` (& `staging` kalau perlu)
- [ ] Isi secret di environment `production`:
  - [ ] `VM_HOST` = IP VM
  - [ ] `VM_USER` = user SSH
  - [ ] `VM_SSH_KEY` = private key SSH (PEM)
  - [ ] `DISCORD_WEBHOOK` = (opsional) webhook channel Discord
- [ ] Tes: **Actions → Deploy to VM → Run workflow** → pastикan hijau + notif Discord masuk
- [ ] Setelah ini: tiap `git push origin main` = auto-deploy + health-check + auto-rollback

---

## Fase 7 — Staging (opsional)

- [ ] Provision infra staging (nama beda):
  ```bash
  GCP_PROJECT=<PROJECT_ID> VM_NAME=flik-staging SQL_NAME=flik-pg-staging \
  SQL_CPU=1 SQL_MEM=3840MB FLIK_DOMAIN=staging.<DOMAIN> FLIK_LE_EMAIL=<EMAIL> \
  FLIK_GCS_BUCKET=<BUCKET> bash scripts/gcp-provision.sh
  ```
- [ ] Di VM staging: `sudo git clone -b staging <REPO_URL> /var/www/flik` → ulangi Fase 4
- [ ] GitHub → Environments → buat `staging` → isi secret-nya
- [ ] `git push origin staging` → deploy ke staging

---

## Fase 8 — Konfigurasi integrasi (lewat admin, kapan saja)

Semua fitur degrade dengan anggun kalau key kosong — isi belakangan tanpa redeploy:

- [ ] `/admin/infrastructure` → Payment (Midtrans), Email (SMTP), Realtime, dll
- [ ] `/admin/ai-settings` → provider AI (OpenAI/DeepSeek/dll) + Test Connection
- [ ] `/admin/integration-guide` → langkah tiap layanan
- [ ] Google login, TMDB, Mailchimp, Turnstile — sesuai kebutuhan

---

## Pre-flight (opsional, sebelum prod)

- [ ] Tes 121 migrasi di Postgres bersih: `bash scripts/check-pg-migrations.sh` (butuh Docker)
- [ ] Pastikan bucket GCS **private** + lifecycle backup aktif (`gsutil lifecycle get gs://<BUCKET>`)
- [ ] Cloud SQL: backup harian + PITR aktif (default dari provisioning)

---

## Rangkuman alur

```
Fase 0 prasyarat → 1 GCS → 2 provision (VM+Cloud SQL) → 3 DNS/HTTPS
→ 4 deploy pertama (.env, migrate, worker) → 5 verifikasi
→ 6 CI/CD → (7 staging) → 8 integrasi via admin
```
Setelah Fase 6, hidupmu = `git push` → otomatis (build, backup, migrate, health-check, rollback, notif).
