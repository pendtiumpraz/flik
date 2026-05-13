# Secrets Audit

Owner: Platform Security
Last reviewed: 2026-05-13
Sibling docs: [`headers.md`](headers.md), [`dast-runbook.md`](dast-runbook.md), [`sql-injection-audit.md`](sql-injection-audit.md)

Scope: confirm no real credentials are committed to the repository, and
document the keys that MUST be supplied via environment at deploy time.

---

## 1. Repository state

| Item | Status | Notes |
|---|---|---|
| `.env` tracked by git? | NO | `git ls-files .env` returns empty; `git check-ignore .env` matches. |
| `.env` in `.gitignore`? | YES | Line 6 of `.gitignore`. `.env.backup` also ignored (line 7). |
| `.env.example` tracked? | YES | Intended — template only, all secret values blank. |
| Local `.env` present? | YES (developer-local) | Contains a placeholder `TMDB_TOKEN=eyJhbGciOiJIUzI1NiJ9...token_anda_disini` which is a sample value, not a real token. Local file only — never committed. |
| Hardcoded secrets in `*.php` / `*.blade.php` / `*.js`? | NONE FOUND | Grep against `(sk-\|pk_live\|api_key\s*=\s*['"][a-zA-Z0-9]{20,})` and `(sk_live_\|sk_test_\|AIza[0-9A-Za-z\-_]{35}\|ghp_[A-Za-z0-9]{36}\|xox[baprs]-)` returned zero matches. |

> The placeholder string in the local `.env` (line 59) is benign but the
> file has a duplicate `TMDB_TOKEN=` (lines 51 + 59). The second wins. If
> you intend to set a real TMDB token locally, replace line 59 with the
> real value and delete line 51.

---

## 2. Sensitive keys present in `.env.example` (placeholders only)

The following keys are listed with EMPTY or DEFAULT placeholder values in
`.env.example`. Every one of them is intentionally blank — confirmed by
inspection. Real values are injected at deploy time via the secrets store.

| Key | Placeholder value in `.env.example` | Verdict | Notes |
|---|---|---|---|
| `APP_KEY` | empty | OK — must be generated per environment | `php artisan key:generate` is documented in the file header and README. Never reuse across envs. |
| `DB_PASSWORD` | empty | OK | Local stack uses passwordless root by convention; production sets via secret store. |
| `MAIL_PASSWORD` | `null` literal | OK | MailHog dev container ignores credentials. |
| `MAIL_USERNAME` | `null` literal | OK | Same as above. |
| `PUSHER_APP_SECRET` | empty | OK | Pusher integration auto-disables when key missing (see CLAUDE.md ENV-driven feature gating). |
| `PUSHER_APP_ID` | empty | OK | Same as above. |
| `PUSHER_APP_KEY` | empty | OK | Same as above. |
| `MIDTRANS_SERVER_KEY` | empty | OK | `PaymentController::isEnabled()` returns `false` so the checkout button shows "Coming Soon" instead of crashing. |
| `MIDTRANS_CLIENT_KEY` | empty | OK | Frontend-safe key but still env-injected. |
| `GOOGLE_CLIENT_SECRET` | empty | OK | Socialite gracefully no-ops when missing — login form still works. |
| `GOOGLE_CLIENT_ID` | empty | OK | Same as above. |
| `TMDB_TOKEN` | empty | OK | TMDB enrichment skipped when blank. |
| `MAILCHIMP_KEY` | empty | OK | Newsletter signup degrades to a friendly notice. |
| `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` | empty | OK | S3 disk only used when `FILESYSTEM_DISK=s3`. |
| `AZURE_STORAGE_KEY` | empty | OK | Same gating logic as AWS. |
| `ALIBABA_OSS_ACCESS_KEY_SECRET` | empty | OK | Same gating logic. |
| AI provider keys (OpenAI / Anthropic / etc.) | NOT in `.env.example` | OK | Stored encrypted in the `ai_providers` DB table via `/admin/ai-settings`, not in env. The DB column is encrypted at rest by Laravel's default cipher. |
| Bunny CDN keys | not in `.env.example` (referenced from `config/services.php`) | OK | `BUNNY_STORAGE_KEY`, `BUNNY_STREAM_API_KEY`, `BUNNY_TOKEN_KEY` — set via deploy secrets, never committed. |

---

## 3. Source-tree scan results

Two regexes ran against the entire codebase via `Grep`:

```
# Common API-key shapes
(sk-|pk_live|api_key\s*=\s*['"][a-zA-Z0-9]{20,})

# Hard-coded passwords in PHP
(password|secret|token|api_key|apikey)\s*=\s*['"][a-zA-Z0-9_\-/+=]{16,}['"]
```

Neither produced any matches in `*.php`, `*.blade.php`, or `*.js`. The
codebase reads every secret via `env(...)` or `config(...)` — confirmed
clean as of 2026-05-13.

Provider-specific token shapes also scanned (no matches):

```
sk_live_ | sk_test_ | AIza[0-9A-Za-z\-_]{35} | ghp_[A-Za-z0-9]{36} | xox[baprs]-
```

These cover Stripe (live + test), Google API, GitHub PAT, Slack tokens.

---

## 4. Production deployment requirements

Before going live, every key in section 2 above must be present in the
production secret store (Vault / AWS Secrets Manager / Doppler / whatever
the deploy stack uses). The following are NON-NEGOTIABLE:

1. `APP_KEY` — generated per environment, never reused. `php artisan key:generate --show` once, then store.
2. `APP_DEBUG=false` — leaking stack traces is the most common Laravel disclosure bug.
3. `APP_ENV=production` — gates `URL::forceScheme('https')`, HSTS, and `ForceHttps`.
4. `SESSION_SECURE_COOKIE=true` — see [`headers.md`](headers.md) section 4.
5. `DB_PASSWORD` — strong, not the local-dev default.
6. Active AI provider configured via `/admin/ai-settings` with encrypted key.

---

## 5. Audit cadence

- Re-run the regex scans below before every release tag.
- Re-run section 1 (gitignore + tracked status) any time `.gitignore` is touched.
- A pre-commit hook stub is provided in `.githooks/pre-commit` — enable per
  developer with `git config core.hooksPath .githooks` (see
  [`CONTRIBUTING.md`](../../CONTRIBUTING.md)).

```bash
# Quick re-run
grep -rEn "sk-[a-zA-Z0-9]{20,}|pk_live|sk_live_|sk_test_|AIza[0-9A-Za-z_-]{35}|ghp_[A-Za-z0-9]{36}|xox[baprs]-" \
  --include="*.php" --include="*.blade.php" --include="*.js" .

git check-ignore .env && echo "ok: .env ignored"
git ls-files .env | wc -l   # expect 0
```
