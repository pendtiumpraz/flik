# API Keys — Service-to-Service Auth

FLiK issues API keys for machine clients (transcoding workers, SDK callers, third-party integrations) that need to call `/api/*` endpoints without a browser session. Human users continue to authenticate with email/password + 2FA via the standard web flow.

## Key shape

```
flk_a1b2c3d4e5f6...   (4-char prefix + 32 hex chars = 36 chars total)
```

- **128 bits of entropy** (`random_bytes(16)` → 32 hex chars).
- The literal `flk_` prefix lets log scrubbers and secret scanners (TruffleHog, GitLeaks) pick keys out of accidentally committed code.
- The first 8 chars (e.g. `flk_a1b2`) are the **public prefix** stored alongside `key_hash`. The admin UI displays only the prefix.

## Storage

Plaintext keys are **never persisted**. The `api_keys` table stores:

| Column | Notes |
|---|---|
| `key_hash` | `sha256(plaintext)`, unique-indexed for O(1) lookup. SHA-256 (not bcrypt) because the input already has 128 bits of entropy — bcrypt's stretching adds latency without strengthening anything. |
| `key_prefix` | First 8 chars of plaintext, indexed (for searching by partial). |
| `abilities` | JSON array. `["*"]` = full access. |
| `expires_at`, `revoked_at` | Either set → key fails verification. |
| `last_used_at`, `last_used_ip` | Stamped by the auth middleware on every successful call. |
| `created_by_user_id` | FK to the admin who issued the key. |

The composite index on `(revoked_at, expires_at)` powers the `scopeActive()` query.

## Issuing a key

**Admin UI** (`/admin/api-keys`):

1. Sign in as an admin (`is_admin = true`).
2. Fill the "Issue new key" form:
   - **Name** — required, ≤ 80 chars. Identifies the key in logs/UI (e.g. "Subtitle Worker prod").
   - **Abilities** — CSV. `*` (or empty) = wildcard. Otherwise a list of scope strings (`movies.read,movies.write`).
   - **Expires at** — optional. Must be in the future.
3. Submit. The plaintext key appears **once** in a modal — copy it into your secret manager immediately. Refreshing the page hides it forever.

**Programmatically** (e.g. seeders, tests):

```php
use App\Services\Security\ApiKeyService;

['plaintext' => $key, 'model' => $row] = app(ApiKeyService::class)->generate(
    name: 'Subtitle Worker',
    abilities: ['subtitles.write'],
    expiresAt: now()->addYear(),
);
```

## Using a key

Send the plaintext in **one** of:

```
Authorization: Bearer flk_a1b2c3d4e5f6...
```

```
X-Api-Key: flk_a1b2c3d4e5f6...
```

Apply the `auth.apikey` middleware to any route or group:

```php
Route::middleware('auth.apikey')->prefix('api')->group(function () {
    Route::post('/movies/{movie}/encoding-callback', [EncodingWebhookController::class, 'handle']);
});
```

Inside the handler the verified key is exposed via the request:

```php
/** @var \App\Models\ApiKey $apiKey */
$apiKey     = $request->attributes->get('api_key');
$abilities  = $request->attributes->get('api_key_abilities');

if (! $apiKey->can('movies.write')) {
    abort(403);
}
```

### Failure modes

The middleware returns **HTTP 401** with body `{"error":"unauthorized","message":"…"}` for:

- Missing header
- Malformed prefix (anything not starting with `flk_`)
- Unknown hash
- Revoked key
- Expired key

All four "bad key" cases collapse to the same `Invalid API key.` response so the endpoint can't be used to enumerate which keys exist or once existed.

A `WWW-Authenticate: Bearer realm="api"` header is included per RFC 6750 so well-behaved clients know which scheme to use.

## Rotation

Keys do not auto-rotate — rotate them when:

- A team member with access to a secret manager leaves.
- A key has been used unexpectedly (check `last_used_ip` against your known infrastructure).
- A scope change requires fresh capabilities.
- Annually, as a baseline hygiene step.

**Recommended rotation flow:**

1. Issue a new key in the admin UI.
2. Deploy the new key to your service alongside the old one (`API_KEY_PRIMARY` + `API_KEY_SECONDARY`).
3. Switch traffic to the new key (config flip / blue-green deploy).
4. Watch `last_used_at` on the old key for 24–48 hours to confirm nothing is still using it.
5. Revoke the old key from the admin UI.

## Revocation

Revocation is **soft** — the row stays in place with `revoked_at` set. We don't hard-delete because:

- `audit_logs` rows reference the key (action `api_key.revoked` records the prefix + name + actor).
- `last_used_ip` history remains queryable for incident response.
- A revoked key cannot be re-activated; if you need it back, generate a fresh one.

Revocation takes effect on the **next** request — there is no in-flight cancellation. The middleware re-reads from DB on every call (no caching), so the latency window is whatever your DB round-trip is.

## Scopes (abilities)

The `abilities` column is a freeform JSON array. Suggested taxonomy:

| Pattern | Scope of access |
|---|---|
| `*` | Everything. Use only for first-party services you fully control. |
| `movies.read` | Read movie catalog. |
| `movies.write` | Mutate movie rows (admin uploaders, transcoder callbacks). |
| `subtitles.read` / `subtitles.write` | Subtitle pipeline. |
| `playback.token.issue` | Mint DRM playback JWTs. |
| `metrics.read` | Internal observability. |

Enforcement is the **caller's responsibility** — the middleware only authenticates. Each protected endpoint should call `$apiKey->can('whatever')` before doing real work.

## Threat model

- **Stolen key** — invalidate via the admin UI; affected blast radius is bounded by the abilities list. Monitor `last_used_ip` for unexpected origins.
- **Database leak** — only hashes leak. SHA-256 of a 128-bit random value is computationally infeasible to reverse. Keys cannot be recovered from a stolen DB dump.
- **Brute force** — the keyspace is 2¹²⁸. Even at 10⁹ guesses/second, expected time to first hit is comfortably past the heat death of the sun. We do not separately rate-limit the API key middleware; the global throttle layer is sufficient.
- **Timing oracle** — `verify()` always does the same work shape (one indexed lookup + one constant-time compare) and the middleware response collapses all failures to an identical status + message.

## CORS interaction

The CORS config (`config/cors.php`) requires `Authorization` and `X-Api-Key`-friendly headers in `allowed_headers`, and same-origin (or explicitly allowlisted via `CORS_ALLOWED_ORIGINS`) origins. Browser-based SPAs cross-origin must therefore live on an allowlisted domain — server-to-server callers ignore CORS entirely (no preflight from non-browser clients).

## Code references

| Concern | Path |
|---|---|
| Migration | `database/migrations/2026_05_10_040105_create_api_keys_table.php` |
| Model | `app/Models/ApiKey.php` |
| Service | `app/Services/Security/ApiKeyService.php` |
| Middleware | `app/Http/Middleware/AuthenticateApiKey.php` (alias `auth.apikey`) |
| Admin controller | `app/Http/Controllers/Admin/ApiKeyController.php` |
| Admin view | `resources/views/admin/api-keys/index.blade.php` |
| Routes | `routes/web.php` (admin group, `api-keys.*` named routes) |
