#!/usr/bin/env bash
#
# vm-startup.sh — GCE startup script (runs as root on first boot).
# Dipasang oleh scripts/gcp-provision.sh via --metadata-from-file=startup-script.
#
# Tugas:
#   1) install PHP 8.2 + ext, nginx, ffmpeg, redis, composer, node 20
#   2) install Cloud SQL Auth Proxy + systemd service (auto-start, 127.0.0.1:5432)
#   3) install 'flik-worker' systemd service (queue:work) — enabled, START manual
#      setelah kode + .env siap
#   4) cron Laravel scheduler (schedule:run tiap menit)
#
# Connection name Cloud SQL dibaca dari instance metadata 'flik-sql-connection'.
# Semua log → /var/log/flik-startup.log. Idempoten (skip kalau sudah pernah jalan).
#
set -euo pipefail
exec > /var/log/flik-startup.log 2>&1
echo "=== FLiK VM startup $(date -u) ==="

if [ -f /etc/flik/.provisioned ]; then
  echo "Sudah pernah provision — skip."
  exit 0
fi

export DEBIAN_FRONTEND=noninteractive
APP_DIR=/var/www/flik
PROXY_VER=v2.11.0

# ── 1. Dependencies ───────────────────────────────────────────
apt-get update
apt-get install -y \
  php8.2 php8.2-fpm php8.2-cli php8.2-mbstring php8.2-xml php8.2-curl \
  php8.2-zip php8.2-gd php8.2-bcmath php8.2-pgsql php8.2-mysql php8.2-redis \
  nginx git unzip ffmpeg redis-server curl ca-certificates gnupg \
  postgresql-client default-mysql-client     # untuk db-backup.sh (pre-migrate)

# Composer
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Node 20 (untuk `npm run build`)
curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
apt-get install -y nodejs

# PostgreSQL 16 client — pg_dump WAJIB >= versi server Cloud SQL (16). Default Debian 12
# cuma client 15 → "server version mismatch" saat db-backup.sh. PGDG repo punya client 16.
apt-get install -y postgresql-common || true
/usr/share/postgresql-common/pgdg/apt.postgresql.org.sh -y || true
apt-get install -y postgresql-client-16 || true

# Google Cloud CLI (gsutil) — upload backup DB offsite ke GCS (pakai service account VM, tanpa key)
curl -fsSL https://packages.cloud.google.com/apt/doc/apt-key.gpg | gpg --dearmor -o /usr/share/keyrings/cloud.google.gpg
echo "deb [signed-by=/usr/share/keyrings/cloud.google.gpg] https://packages.cloud.google.com/apt cloud-sdk main" > /etc/apt/sources.list.d/google-cloud-sdk.list
apt-get update && apt-get install -y google-cloud-cli || echo "⚠️  gagal install google-cloud-cli — offsite backup nonaktif"

# ── 2. Cloud SQL Auth Proxy ───────────────────────────────────
curl -o /usr/local/bin/cloud-sql-proxy \
  "https://storage.googleapis.com/cloud-sql-connectors/cloud-sql-proxy/${PROXY_VER}/cloud-sql-proxy.linux.amd64"
chmod +x /usr/local/bin/cloud-sql-proxy

# Ambil connection name dari metadata (diset oleh gcp-provision.sh)
META="http://metadata.google.internal/computeMetadata/v1/instance/attributes"
CONN="$(curl -s -H 'Metadata-Flavor: Google' "$META/flik-sql-connection" || true)"

mkdir -p /etc/flik
printf 'FLIK_SQL_CONNECTION=%s\n' "$CONN" > /etc/flik/proxy.env

mkdir -p "$APP_DIR"
chown -R www-data:www-data "$APP_DIR"

# Folder snapshot DB pre-migrate (lihat scripts/db-backup.sh).
mkdir -p /var/backups/flik

# ── 3. systemd: Cloud SQL Auth Proxy ──────────────────────────
cat > /etc/systemd/system/cloud-sql-proxy.service <<'UNIT'
[Unit]
Description=Cloud SQL Auth Proxy (FLiK)
After=network-online.target
Wants=network-online.target

