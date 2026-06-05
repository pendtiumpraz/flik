#!/usr/bin/env bash
#
# gcp-provision.sh — provision GCP infra for FLiK:
#   1) Cloud SQL for PostgreSQL (instance + database + app user)
#   2) Compute Engine VM (web + worker + ffmpeg host)
#   3) Firewall (HTTP/HTTPS) + IAM (Cloud SQL Auth Proxy access)
#
# ⚠️  Membuat resource BERBAYAR di GCP. Pakai --dry-run dulu untuk preview.
#
# Usage:
#   bash scripts/gcp-provision.sh --dry-run          # cetak perintah, TIDAK eksekusi
#   bash scripts/gcp-provision.sh                    # eksekusi (minta konfirmasi)
#   bash scripts/gcp-provision.sh --yes              # eksekusi tanpa konfirmasi
#
# Override via env (atau edit blok CONFIG di bawah):
#   GCP_PROJECT  GCP_REGION  GCP_ZONE  VM_NAME  VM_MACHINE
#   SQL_NAME  SQL_CPU  SQL_MEM  SQL_DB  SQL_USER  SQL_PASS  SQL_HA  PG_VERSION
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

# ─────────────────────────── CONFIG ───────────────────────────
PROJECT="${GCP_PROJECT:-$(gcloud config get-value project 2>/dev/null || true)}"
REGION="${GCP_REGION:-asia-southeast2}"        # Jakarta
ZONE="${GCP_ZONE:-${REGION}-a}"

VM_NAME="${VM_NAME:-flik-app}"
VM_MACHINE="${VM_MACHINE:-e2-standard-2}"      # 2 vCPU / 8GB

SQL_NAME="${SQL_NAME:-flik-pg}"
SQL_CPU="${SQL_CPU:-2}"
SQL_MEM="${SQL_MEM:-8GiB}"                      # custom: kelipatan 256MB
SQL_DB="${SQL_DB:-velflix}"
SQL_USER="${SQL_USER:-velflix}"
SQL_HA="${SQL_HA:-ZONAL}"                       # REGIONAL untuk HA produksi
PG_VERSION="${PG_VERSION:-POSTGRES_16}"
SQL_PASS="${SQL_PASS:-}"                        # digenerate kalau kosong

DOMAIN="${FLIK_DOMAIN:-}"                        # mis. flik.example.com — kosong = HTTP saja
LE_EMAIL="${FLIK_LE_EMAIL:-}"                    # email Let's Encrypt (opsional)
GCS_BUCKET="${FLIK_GCS_BUCKET:-}"                # bucket GCS untuk offsite DB backup (= AWS_BUCKET app)

# Cloud SQL connection name = project:region:instance (deterministik).
CONN="${PROJECT}:${REGION}:${SQL_NAME}"

# Metadata yang dibaca vm-startup.sh (connection name + domain/HTTPS).
METADATA="flik-sql-connection=$CONN"
if [ -n "$DOMAIN" ]; then METADATA="$METADATA,flik-domain=$DOMAIN"; fi
if [ -n "$LE_EMAIL" ]; then METADATA="$METADATA,flik-letsencrypt-email=$LE_EMAIL"; fi

DRY=0; YES=0
for a in "$@"; do
  case "$a" in
    --dry-run) DRY=1 ;;
    --yes) YES=1 ;;
    -h|--help) sed -n '2,22p' "$0"; exit 0 ;;
    *) echo "Unknown arg: $a"; exit 2 ;;
  esac
done

command -v gcloud >/dev/null || { echo "❌ gcloud CLI tak ada. Install Google Cloud SDK dulu."; exit 1; }
[ -n "$PROJECT" ] || { echo "❌ Project belum diset. Jalankan 'gcloud config set project <id>' atau set GCP_PROJECT."; exit 1; }

if [ -z "$SQL_PASS" ]; then
  SQL_PASS="$(openssl rand -base64 18 | tr -d '/+=' )"
  GENERATED_PASS=1
fi

echo "──────────────────────────────────────────────"
echo " Project   : $PROJECT"
echo " Region/Zone: $REGION / $ZONE"
echo " VM        : $VM_NAME ($VM_MACHINE)"
echo " Cloud SQL : $SQL_NAME ($PG_VERSION, ${SQL_CPU}vCPU/${SQL_MEM}, HA=$SQL_HA)"
echo " DB / user : $SQL_DB / $SQL_USER"
echo " Domain    : ${DOMAIN:-(none → HTTP saja)}"
echo " GCS backup: ${GCS_BUCKET:-(none → offsite backup nonaktif)}"
echo " Mode      : $([ "$DRY" = 1 ] && echo 'DRY-RUN (preview)' || echo 'EXECUTE')"
echo "──────────────────────────────────────────────"

if [ "$DRY" = 0 ] && [ "$YES" = 0 ]; then
  read -r -p "Lanjut buat resource BERBAYAR ini? ketik 'yes': " ans
  [ "$ans" = "yes" ] || { echo "Dibatalkan."; exit 0; }
fi

# run: echo the command; execute unless --dry-run
run() { echo "+ $*"; [ "$DRY" = 1 ] || "$@"; }

# ─────────────────────── 1. Enable APIs ───────────────────────
run gcloud services enable compute.googleapis.com sqladmin.googleapis.com --project="$PROJECT"

