# Encrypted Backup & Restore

Operational runbook for the FLiK encrypted backup pipeline.

- **Service**: [`app/Services/Backup/BackupService.php`](../../app/Services/Backup/BackupService.php)
- **Runner**: [`app/Console/Commands/RunBackup.php`](../../app/Console/Commands/RunBackup.php) — `php artisan flik:backup`
- **Restore**: [`app/Console/Commands/RestoreBackup.php`](../../app/Console/Commands/RestoreBackup.php) — `php artisan flik:backup:restore`
- **Config**: [`config/backup.php`](../../config/backup.php)
- **Schedule**: registered in [`app/Console/Kernel.php`](../../app/Console/Kernel.php) — `dailyAt('01:00')` Asia/Jakarta

## Pipeline

```
dump  →  archive  →  encrypt  →  upload  →  prune
 sql       tar.gz     tar.gz.enc  remote     >N days
```

| Step | Tooling | Output |
| ---- | ------- | ------ |
| `dump` | `mysqldump` / `pg_dump` (per active DB driver) via `proc_open` | `storage/app/private/backups/db_<ts>.sql` |
| `archive` | `tar -czf` (or PHP `PharData` fallback) | `db_<ts>.tar.gz` |
| `encrypt` | `openssl enc -aes-256-cbc -pbkdf2 -salt` (or chunked PHP `openssl_encrypt`) | `db_<ts>.tar.gz.enc` |
| `upload` | `CdnStorageContract::putStream()` (Bunny / S3) | `backups/<YYYY-MM-DD>/db_<ts>.tar.gz.enc` |
| `prune` | local `unlink` + `CdnStorageContract::delete()` | files older than `BACKUP_RETENTION_DAYS` removed |

## Setup

### 1. Generate the encryption key

```bash
php artisan key:generate --show
```

Copy the output (a 32-byte base64 value, e.g. `base64:xxxxxxx…`) and store it
**outside the repo** — we recommend a vault / 1Password / AWS Secrets Manager
entry tagged `flik/backup`. Without this key the encrypted backups cannot be
restored. Ever.

### 2. Set environment variables

```dotenv
BACKUP_ENCRYPTION_KEY=base64:…       # required — see step 1
BACKUP_REMOTE_DISK=bunny             # bunny | s3
BACKUP_RETENTION_DAYS=30             # optional, default 30
BACKUP_MAX_MEDIA_BYTES=5000000000    # optional, default 5 GB per extra dir
BACKUP_NOTIFY_ADMINS=true            # optional, email super_admins on done

# Optional binary overrides (for non-standard installs)
BACKUP_MYSQLDUMP_BIN=/usr/local/bin/mysqldump
BACKUP_PG_DUMP_BIN=/opt/homebrew/bin/pg_dump
BACKUP_OPENSSL_BIN=/usr/bin/openssl
BACKUP_TAR_BIN=/usr/bin/tar
BACKUP_PREFER_OPENSSL_BIN=true       # false → pure-PHP encrypt path
```

The remote disk credentials reuse the existing variables:

- **Bunny**: `BUNNY_STORAGE_ZONE`, `BUNNY_STORAGE_KEY`, `BUNNY_PULL_ZONE_URL`,
  `BUNNY_TOKEN_KEY`
- **S3**: `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`,
  `AWS_BUCKET`

### 3. Create the local working dir (first run only)

```bash
mkdir -p storage/app/private/backups
chmod 750 storage/app/private/backups
```

The runner will create it on demand if missing — these commands just give it
the right perms upfront on a fresh deploy.

### 4. Verify with a dry run

```bash
php artisan flik:backup --dry
```

This logs every step it would take without writing or uploading anything.
Use it to confirm the schedule wiring before flipping the cron live.

## Manual run

```bash
php artisan flik:backup                # full pipeline
php artisan flik:backup --no-upload    # local-only (writes .enc to storage/app/private/backups)
php artisan flik:backup --dry          # log only
```

Each step prints its output path + duration; on completion the command emits a
single `Backup COMPLETED in Xs.` summary and (if `BACKUP_NOTIFY_ADMINS=true`)
emails every `super_admin` user with a per-step report.

### Dev-mode behaviour

If `mysqldump` / `pg_dump` is not on `PATH` the dump step **does not fail** —
it logs a warning and writes a placeholder `.sql` file so the rest of the
pipeline (archive → encrypt → upload → prune) can still be exercised
end-to-end. This keeps local development frictionless without a full DB
toolchain. Production hosts MUST have the real binary installed; check
deploy-time with `which mysqldump`.

## Restore procedure

> :warning: **DESTRUCTIVE.** A restore overwrites the contents of the active
> database connection. Always run against a maintenance-mode app and
> double-check the target DB before continuing.

### 1. Put the app in maintenance mode

```bash
php artisan down --refresh=120 --secret="rotate-me"
```