[Service]
EnvironmentFile=/etc/flik/proxy.env
ExecStart=/usr/local/bin/cloud-sql-proxy --port 5432 ${FLIK_SQL_CONNECTION}
Restart=always
RestartSec=3
User=www-data

[Install]
WantedBy=multi-user.target
UNIT

# ── 3b. systemd: queue worker ─────────────────────────────────
cat > /etc/systemd/system/flik-worker.service <<UNIT
[Unit]
Description=FLiK queue worker
After=network-online.target cloud-sql-proxy.service
Wants=cloud-sql-proxy.service

[Service]
WorkingDirectory=${APP_DIR}
ExecStart=/usr/bin/php ${APP_DIR}/artisan queue:work --queue=transcoding,ai-realtime,ai-batch,notifications,audit,default --timeout=7200 --sleep=3 --tries=2
Restart=always
RestartSec=5
User=www-data

[Install]
WantedBy=multi-user.target
UNIT

systemctl daemon-reload

# Proxy bisa langsung jalan (VM service account sudah punya roles/cloudsql.client).
systemctl enable cloud-sql-proxy.service
[ -n "$CONN" ] && systemctl start cloud-sql-proxy.service || echo "⚠️  flik-sql-connection metadata kosong — start proxy manual setelah set /etc/flik/proxy.env"

# Worker di-ENABLE (auto-start saat boot berikutnya) tapi BELUM di-start:
# butuh kode + .env dulu. Setelah deploy: `sudo systemctl start flik-worker`.
systemctl enable flik-worker.service

# ── 4. Scheduler (cron) ───────────────────────────────────────
cat > /etc/cron.d/flik-scheduler <<CRON
* * * * * www-data cd ${APP_DIR} && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
CRON
chmod 0644 /etc/cron.d/flik-scheduler

# ── 5. nginx site + HTTPS (Let's Encrypt) ─────────────────────
DOMAIN="$(curl -s -H 'Metadata-Flavor: Google' "$META/flik-domain" || true)"
LE_EMAIL="$(curl -s -H 'Metadata-Flavor: Google' "$META/flik-letsencrypt-email" || true)"
SERVER_NAME="${DOMAIN:-_}"

# Quoted heredoc supaya $uri / $fastcgi_* TIDAK di-expand bash; server_name disisipkan via sed.
cat > /etc/nginx/sites-available/flik <<'NGINX'
server {
    listen 80;
    listen [::]:80;
    server_name __SERVER_NAME__;
    root /var/www/flik/public;
    index index.php index.html;

    client_max_body_size 5G;          # samakan dgn public/.user.ini (upload besar)

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
NGINX
sed -i "s/__SERVER_NAME__/${SERVER_NAME}/" /etc/nginx/sites-available/flik

ln -sf /etc/nginx/sites-available/flik /etc/nginx/sites-enabled/flik
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx || echo "⚠️  nginx config error — cek 'nginx -t'"

# HTTPS otomatis bila domain diset + DNS sudah mengarah ke IP VM ini.
if [ -n "$DOMAIN" ]; then
  apt-get install -y certbot python3-certbot-nginx || echo "⚠️  gagal install certbot"
  if [ -n "$LE_EMAIL" ]; then
    certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos -m "$LE_EMAIL" --redirect \
      || echo "⚠️  certbot gagal (DNS '$DOMAIN' belum mengarah ke VM?). Coba lagi nanti: sudo certbot --nginx -d $DOMAIN"
  else
    certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos --register-unsafely-without-email --redirect \
      || echo "⚠️  certbot gagal. Coba lagi nanti: sudo certbot --nginx -d $DOMAIN"
  fi
  # certbot memasang systemd timer 'certbot.timer' untuk auto-renew.
else
  echo "ℹ️  flik-domain metadata kosong → HTTP saja. Set domain (DNS A → IP VM) lalu: sudo certbot --nginx -d <domain>"
fi

touch /etc/flik/.provisioned
echo "=== ✅ FLiK VM siap. Deploy kode ke ${APP_DIR}, isi .env, lalu: systemctl start flik-worker ==="
