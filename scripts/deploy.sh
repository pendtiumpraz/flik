#!/usr/bin/env bash
#
# deploy.sh — dijalankan DI VM (sebagai root, via `sudo bash`) oleh GitHub Actions
# SETELAH kode terbaru di-pull. Build + migrate + cache + restart worker.
#
# Catatan: git pull dilakukan di workflow (bukan di sini) supaya file deploy.sh
# tidak menimpa dirinya sendiri saat sedang dieksekusi.
#
set -euo pipefail

APP_DIR="${FLIK_APP_DIR:-/var/www/flik}"

# Jalankan perintah sebagai www-data dengan HOME/COMPOSER_HOME yang writable
# (npm/composer butuh HOME untuk cache).
run_www() { sudo -u www-data env HOME="$APP_DIR" COMPOSER_HOME="$APP_DIR/.composer" "$@"; }

cd "$APP_DIR"

echo "▶️  composer install"
run_www composer install --no-dev --optimize-autoloader --no-interaction

echo "▶️  build assets"
run_www bash -c 'npm ci && npm run build'

echo "▶️  DB backup (pre-migrate)"
bash "$APP_DIR/scripts/db-backup.sh"   # gagal di sini → deploy.sh gagal (set -e) → CI rollback

echo "▶️  migrate"
run_www php artisan migrate --force

echo "▶️  cache (config/route/view/event)"
run_www php artisan optimize
run_www php artisan event:cache || true

echo "▶️  restart worker + reload php-fpm"
run_www php artisan queue:restart || true   # non-kritis: sinyal restart worker; jangan gagalkan deploy
systemctl reload php8.2-fpm || true

echo "✅ Deploy selesai @ $(date -u)"