### 2. Identify the backup to restore

```bash
# List remote backups (Bunny)
curl -H "AccessKey: ${BUNNY_STORAGE_KEY}" \
  "https://${BUNNY_STORAGE_HOSTNAME:-storage.bunnycdn.com}/${BUNNY_STORAGE_ZONE}/backups/" | jq .
```

Or, locally:

```bash
ls -lh storage/app/private/backups/
```

### 3. Run the restore command

```bash
# Pull latest from Bunny by basename — the command resolves the full path
php artisan flik:backup:restore db_20260513_010000.tar.gz.enc

# From local disk
php artisan flik:backup:restore /absolute/path/to/db_20260513_010000.tar.gz.enc \
  --from-disk=local

# Unattended (skip confirmations)
php artisan flik:backup:restore db_20260513_010000.tar.gz.enc --force

# Just decrypt + untar, do NOT touch the DB
php artisan flik:backup:restore db_20260513_010000.tar.gz.enc --extract-only
```

The restore command:

1. Downloads the `.enc` file from the remote disk (or reads it locally).
2. Decrypts it using `BACKUP_ENCRYPTION_KEY`.
3. Untars it into a working directory.
4. Pipes the bundled `.sql` into `mysql` / `psql` for the active connection.

It prints each step + the resolved working dir for inspection.

### 4. Take the app out of maintenance

```bash
php artisan up
```

### 5. Smoke-test

- Log in as `admin@gmail.com` / restored password.
- Hit `/healthz/detailed` and verify DB / cache / queue are green.
- Watch `storage/logs/laravel.log` for any post-restore errors.

## Manual decryption (fallback)

Our encrypted format is bit-for-bit compatible with the OpenSSL CLI. If our
artisan tooling is unavailable, an operator with the encryption key can
decrypt manually:

```bash
openssl enc -d -aes-256-cbc -pbkdf2 -in db_20260513.tar.gz.enc -out db_20260513.tar.gz
tar -xzf db_20260513.tar.gz
mysql -u <user> -p <database> < db_20260513.sql
```

## Disaster recovery targets

| Target | Value | Rationale |
| ------ | ----- | --------- |
| **RPO** (Recovery Point Objective) | **24 hours** | Backups run daily at 01:00 Asia/Jakarta. Worst case a failure at 00:59 means losing ~24h of writes. |
| **RTO** (Recovery Time Objective) | **2 hours** | Includes: 10 min for triage, 30 min download from CDN, 15 min decrypt + untar, 30 min restore SQL, 30 min app verification. Scales with DB size — measured against the current ~2 GB dump. |
| **Retention** | **30 days** rolling | `BACKUP_RETENTION_DAYS=30`. Older backups pruned both locally and from the remote disk. |
| **Geo redundancy** | Single Bunny region (default) | For multi-region failover, set `BACKUP_REMOTE_DISK=s3` and configure a second cron with `--no-upload` followed by a manual `aws s3 cp` to a backup region. |
| **Encryption** | AES-256-CBC + PBKDF2 (10 000 iters) + 8-byte salt | OpenSSL `enc -salt -pbkdf2` compatible. Key rotation requires re-encrypting historical backups (see "Key rotation" below). |

## Key rotation

The encryption key is the single most sensitive secret in the backup pipeline.
Rotate it in this order:

1. Generate a new key: `php artisan key:generate --show`.
2. Decrypt the most recent backup with the OLD key (see "Manual decryption"),
   re-encrypt it with the NEW key (`openssl enc -aes-256-cbc -pbkdf2 -salt -k
   '<new-key>'`).
3. Upload the re-encrypted artefact to the same remote path.
4. Update `BACKUP_ENCRYPTION_KEY` in your secrets store and redeploy.
5. Wait one full retention window (default 30 days) before destroying the OLD
   key — older backups in the rotation are still encrypted with it.

## Failure escalation

| Situation | Action |
| --------- | ------ |
| `flik:backup` fails for 2 consecutive nights | Page on-call ops via `#flik-incident`. The notification mail to super_admins includes the failed step + stderr. |
| Restore command errors with "openssl_decrypt failed (wrong key?)" | The `BACKUP_ENCRYPTION_KEY` does not match the key the file was encrypted with. Try the previous key from the secrets store rotation log. |
| Restore command succeeds but the app is broken | The `.sql` may be from a future schema. Run `php artisan migrate:status` to compare; you may need to roll the app back to the same release as the backup. |
| Upload step times out repeatedly | Check Bunny storage zone quota + the network egress from the app host. Run with `--no-upload` to keep producing local backups while the remote is investigated. |

## Related docs

- [`docs/security/dast-runbook.md`](dast-runbook.md) — DAST workflow.
- [`docs/security/sql-injection-audit.md`](sql-injection-audit.md) — SQLi audit trail.
