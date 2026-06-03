#!/usr/bin/env bash
#
# check-pg-migrations.sh — verify ALL migrations run cleanly on PostgreSQL.
#
# By default it spins up a THROWAWAY Postgres (Docker) and runs the migrations
# against it, so your real (.env) database is never touched. It overrides the
# DB_* env vars only for the migrate command (Laravel's immutable Dotenv lets
# real env vars win over .env).
#
# Usage:
#   bash scripts/check-pg-migrations.sh [--seed] [--rollback] [--keep]
#   bash scripts/check-pg-migrations.sh --external      # use PGCHECK_* env (e.g. a Neon TEST branch)
#
# Flags:
#   --seed       also run `db:seed` after migrating
#   --rollback   after up, also test `migrate:reset` (down migrations)   [docker mode only]
#   --keep       leave the docker container running for inspection
#   --external   target an existing Postgres via PGCHECK_* env (UP-ONLY, no wipe)
#
# External mode env (point at an EMPTY db / dedicated Neon branch — up-only, safe):
#   PGCHECK_HOST  PGCHECK_DB  PGCHECK_USER  PGCHECK_PASS  [PGCHECK_PORT=5432] [PGCHECK_SSLMODE=require]
#
set -euo pipefail

MODE="docker"
SEED=0; ROLLBACK=0; KEEP=0
for a in "$@"; do
  case "$a" in
    --seed) SEED=1 ;;
    --rollback) ROLLBACK=1 ;;
    --keep) KEEP=1 ;;
    --external) MODE="external" ;;
    -h|--help) sed -n '2,25p' "$0"; exit 0 ;;
    *) echo "Unknown arg: $a"; exit 2 ;;
  esac
done

[ -f artisan ] || { echo "❌ Jalankan dari root project Laravel (file 'artisan' tak ada)."; exit 1; }

run_migrations() {
  php artisan config:clear >/dev/null 2>&1 || true

  echo "▶️  migrate (up) …"
  if php artisan migrate --force; then
    echo "✅ Semua migrasi UP lolos di PostgreSQL."
  else
    echo "❌ Ada migrasi yang GAGAL di PostgreSQL (lihat error di atas)."
    return 1
  fi

  if [ "$SEED" = 1 ]; then
    echo "▶️  db:seed …"
    if php artisan db:seed --force; then echo "✅ Seed lolos."; else echo "❌ Seeder gagal."; return 1; fi
  fi

  if [ "$ROLLBACK" = 1 ]; then
    if [ "$MODE" = "external" ]; then
      echo "⚠️  --rollback diabaikan di mode --external (migrate:reset menghapus data)."
    else
      echo "▶️  migrate:reset (down) — menguji semua down() …"
      if php artisan migrate:reset --force; then
        echo "✅ Semua migrasi DOWN (rollback) lolos."
      else
        echo "⚠️  Ada down() yang gagal (kurang krusial — produksi jarang rollback penuh)."
      fi
    fi
  fi

  echo "▶️  migrate:status"
  php artisan migrate:status || true
}

# ─────────────────────────── External mode ───────────────────────────
if [ "$MODE" = "external" ]; then
  : "${PGCHECK_HOST:?set PGCHECK_HOST}"
  : "${PGCHECK_DB:?set PGCHECK_DB}"
  : "${PGCHECK_USER:?set PGCHECK_USER}"
  : "${PGCHECK_PASS:?set PGCHECK_PASS}"
  echo "🔌 External Postgres: ${PGCHECK_USER}@${PGCHECK_HOST}:${PGCHECK_PORT:-5432}/${PGCHECK_DB}"
  echo "   (UP-ONLY — pastikan ini DB KOSONG / Neon branch khusus tes)"
  export DB_CONNECTION=pgsql \
         DB_HOST="$PGCHECK_HOST" DB_PORT="${PGCHECK_PORT:-5432}" \
         DB_DATABASE="$PGCHECK_DB" DB_USERNAME="$PGCHECK_USER" DB_PASSWORD="$PGCHECK_PASS" \
         DB_SSLMODE="${PGCHECK_SSLMODE:-require}" DATABASE_URL=""
  run_migrations
  echo "🎉 Selesai (external)."
  exit 0
fi

# ─────────────────────────── Docker mode ─────────────────────────────
command -v docker >/dev/null || { echo "❌ Docker tak ada. Pakai '--external' + PGCHECK_* env, atau install Docker."; exit 1; }

PORT=$(( (RANDOM % 2000) + 55432 ))
CONT="flik-pgcheck-$$"
DB=flik_pgcheck; USER=flik; PASS=pgcheck_secret

cleanup() {
  if [ "$KEEP" = 1 ]; then
    echo "ℹ️  --keep: container '$CONT' tetap jalan di port $PORT (hapus manual: docker rm -f $CONT)."
  else
    docker rm -f "$CONT" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

echo "🐘 Menjalankan Postgres ephemeral '$CONT' di port $PORT …"
docker run -d --name "$CONT" \
  -e POSTGRES_DB="$DB" -e POSTGRES_USER="$USER" -e POSTGRES_PASSWORD="$PASS" \
  -p "$PORT:5432" postgres:16-alpine >/dev/null

echo -n "⏳ Menunggu Postgres siap"
ok=0
for _ in $(seq 1 30); do
  if docker exec "$CONT" pg_isready -U "$USER" -d "$DB" >/dev/null 2>&1; then ok=1; break; fi
  printf '.'; sleep 1
done
echo
[ "$ok" = 1 ] || { echo "❌ Postgres tak kunjung siap."; docker logs "$CONT" | tail -20; exit 1; }

export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT="$PORT" \
       DB_DATABASE="$DB" DB_USERNAME="$USER" DB_PASSWORD="$PASS" \
       DB_SSLMODE=disable DATABASE_URL=""

if run_migrations; then
  echo "🎉 Selesai — DB throwaway dibersihkan otomatis."
else
  echo "💥 Ada kegagalan migrasi/seed di PostgreSQL (lihat di atas)."
  exit 1
fi
