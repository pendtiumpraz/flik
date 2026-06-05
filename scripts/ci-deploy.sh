#!/usr/bin/env bash
#
# ci-deploy.sh — dijalankan DI VM oleh GitHub Actions SETELAH git reset ke ref baru.
# Build (deploy.sh) → health-check /healthz → kalau gagal, rollback ke $PREV.
# Env yang diharapkan: PREV (commit sebelumnya), TARGET_REF (nama branch).
#
set +e

APP_DIR=/var/www/flik
cd "$APP_DIR" || exit 1
echo "Deploy branch=${TARGET_REF:-?}  (rollback target=${PREV:-?})"

health() { curl -fsS -o /dev/null --max-time 15 http://127.0.0.1/healthz; }

sudo bash "$APP_DIR/scripts/deploy.sh"; rc=$?
sleep 3
health; hc=$?

if [ "$rc" -eq 0 ] && [ "$hc" -eq 0 ]; then
  echo "✅ Deploy + health-check OK."
  exit 0
fi

echo "❌ Deploy/health GAGAL (deploy=$rc health=$hc) — rollback ke ${PREV:-?}"
if [ -n "${PREV:-}" ]; then
  sudo -u www-data env HOME="$APP_DIR" git reset --hard "$PREV"
  sudo bash "$APP_DIR/scripts/deploy.sh"
  sleep 3
  health && echo "↩️  Rollback berhasil ke $PREV." || echo "⚠️  Rollback build/health gagal — cek VM manual."
fi
exit 1
