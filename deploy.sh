#!/usr/bin/env bash
# FLiK deployment script. Run di server setelah git pull.
# Usage: bash deploy.sh

set -e

cd "$(dirname "$0")"

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo " FLiK Deploy"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

echo "→ [1/8] Pull latest from git..."
git pull origin main

echo "→ [2/8] Composer install (production)..."
composer install --no-dev --optimize-autoloader --prefer-dist

echo "→ [3/8] NPM clean install..."
rm -f public/hot   # critical: hapus leftover dev marker
npm ci --omit=dev

echo "→ [4/8] Build production assets..."
npm run build

echo "→ [5/8] Run migrations..."
php artisan migrate --force

echo "→ [6/8] Clear caches..."
php artisan view:clear
php artisan route:clear
php artisan config:clear
php artisan cache:clear

echo "→ [7/8] Cache for performance..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "→ [8/8] Storage symlink + permissions..."
php artisan storage:link 2>/dev/null || true
chmod -R 775 storage bootstrap/cache 2>/dev/null || true

# Optional: restart queue worker if running
# php artisan queue:restart

echo ""
echo "✓ Deploy complete."
echo "→ Hard-refresh browser (Ctrl+Shift+R) to bust browser CSS cache."
