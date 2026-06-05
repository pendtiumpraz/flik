#!/usr/bin/env bash
#
# finish-deploy.sh — selesaikan deploy pertama FLiK di VM (jalankan sebagai root):
#   sudo bash scripts/finish-deploy.sh
#
# Idempoten: aman diulang. composer install (prod), key/storage/migrate/seed,
# tulis nginx site + aktifkan, start worker.
#
set -euo pipefail

APP_DIR=/var/www/flik
cd "$APP_DIR"

chown -R www-data:www-data "$APP_DIR"
W() { sudo -u www-data env HOME="$APP_DIR" COMPOSER_MEMORY_LIMIT=-1 "$@"; }
W git config --global --add safe.directory "$APP_DIR" || true

echo "▶️  composer install (prod)"
rm -f composer.lock
W composer install --no-dev --optimize-autoloader

[ -f .env ] || { echo "❌ .env belum ada di $APP_DIR — buat dulu, lalu ulangi."; exit 1; }
grep -q '^APP_KEY=base64' .env || W php artisan key:generate

echo "▶️  storage:link + migrate + seed"
W php artisan storage:link || true
W php artisan migrate --force
W php artisan db:seed --force || echo "(seed dilewati / sudah ada data)"
W php artisan optimize

echo "▶️  nginx site"
cat > /etc/nginx/sites-available/flik <<'NGINX'
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;
    root /var/www/flik/public;
    index index.php index.html;
    client_max_body_size 5G;

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
    location ~ /\.(?!well-known).* { deny all; }
}
NGINX
ln -sf /etc/nginx/sites-available/flik /etc/nginx/sites-enabled/flik
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx

echo "▶️  worker"
systemctl start flik-worker || true

IP="$(curl -s -H 'Metadata-Flavor: Google' 'http://metadata.google.internal/computeMetadata/v1/instance/network-interfaces/0/access-configs/0/external-ip' 2>/dev/null || echo '<VM_IP>')"
echo "✅ SELESAI — buka http://$IP  (login admin@gmail.com / password, langsung ganti!)"
