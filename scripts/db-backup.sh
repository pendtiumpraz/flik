#!/usr/bin/env bash
#
# db-backup.sh — snapshot database SEBELUM migrate (dipanggil deploy.sh).
#
# Driver-aware (pgsql → pg_dump -Fc, mysql → mysqldump|gzip), simpan lokal di
# /var/backups/flik dengan rotasi. EXIT NON-ZERO kalau backup gagal → deploy.sh
# (set -e) ikut gagal → CI tidak jadi migrate + rollback otomatis.
#
# Restore:
#   pgsql : pg_restore -h 127.0.0.1 -p 5432 -U <user> -d <db> --clean <file.dump>
#   mysql : gunzip -c <file.sql.gz> | mysql -h 127.0.0.1 -u <user> -p <db>
#
# Catatan: Cloud SQL juga punya backup harian + PITR (diaktifkan provisioning).
# Dump ini = jaring pengaman cepat untuk meng-undo migrasi yang breaking.
#
set -euo pipefail

APP_DIR="${FLIK_APP_DIR:-/var/www/flik}"
BACKUP_DIR="${FLIK_BACKUP_DIR:-/var/backups/flik}"
KEEP="${FLIK_BACKUP_KEEP:-10}"

# Ambil nilai dari .env (strip quotes/spasi).
envval() {
  grep -E "^$1=" "$APP_DIR/.env" 2>/dev/null | head -1 | cut -d= -f2- \
    | sed -e 's/^[[:space:]"'\'']*//' -e 's/[[:space:]"'\'']*$//'
}

DRIVER="$(envval DB_CONNECTION)"
HOST="$(envval DB_HOST)"; PORT="$(envval DB_PORT)"
DB="$(envval DB_DATABASE)"; USER="$(envval DB_USERNAME)"; PASS="$(envval DB_PASSWORD)"

if [ -z "$DRIVER" ] || [ -z "$DB" ]; then
  echo "⚠️  DB env tak lengkap di $APP_DIR/.env — skip backup"
  exit 0
fi

mkdir -p "$BACKUP_DIR"
TS="$(date -u +%Y%m%d-%H%M%S)"

case "$DRIVER" in
  pgsql)
    command -v pg_dump >/dev/null || { echo "❌ pg_dump tak ada (install postgresql-client)"; exit 1; }
    OUT="$BACKUP_DIR/${DB}-${TS}.dump"
    echo "▶️  pg_dump → $OUT"
    PGPASSWORD="$PASS" pg_dump -h "${HOST:-127.0.0.1}" -p "${PORT:-5432}" -U "$USER" -d "$DB" -Fc -f "$OUT"
    ;;
  mysql|mariadb)
    command -v mysqldump >/dev/null || { echo "❌ mysqldump tak ada (install default-mysql-client)"; exit 1; }
    OUT="$BACKUP_DIR/${DB}-${TS}.sql.gz"
    echo "▶️  mysqldump → $OUT"
    MYSQL_PWD="$PASS" mysqldump -h "${HOST:-127.0.0.1}" -P "${PORT:-3306}" -u "$USER" \
      --single-transaction --quick --routines --triggers "$DB" | gzip > "$OUT"
    ;;
  sqlite)
    echo "ℹ️  sqlite (file-based) — skip dump terpisah"; exit 0 ;;
  *)
    echo "⚠️  driver '$DRIVER' tak didukung — skip backup"; exit 0 ;;
esac

# File harus ada & tak kosong.
[ -s "$OUT" ] || { echo "❌ Backup gagal / kosong: $OUT"; exit 1; }
echo "✅ Backup OK: $OUT ($(du -h "$OUT" | cut -f1))"

# Rotasi: simpan KEEP terbaru, hapus sisanya.
ls -1t "$BACKUP_DIR"/${DB}-* 2>/dev/null | tail -n +$((KEEP + 1)) | xargs -r rm -f
echo "🧹 Rotasi: simpan $KEEP backup terbaru di $BACKUP_DIR."