# ─────────────────── 2. Cloud SQL PostgreSQL ──────────────────
run gcloud sql instances create "$SQL_NAME" \
  --project="$PROJECT" \
  --database-version="$PG_VERSION" \
  --region="$REGION" \
  --cpu="$SQL_CPU" --memory="$SQL_MEM" \
  --storage-auto-increase \
  --availability-type="$SQL_HA" \
  --backup --enable-point-in-time-recovery

run gcloud sql databases create "$SQL_DB" --instance="$SQL_NAME" --project="$PROJECT"
run gcloud sql users create "$SQL_USER" --instance="$SQL_NAME" --password="$SQL_PASS" --project="$PROJECT"

# ────────────────────── 3. Compute VM ─────────────────────────
run gcloud compute instances create "$VM_NAME" \
  --project="$PROJECT" --zone="$ZONE" \
  --machine-type="$VM_MACHINE" \
  --image-family=debian-12 --image-project=debian-cloud \
  --scopes=cloud-platform \
  --tags=http-server,https-server \
  --metadata="$METADATA" \
  --metadata-from-file=startup-script="$SCRIPT_DIR/vm-startup.sh"

# ──────────────── 4. Firewall HTTP/HTTPS ──────────────────────
run gcloud compute firewall-rules create flik-allow-web \
  --project="$PROJECT" \
  --allow=tcp:80,tcp:443 \
  --source-ranges=0.0.0.0/0 \
  --target-tags=http-server,https-server \
  --direction=INGRESS

# ───────────── 5. IAM: VM boleh konek Cloud SQL ───────────────
if [ "$DRY" = 0 ]; then
  SA="$(gcloud compute instances describe "$VM_NAME" --zone="$ZONE" --project="$PROJECT" --format='value(serviceAccounts[0].email)')"
  VM_IP="$(gcloud compute instances describe "$VM_NAME" --zone="$ZONE" --project="$PROJECT" --format='value(networkInterfaces[0].accessConfigs[0].natIP)')"
else
  SA="<vm-service-account>"; VM_IP="<vm-external-ip>"
fi

run gcloud projects add-iam-policy-binding "$PROJECT" \
  --member="serviceAccount:$SA" \
  --role="roles/cloudsql.client"

# Akses tulis ke bucket GCS untuk offsite DB backup (gsutil dari VM) — bucket-scoped.
if [ -n "$GCS_BUCKET" ]; then
  if [ "$DRY" = 1 ]; then
    echo "+ (dry-run) grant roles/storage.objectAdmin pada gs://$GCS_BUCKET ke $SA"
  else
    echo "+ grant roles/storage.objectAdmin pada gs://$GCS_BUCKET ke $SA"
    gcloud storage buckets add-iam-policy-binding "gs://$GCS_BUCKET" \
      --member="serviceAccount:$SA" --role="roles/storage.objectAdmin" \
      || echo "⚠️  Gagal grant storage IAM — pastikan bucket '$GCS_BUCKET' sudah ada, atau set manual."
  fi
fi

# ─────────────────────── Ringkasan ────────────────────────────
echo
if [ "$DRY" = 1 ]; then echo "✅ Selesai (DRY-RUN — tidak ada yang dibuat)."; else echo "✅ Selesai."; fi
echo "──────────────────────────────────────────────"
echo " Cloud SQL connection name : $CONN"
echo " VM external IP            : $VM_IP"
echo " DB / user                : $SQL_DB / $SQL_USER"
if [ "${GENERATED_PASS:-0}" = 1 ]; then
  echo " DB password (GENERATED)  : $SQL_PASS"
  echo "   ⚠️  SIMPAN password ini sekarang — tidak ditampilkan lagi."
fi
echo "──────────────────────────────────────────────"
cat <<EOF

VM menjalankan startup-script otomatis saat boot pertama. Pantau:
  gcloud compute ssh $VM_NAME --zone $ZONE --command 'sudo tail -f /var/log/flik-startup.log'
Yang otomatis terpasang:
  ✓ PHP 8.2 + nginx + ffmpeg + redis + composer + node 20
  ✓ systemd 'cloud-sql-proxy'  (auto-start → 127.0.0.1:5432)
  ✓ systemd 'flik-worker'      (enabled; di-start setelah kode + .env siap)
  ✓ cron scheduler             (schedule:run tiap menit)
  ✓ nginx site + HTTPS         (certbot, jika flik-domain diset & DNS sudah mengarah ke VM)

Langkah deploy (SSH ke VM):
  sudo git clone <repo-url> /var/www/flik && cd /var/www/flik
  sudo -u www-data composer install --no-dev --optimize-autoloader
  sudo -u www-data bash -c 'npm ci && npm run build'
  # .env:  DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432
  #        DB_DATABASE=$SQL_DB DB_USERNAME=$SQL_USER DB_PASSWORD=<password di atas> DB_SSLMODE=disable
  sudo -u www-data php artisan key:generate
  sudo -u www-data php artisan storage:link
  sudo -u www-data php artisan migrate --force
  sudo systemctl start flik-worker

  # HTTPS: pastikan DNS A <domain> → $VM_IP, lalu (kalau certbot belum jalan):
  #   sudo certbot --nginx -d <domain>
EOF
