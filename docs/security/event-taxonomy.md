# Security event taxonomy

Canonical reference for every value in `App\Support\SecurityEvents`. Every
call to `AuditLogger::security($event, ...)` MUST pass one of these
constants — free-form strings break the `/admin/audit-logs` filter, the
Slack/Discord severity mapping, and the daily digest dedup logic.

The values use the `<domain>.<noun>.<verb>` shape and fit inside the 80-char
`audit_logs.action` column. Once shipped, **a value MUST NOT be renamed** —
deprecate by leaving the constant in place and adding a comment.

Severity buckets are defined by `SecurityEvents::severity($event)` and used
to:

- decide whether `SecurityAlertService` fans out to Slack / Discord
  (`min_severity` floor in `services.security_alerts`),
- colour-code rows in `/admin/audit-logs` (red intensity scales with
  severity),
- group rows in the daily digest (`SecurityDailyDigest` console command).

| Symbol | Value | Severity | Fired when |
| --- | --- | --- | --- |
| **Authentication** ||||
| `LOGIN_SUCCESS` | `auth.login.success` | low | A user completes the password (and 2FA, if enabled) flow. Emitted by `SessionsController::store` (no-2FA branch) and `TwoFactorController::verify` (2FA branch). |
| `LOGIN_FAILED` | `auth.login.failed` | medium | A submitted email + password pair fails `Hash::check`. Also emitted when the email is unknown — meta `reason` distinguishes `bad_password` vs `unknown_email`. |
| `LOGIN_LOCKED_OUT` | `auth.login.locked_out` | high | `LoginThrottle` reports the IP or account is locked out. Meta `scope` is `ip` or `account`. |
| `LOGOUT` | `auth.logout` | low | User hits `POST /logout` (or the destroy route). |
| `SESSION_REVOKED` | `auth.session.revoked` | high | All sessions for the user are invalidated server-side. Currently fired during password reset; future: admin-forced logout, suspicious activity. |
| **Two-factor** ||||
| `TWO_FACTOR_ENABLED` | `auth.2fa.enabled` | medium | User confirms TOTP setup (the `confirm()` action persists the secret). |
| `TWO_FACTOR_DISABLED` | `auth.2fa.disabled` | high | User (or admin) turns off 2FA. High severity because it lowers the account's security posture. |
| `TWO_FACTOR_VERIFIED` | `auth.2fa.verified` | low | User clears the challenge with a TOTP code or recovery code. Meta `mode` distinguishes `totp` vs `recovery_code`. |
| `TWO_FACTOR_FAILED` | `auth.2fa.failed` | high | Wrong TOTP / consumed-or-invalid recovery code at the challenge screen. |
| **Password / email lifecycle** ||||
| `PASSWORD_CHANGED` | `auth.password.changed` | medium | The hash on `users.password` mutated. Meta `via` is `reset` (forgot-password flow) or `profile` (settings change). |
| `PASSWORD_RESET_REQUESTED` | `auth.password.reset_requested` | medium | `POST /forgot-password` was hit. Emitted regardless of whether the email matched a row (meta `matched`); response to the client is identical either way. |
| `PASSWORD_RESET_COMPLETED` | `auth.password.reset_completed` | medium | A reset token was successfully consumed. |
| `EMAIL_VERIFIED` | `auth.email.verified` | low | The email-verification link was clicked. |
| **Behavioural / heuristic signals** ||||
| `NEW_DEVICE_LOGIN` | `security.new_device` | medium | `LoginAlertService` did not find a matching `(user, device_fingerprint)` in `known_devices`. |
| `NEW_COUNTRY_LOGIN` | `security.new_country` | medium | The resolved country is not in the user's last 5 login countries. |
| `SUSPICIOUS_GEO_VELOCITY` | `security.suspicious.geo_velocity` | critical | 3+ distinct countries in 60 minutes for the same user — strong "credential takeover" signal. |
| `PRIVILEGE_ESCALATION_ATTEMPT` | `security.priv_escalation_attempt` | critical | Reserved for the gate/policy layer to log "user X tried to access admin scope without `is_admin`". Currently surfaces from policy violations and unauthorised admin route hits. |
| `RATE_LIMIT_HIT` | `security.rate_limit_hit` | medium | A response with HTTP 429 was returned. Recorded by `RecordRateLimitHits` middleware. |
| `HONEYPOT_HIT` | `security.honeypot_hit` | high | A bot filled the hidden honeypot form field. Wired by the honeypot middleware (peer SEC #16). |
| `CSP_VIOLATION` | `security.csp_violation` | medium | The `/csp/report` endpoint received a Content-Security-Policy violation report. |
| `SSRF_BLOCKED` | `security.ssrf_blocked` | high | `App\Services\Http\SafeHttp` rejected an outbound request because the host resolved to a private/loopback range or failed the allowlist (peer SEC #10). |
| `FILE_UPLOAD_REJECTED` | `security.file_upload_rejected` | high | `FileUploadValidator::fail()` was called — meta `kind` (image/video/subtitle), `threat` (`script_tag`/`javascript_uri`), `errors`. |
| **Admin actions** ||||
| `ADMIN_ACTION` | `admin.action` | medium | Generic admin event for actions that don't have a dedicated event yet. Prefer adding a specific constant when the action becomes recurring. |
| `ADMIN_USER_UNLOCK` | `admin.user.unlock` | medium | An admin cleared the brute-force lockout for a user (`AdminController::unlockLogin`). |
| `ADMIN_USER_DELETED` | `admin.user.deleted` | critical | An admin deleted a user record via `AdminController::destroyUser`. Critical because this is data destruction. |
| **Privacy (GDPR)** ||||
| `DATA_EXPORT_REQUESTED` | `privacy.export_requested` | medium | The user requested their data export (`PrepareDataExport` job dispatched). |
| `DATA_EXPORT_DOWNLOADED` | `privacy.export_downloaded` | medium | The signed download link was used. Important for data-minimisation auditing — the file should be cleaned up after download. |
| `ACCOUNT_DELETED` | `privacy.account_deleted` | critical | The user completed self-service deletion via `UserDataController::delete`. Meta carries the per-table erasure summary as evidence. |
| **Payments** ||||
| `PAYMENT_CHARGEBACK` | `payment.chargeback` | critical | A Midtrans/PayPal chargeback notification was processed. Critical — usually a fraud signal. |
| **DRM / playback** ||||
| `DRM_KEY_REQUEST` | `drm.key_request` | low | A successful AES content-key handout from `PlaybackController::key`. Low severity but high volume — used to size CDN/key-server capacity. |
| `DRM_KEY_DENIED` | `drm.key_denied` | high | Any failure path in `PlaybackController::key` (missing/invalid token, session/key mismatch, expired session, geo block, missing key). Meta `reason` carries the structured cause. |

## How to add a new event

1. Add the constant to `App\Support\SecurityEvents`. Choose a value that
   matches the `<domain>.<noun>.<verb>` shape and fits in 80 chars.
2. Add the constant to `SecurityEvents::all()` so the `/admin/audit-logs`
   filter chip includes it.
3. Add a severity branch in `SecurityEvents::severity()` (default is `low`).
4. Add a row to the table above. Severity, "fired when", any meta keys.
5. Wire the call site to `AuditLogger::security($event, ...)` (NOT `log()`)
   so the row is flagged `is_security = true` and Slack/Discord can be
   notified.
6. Inside `App\Services\Security\SecurityAlertService::severity()`, if the
   default-from-`SecurityEvents` severity isn't right for this event
   specifically (e.g. you want one variant to page on-call), add it to the
   explicit severity table there.

## Operational hooks

- `/admin/audit-logs` — filter chip "Security only" calls
  `AuditLog::scopeSecurityOnly()` which prefers the `is_security` column
  and falls back to action-prefix matching for legacy rows.
- `App\Services\Security\SecurityAlertService` — synchronous Slack/Discord
  fan-out. Severity floor configured via
  `services.security_alerts.min_severity` (default `high`). Throttled per
  `(event, user)` for 5 minutes to absorb bursts.
- `App\Console\Commands\SecurityDailyDigest` — daily summary email of
  every event in the last 24 hours, grouped by severity.

## Backward compatibility

- `AuditLogger::log()` retains its original signature — no existing call
  site needs to change.
- The `is_security` column was added in migration
  `2026_05_10_040101_add_is_security_to_audit_logs.php`. Pre-migration
  rows have `is_security = false`; the model scope falls back to
  action-prefix matching so security-only filtering still works on legacy
  rows after a partial deploy.
- Legacy event names (e.g. `security.suspicious_geo_velocity` with the
  underscore in the middle, used before this taxonomy landed) remain in
  the audit table. The constants here use the canonical
  `security.suspicious.geo_velocity` form. Both surfaces can coexist
  because the `security_only` filter matches both shapes via prefix.
