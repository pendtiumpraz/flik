# FLiK Security Documentation

This directory holds all security documentation for FLiK (codename Velflix). It is intended for engineers, the Security WG, and external auditors.

If you're new to FLiK security, read in this order:

1. [`threat-model.md`](./threat-model.md) — what we're protecting and against whom (STRIDE).
2. [`pentest-checklist.md`](./pentest-checklist.md) — manual test pass to run before each release (OWASP Top 10).
3. [`incident-response.md`](./incident-response.md) — what to do when something goes wrong.

Then dive into the per-topic deep dives below.

---

## Index

### Strategy & process

| Doc | Status | Summary |
|-----|--------|---------|
| [`threat-model.md`](./threat-model.md) | Done | STRIDE threat model, asset inventory, threat matrix, top-5 risks |
| [`pentest-checklist.md`](./pentest-checklist.md) | Done | OWASP Top 10 manual test checklist with per-route inventory |
| [`incident-response.md`](./incident-response.md) | Done | Severity definitions, roles, timeline, comms templates, runbooks |

### Application security audits

| Doc | Status | Summary |
|-----|--------|---------|
| [`sql-injection-audit.md`](./sql-injection-audit.md) | Existing | SQL injection sweep across Eloquent and raw queries (peer SEC #8) |
| [`xss-audit.md`](./xss-audit.md) | TODO | Audit of every `{!! !!}` in Blade and Livewire renderers (peer #9) |
| [`file-uploads.md`](./file-uploads.md) | TODO | Upload validation: MIME, extension, size, antivirus, storage location (peer #11) |
| [`authorization-audit.md`](./authorization-audit.md) | TODO | Per-Policy verification matrix vs. routes and Livewire components (peer #12) |
| [`mass-assignment-audit.md`](./mass-assignment-audit.md) | TODO | `$guarded` / `$fillable` audit per model, focus on privilege columns (peer #13) |

### Bot mitigation & abuse

| Doc | Status | Summary |
|-----|--------|---------|
| [`captcha.md`](./captcha.md) | TODO | Cloudflare Turnstile integration, when to require, fallback (peer #15) |
| [`honeypot.md`](./honeypot.md) | Done | Honeypot trap field + form-fill timer on login/register/password-reset/newsletter (peer #16) |
| [`waf-lite.md`](./waf-lite.md) | TODO | App-layer firewall: bad-bot UA list, geo block, ASN deny list (peer #26) |

### DAST & ops

| Doc | Status | Summary |
|-----|--------|---------|
| [`dast-runbook.md`](./dast-runbook.md) | Existing | Dynamic scanning runbook: ZAP / sqlmap / nikto cadence and scope (peer #19) |
| [`alerting.md`](./alerting.md) | TODO | Slack/PagerDuty wiring for security events (peer #25) |
| [`event-taxonomy.md`](./event-taxonomy.md) | TODO | Canonical action names for `audit_logs.action` (peer #24) |
| [`headers.md`](./headers.md) | TODO | Security headers: CSP, HSTS, X-Frame, Referrer, Permissions (peer #7) |

### Data protection & secrets

| Doc | Status | Summary |
|-----|--------|---------|
| [`pii-encryption.md`](./pii-encryption.md) | Done | Which columns are encrypted-at-rest, key rotation, GDPR/UU PDP mapping (peer #23) |
| [`backup-restore.md`](./backup-restore.md) | TODO | Backup schedule, encryption (`BACKUP_KEY`), restore drill, RTO/RPO (peer #27) |
| [`api-keys.md`](./api-keys.md) | TODO | API key issuance, rotation, scopes for AI providers and integrations (peer #28) |
| [`secrets-audit.md`](./secrets-audit.md) | TODO | gitleaks / trufflehog scan of repo + history; remediation log (peer #29) |

---

## Conventions

- All docs are Markdown, English (Indonesian only for customer-facing templates inside `incident-response.md`).
- Use checkboxes (`[ ]` / `[x]` / `[~]`) for action items so they're greppable.
- Cite code paths as full repo-relative paths (e.g. `app/Services/Drm/DrmKeyService.php`) — never line numbers, which drift.
- Link generously between docs; do not duplicate.
- When you discover a security issue, file an issue tagged `security` AND link the row in the relevant audit doc — do not store findings only in tickets.

## Contact

- Security WG channel: `#sec-wg`
- Incidents: `#sec-alerts` and PagerDuty `flik-prod`
- Disclosure: security@flik.id (PGP key in `/.well-known/security.txt`)
- DPO: dpo@flik.id
