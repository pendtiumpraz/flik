# 🚀 Deploy FLiK — langkah sekarang

**Nilai infra-mu:**
- VM IP: `34.50.66.29`
- Project: `fliktv-498512`
- Cloud SQL connection: `fliktv-498512:asia-southeast2:flik-pg`
- DB / user: `velflix` / `velflix`
- DB password: **ganti `<DB_PASSWORD>` di bawah** dengan password dari output provision

---

## 1. SSH ke VM (di Cloud Shell)
```bash
gcloud compute ssh flik-app --zone asia-southeast2-a
```
(diminta passphrase → kosongin, Enter)

## 2. Deploy app — tempel SEMUA sekaligus (DI DALAM VM)
> Ganti `<DB_PASSWORD>` dulu sebelum tempel.
```bash
sudo git clone https://github.com/pendtiumpraz/flik.git /var/www/flik
sudo chown -R www-data:www-data /var/www/flik
cd /var/www/flik
sudo -u www-data env HOME=/var/www/flik composer install --no-dev --optimize-autoloader
sudo -u www-data env HOME=/var/www/flik bash -c 'npm ci && npm run build'

sudo -u www-data tee .env >/dev/null <<'EOF'
APP_NAME=FLiK
APP_ENV=production
APP_DEBUG=false
APP_URL=http://34.50.66.29
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=velflix
DB_USERNAME=velflix
DB_PASSWORD=<DB_PASSWORD>
DB_SSLMODE=disable
QUEUE_CONNECTION=database
CACHE_DRIVER=redis
SESSION_DRIVER=database
FILESYSTEM_DISK=local
MAIL_MAILER=log
EOF

sudo -u www-data php artisan key:generate
sudo -u www-data php artisan storage:link
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan db:seed --force
sudo -u www-data php artisan optimize

sudo ln -sf /etc/nginx/sites-available/flik /etc/nginx/sites-enabled/flik
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
sudo systemctl start flik-worker
```

## 3. Buka browser
**http://34.50.66.29** → login `admin@gmail.com` / `password` (langsung ganti!)

---

## Kalau error / masih "Welcome to nginx"
```bash
# app ke-deploy?
ls /var/www/flik/public/index.php && echo "APP ADA" || echo "APP BELUM"

# log error app
sudo tail -n 30 /var/www/flik/storage/logs/laravel.log

# status service
sudo systemctl status cloud-sql-proxy --no-pager
sudo systemctl status flik-worker --no-pager
sudo nginx -t
```

## Lupa DB password? Reset (di Cloud Shell):
```bash
gcloud sql users set-password velflix --instance=flik-pg --password='PasswordBaru123'
```
