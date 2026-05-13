# PII Encryption at Rest

> Status: Done (initial rollout in migration `2026_05_10_040100_add_pii_columns_and_encryption.php`).
> Owner: Security WG.

This document describes how FLiK protects personally-identifiable information (PII) in the database. It is the authoritative reference for which columns are encrypted, how the encryption is keyed, how to rotate keys, and what queries are (and are not) possible against encrypted data.

---

## TL;DR

| Column                                  | Mechanism                                 | Searchable? |
|-----------------------------------------|-------------------------------------------|-------------|
| `users.phone`                           | `encrypted` cast (AES-256-CBC, APP_KEY)   | No          |
| `users.address`                         | `encrypted` cast                          | No          |
| `users.national_id_hash`                | SHA-256 + pepper (one-way)                | Yes (exact) |
| `users.birth_date`                      | Plaintext (needed for age verification)   | Yes         |
| `users.email`, `users.name`             | **Plaintext by design** (login lookup)    | Yes         |
| `users.two_factor_secret`               | `encrypted` cast (existing)               | No          |
| `users.two_factor_recovery_codes`       | `encrypted:array` cast (existing)         | No          |
| `subscriptions.billing_address`         | `encrypted` cast                          | No          |
| `known_devices.ip`                      | `encrypted` cast                          | No          |
| `payments.last4_card_digits` (if table) | `encrypted` cast (Midtrans holds the PAN) | No          |

