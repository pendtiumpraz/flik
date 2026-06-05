#!/usr/bin/env bash
#
# gcs-backup-lifecycle.sh — set GCS lifecycle: hapus objek 'db-backups/'
# setelah N hari, TANPA mengganggu rule/objek lain di bucket (media dll).
#
# AMAN & IDEMPOTEN: membaca lifecycle yang ada (gsutil, format camelCase),
# membuang rule db-backups lama, lalu menambah rule baru, dan set ulang.
# Re-run = update jumlah hari, bukan duplikat.
#
# Usage:
#   bash scripts/gcs-backup-lifecycle.sh <bucket> [days]
#   FLIK_GCS_BUCKET=<bucket> FLIK_BACKUP_RETENTION_DAYS=30 bash scripts/gcs-backup-lifecycle.sh
#
set -euo pipefail

BUCKET="${1:-${FLIK_GCS_BUCKET:-}}"
DAYS="${2:-${FLIK_BACKUP_RETENTION_DAYS:-30}}"
PREFIX="${BACKUP_PREFIX:-db-backups/}"

BUCKET="${BUCKET#gs://}"   # normalisasi: terima 'gs://x' atau 'x'

[ -n "$BUCKET" ] || { echo "Usage: $0 <bucket> [days]  (atau set FLIK_GCS_BUCKET)"; exit 1; }
case "$DAYS" in (*[!0-9]*|'') echo "❌ days harus angka: '$DAYS'"; exit 1 ;; esac
command -v gsutil >/dev/null || { echo "❌ gsutil tak ada (install google-cloud-cli)."; exit 1; }
command -v jq >/dev/null     || { echo "❌ jq diperlukan untuk merge aman."; exit 1; }

echo "▶️  Ambil lifecycle gs://$BUCKET …"
RAW="$(gsutil lifecycle get "gs://$BUCKET" 2>/dev/null || true)"
case "$RAW" in
  \{*) CUR="$RAW" ;;             # sudah ada config JSON
  *)   CUR='{"rule":[]}' ;;      # belum ada lifecycle
esac

# Buang rule db-backups lama (idempotent) → tambah rule baru.
NEW="$(printf '%s' "$CUR" | jq --argjson days "$DAYS" --arg prefix "$PREFIX" '
  (.rule // [])
  | map(select(((.condition.matchesPrefix // []) | index($prefix)) | not))
  + [{action:{type:"Delete"}, condition:{age:$days, matchesPrefix:[$prefix]}}]
  | {rule: .}
')"

TMP="$(mktemp)"; printf '%s\n' "$NEW" > "$TMP"
echo "▶️  Set lifecycle: hapus '$PREFIX' setelah $DAYS hari (rule lain dipertahankan) …"
gsutil lifecycle set "$TMP" "gs://$BUCKET"
rm -f "$TMP"
echo "✅ Lifecycle GCS diperbarui untuk gs://$BUCKET."
