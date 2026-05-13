# Security Alerting (Slack / Discord webhooks)

Real-time fan-out of critical security events from `AuditLogger::security()`
into a Slack channel and/or a Discord channel via incoming webhooks, plus
an unconditional **daily digest** to `super_admin` users.

This is the operator-facing layer of the security audit pipeline. The
`audit_logs` table remains the source of truth — webhooks are *signals*,
not records.

---

## Components

| File | Role |
|------|------|
| `app/Services/Security/SecurityAlertService.php` | Severity mapping, throttling, message rendering, webhook POST. |
| `app/Events/SecurityEventLogged.php` | Fired by `AuditLogger::security()` after the row is persisted. |
| `app/Listeners/PushSecurityAlerts.php` | Subscribes to the event, calls `SecurityAlertService::notify`. |
| `app/Services/Audit/AuditLogger.php` | New `security()` method: writes the row **and** dispatches the event. |
| `app/Console/Commands/SecurityDailyDigest.php` | `flik:security:daily-digest` — 24h aggregate, always emails super_admins. |
| `app/Console/Kernel.php` | Schedules the digest at 08:00 Asia/Jakarta. |
| `config/services.php` | New `security_alerts` block. |

---

## Configuration

Add to `.env`:

```dotenv
# Master switch. When false, real-time webhook fan-out is disabled.
# The daily digest is unaffected and continues to email super_admins.
SECURITY_ALERTS_ENABLED=true

# Slack incoming webhook (https://hooks.slack.com/...).
# The service rejects non-HTTPS URLs and any host that is not under
# hooks.slack.com — this stops a misconfigured env from being used as
# an SSRF foot-gun.
SECURITY_ALERTS_SLACK_WEBHOOK=https://hooks.slack.com/services/T000/B000/XXXXX

# Discord webhook (https://discord.com/api/webhooks/.../...).
# Allowed hosts: discord.com, discordapp.com (and ptb./canary. variants).
SECURITY_ALERTS_DISCORD_WEBHOOK=https://discord.com/api/webhooks/12345/abcdef

# Severity floor for real-time webhooks. One of: low, medium, high, critical.
# Default is `high` — raise to `critical` if Slack is too noisy, lower to
# `medium` for a security-team channel that wants more visibility.
SECURITY_ALERTS_MIN_SEVERITY=high
```

All four variables are optional. The service no-ops gracefully when:

* `SECURITY_ALERTS_ENABLED` is false (default), **or**
* both webhook URLs are empty, **or**
* the event severity is below `min_severity`.

---

## Severity mapping

`SecurityAlertService::severity(string $event)` is the single source of
truth. Current mapping:

| Severity   | Events |
|------------|--------|
| `critical` | `security.privilege.escalation_attempt`, `security.account.deleted_by_admin`, `security.geo.suspicious_velocity`, `security.drm.key_denied_mass` |
| `high`     | `security.auth.lockout_mass`, `security.ssrf.blocked`, `security.upload.malware_signature`, `security.csp.violation_batch` |
| `medium`   | `security.auth.new_country_login`, `security.ratelimit.sustained` |
| `low`      | everything else (`security.auth.new_device_login`, `security.auth.login.failed`, ...) |

When the peer `App\Constants\SecurityEvents` class lands (SEC #24) the
constant *values* must match the strings above; only the names should
change.

---

## Throttling

To prevent a brute-force loop from flooding Slack with one message per
attempt, identical `(event, user_id)` pairs are deduped via a
**5-minute** cache key:

```
security_alert:{event}:{user_id|"anon"}
```

Cache backend follows `CACHE_DRIVER`. Redis is recommended in production.
A cache outage is non-fatal — the service logs a warning and forwards
the alert anyway (fail-open: missing alerts are worse than duplicate alerts).

---

## SSRF safety

* Webhook URLs are validated against an HTTPS-only host allowlist
  (`hooks.slack.com`, `discord.com`, `discordapp.com`, plus the canary
  and PTB Discord domains).
* HTTP redirects are disabled (`Http::withoutRedirecting()`) so a 302 to
  `169.254.169.254` (cloud metadata service) cannot exfiltrate webhook
  payload content.
* When the project's `App\Services\Http\SafeHttp` helper (peer SEC #10)
  is available the service routes through it for IP-allowlisting on top
  of host-allowlisting.

---

## How to publish an event

From application code (controller, listener, middleware):

```php
use App\Services\Audit\AuditLogger;

public function block(Request $request, AuditLogger $audit): Response
{
    $audit->security('security.ssrf.blocked', [
        'target_url' => $blockedUrl,
        'reason' => 'private_network',
    ]);

    return response('blocked', 403);
}
```

`AuditLogger::security()` writes the `audit_logs` row first, then
dispatches `SecurityEventLogged`. The listener handles severity gating,
throttling, and webhook POST. **Do not call the webhook helpers
directly** — going through `security()` ensures the audit trail and the
alert are in sync.

---

## Daily digest

The `flik:security:daily-digest` command aggregates the previous 24h of
`audit_logs` rows (action prefixed `security.` or `auth.`) into a single
plaintext report:

* Total events, unique users, unique source IPs.
* Histogram of events grouped by action.
* Top 5 source IPs.

The digest is **always** emailed to every `super_admin` user, even when
`SECURITY_ALERTS_ENABLED=false`. When webhooks are configured the digest
is also POSTed to Slack and Discord (deduped per hour to survive
scheduler failover).

Manual usage:

```bash
# Default — last 24h, send to all super_admins
php artisan flik:security:daily-digest

# Different window
php artisan flik:security:daily-digest --hours=72

# Test without sending
php artisan flik:security:daily-digest --dry

# Override recipients
php artisan flik:security:daily-digest --to=ops@flik.id,sec@flik.id
```

The schedule entry runs at 08:00 Asia/Jakarta:

```php
$schedule->command('flik:security:daily-digest')
    ->dailyAt('08:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping()
    ->onOneServer();
```

---

## Verifying the integration

1. Set both webhook URLs in `.env` and `SECURITY_ALERTS_ENABLED=true`.
2. Run a `php artisan tinker` session and trigger a critical event:

   ```php
   app(\App\Services\Audit\AuditLogger::class)->security(
       'security.privilege.escalation_attempt',
       ['note' => 'manual smoke test'],
   );
   ```

3. A red-banded message should appear in both channels within ~1s,
   linking back to `/admin/audit-logs?id={id}`.
4. Re-run the same call within 5 minutes — the second call should be
   silently throttled (no Slack/Discord message). The `audit_logs` row
   is still written.
5. Run `php artisan flik:security:daily-digest --dry` to verify the
   aggregation pipeline.

---

## Failure modes

| Scenario | Behaviour |
|----------|-----------|
| Webhook URL misconfigured (wrong host) | Logged warning `refusing unsafe webhook URL`; nothing sent. |
| Webhook returns 5xx / times out | 4-second timeout; warning logged; the audit row is still persisted. |
| Cache backend down | Throttle skipped (fail-open); duplicates may be sent until cache recovers. |
| Listener throws | Caught in `PushSecurityAlerts`; logged; audit trail intact. |
| `services.security_alerts.enabled=false` | `notify()` returns immediately; digest still runs. |
| No `super_admin` users in DB | Digest logs a warning and exits 0 (cron stays green). |