For JSON columns containing sensitive answers (e.g. KYC payloads, cold-start quiz responses with sensitive demographics), use the `App\Casts\EncryptedJson` cast — see [JSON columns](#json-columns) below.

---

## What we encrypt — and why we don't encrypt everything

PII is encrypted only where the trade-off makes sense. **`email` and `name` deliberately remain plaintext** because:

- `email` is queried on every login (`User::where('email', $value)->first()`). Encrypting it would either break login or force us to also store a peppered hash, which is more complexity than the threat warrants for an already-public-by-design field (we send marketing email to it).
- `name` is rendered in the admin user list and used for moderator UX. Encrypting would require denormalising for search.

If your threat model differs (e.g. a tightly-regulated EU rollout), add a peppered hash column (`email_hash`) and adjust login to use it. The same pattern as `national_id_hash` applies.

---

## How it works

### `encrypted` cast (Laravel built-in)

Most encrypted columns use Laravel's `encrypted` (and `encrypted:array`) cast. Mechanics:

- Cipher: `AES-256-CBC` with HMAC-SHA-256 authentication (Laravel's `Encrypter`).
- Key: `APP_KEY` from `.env` (32 bytes, base64-encoded).
- Output is base64-wrapped JSON (~3.5x the plaintext length) — that is why all encrypted columns are `TEXT`, not `VARCHAR(255)`.
- Reads via `$model->phone` return plaintext; writes via `$model->phone = '...'` are auto-encrypted on save.
- Casts are defined in the model's `$casts` array. Example (`app/Models/User.php`):

  ```php
  protected $casts = [
      'phone'   => 'encrypted',
      'address' => 'encrypted',
      // ...
  ];
  ```

### National ID — searchable hash, not encryption

The user's `national_id` (KTP/NIK) needs to be looked up exactly (e.g. for KYC matching) but should **never** be reversible from the database. We therefore:

1. Never store the raw value at all.
2. Store `users.national_id_hash` = `sha256(canonicalize(value) + '|' + pepper)`.
3. Pepper source is `PII_PEPPER` env var, falling back to `APP_KEY`.

Helper API (`app/Models/User.php`):

```php
$user->national_id = '3201234567890001'; // mutator hashes + stores in national_id_hash
$user->save();

User::findByNationalId('3201234567890001'); // returns User|null
```

The raw `national_id` attribute is **never persisted** — there is no column for it. Attempting to read `$user->national_id` returns `null`.

### JSON columns

For JSON payloads with sensitive answers (KYC, demographic survey, integration credentials), use `App\Casts\EncryptedJson`:

```php
use App\Casts\EncryptedJson;

protected $casts = [
    'kyc_payload' => EncryptedJson::class,
];
```

This cast:

- Tries `Crypt::decryptString` first.
- Falls back to plaintext JSON parsing during the rollout window (logs a warning so you can spot un-migrated rows).
- Always writes back canonical encrypted form on save.
- Throws on writing non-array values (catch programmer mistakes early).

The column type should be `TEXT` or `JSON`/`JSONB` (encrypted blob is opaque to the DB's JSON engine, so prefer `TEXT` for portability).

---

## Caveats & gotchas

### You cannot `WHERE` on encrypted columns

Each encryption uses a fresh IV, so the same plaintext encrypts to a different ciphertext every time. This means:

```php
User::where('phone', '+62812...')->first();   // BROKEN — will never match
```

To support exact-match lookup on a sensitive column, add a peppered hash column (the `national_id_hash` pattern). For substring search, store a separate normalized lowercase hash of trigrams — but **think hard first**, this re-introduces leak surface.

### Sorting / range / `LIKE` on encrypted columns is also impossible

Same root cause. If you need ordering (e.g. address by zip code), denormalize the queryable bit (`zip_code`) into its own non-PII column.

### Migration window: tolerate plaintext on read

Existing rows still hold plaintext immediately after the migration runs. Both the `encrypted` cast (when configured to be lenient — it currently throws) and our `EncryptedJson` cast handle this gracefully on read for the duration of the rollout.

**You must run** `php artisan flik:security:reencrypt-pii` after deploying the migration to convert all existing rows. Until then, reading `phone` / `address` on a legacy row may throw — wrap in `try/catch` if you cannot guarantee the rollout has completed.

### Bulk updates bypass the cast

`User::query()->update(['phone' => '+62...'])` writes plaintext directly. Always go through model save:

```php
$user->phone = '+62...'; $user->save();   // good
User::query()->update(['phone' => '+62...']); // BAD — writes plaintext
```

If you really need a bulk operation, manually wrap each value with `Crypt::encryptString`.

### Backups and exports must respect this

The database backup pipeline (`docs/security/backup-restore.md`) ships ciphertext — fine. But CSV exports (e.g. `/admin/audit-logs` CSV download, the future "Export all my data" GDPR endpoint) must read through the model so the cast decrypts. Don't write `DB::table('users')->select(...)->toCsv()` for any column on this list.

---

## Operations

### Initial rollout

```bash
php artisan migrate                     # adds columns, switches column types
php artisan flik:security:reencrypt-pii # converts plaintext rows to ciphertext
```

The reencrypt command is idempotent — safe to re-run.

### Key rotation

`APP_KEY` should be rotated on a regular cadence (annual, or immediately after any suspected key exposure). Procedure:

1. Generate the new key locally:
   ```bash
   php artisan key:generate --show
   # copy output, e.g. base64:NEW_KEY_HERE
   ```

2. On production (in `.env`):
   ```
   OLD_APP_KEY=base64:OLD_KEY_HERE
   APP_KEY=base64:NEW_KEY_HERE
   ```
   Deploy/restart the app — sessions, signed URLs, and any reads that *only* hit the current key will start to fail. The reencrypt pass below fixes the at-rest data.

3. Run the reencrypter:
   ```bash
   php artisan flik:security:reencrypt-pii --dry   # preview
   php artisan flik:security:reencrypt-pii         # actually re-encrypt
   ```
   For very large user tables, run during a low-traffic window. The command iterates in chunks and saves quietly.

4. Once the run finishes successfully, remove `OLD_APP_KEY` from `.env` and restart again. Done.

### Pepper rotation (national_id_hash)

If the pepper leaks, rotate via:

1. Set new `PII_PEPPER` in `.env`. Old hashes will stop matching.
2. **You must collect raw national IDs again** — there is no way to re-pepper without the plaintext (that's the point). For now this means asking users to re-enter, or doing an out-of-band re-sync from the KYC provider.

This is a deliberate trade-off: the pepper is a defense in depth and rotating it is painful by design.

---

## Threat model coverage

This control mitigates:

- **STRIDE / Information Disclosure** — DB dump leak (SQL injection, backup theft, employee misuse) does not directly expose PII.
- **GDPR Art. 32** — "appropriate technical and organisational measures" for data at rest.
- **Indonesian UU PDP** — Article 35 data protection-by-default obligations.

This control does **not** mitigate:

- **App-server compromise** — an attacker with code execution can call `Crypt::decryptString` like we do. Defense for this is in `docs/security/incident-response.md` (revoke + rotate).
- **Backup exfiltration combined with `.env` exfiltration** — the encryption key sits in `.env`. Keep `.env` permissions tight (`chmod 600`, never commit, rotate on any suspected leak — see `docs/security/secrets-audit.md`).
- **Logs** — application logs may incidentally contain PII. Audit `Log::*` calls periodically.
- **Sidecar telemetry** — if you wire Datadog / NewRelic, ensure span attributes do not include PII fields.

---

## Inventory checklist

When adding a new model, ask:

- [ ] Does this column hold a phone number, address, government ID, IP, geolocation, payment instrument, biometric, or health/sexual/political/religious data?
- [ ] If yes, is the column type `TEXT` (not `VARCHAR(255)`)?
- [ ] Did you add the appropriate cast (`encrypted`, `encrypted:array`, or `App\Casts\EncryptedJson`)?
- [ ] Did you add the column to the `$hidden` list so it is excluded from default JSON serialization?
- [ ] Did you update **this document**'s table?
- [ ] Did you add the `(model, column)` pair to the `$targets` array in `app/Console/Commands/ReencryptPii.php` so future key rotations cover it?
- [ ] If the column needs lookup, is there a corresponding peppered hash column?

---

## Related docs

- `docs/security/threat-model.md` — STRIDE model and asset inventory.
- `docs/security/backup-restore.md` — backup encryption (`BACKUP_KEY`).
- `docs/security/incident-response.md` — what to do if PII is exposed.
- `docs/security/secrets-audit.md` — `.env` and key hygiene.
