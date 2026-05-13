# WAF-lite (RequestFirewall)

Signature-based request inspector that runs early in FLiK's global middleware stack and blocks unambiguously malicious payloads before they reach a controller.

- **Middleware**: [`app/Http/Middleware/RequestFirewall.php`](../../app/Http/Middleware/RequestFirewall.php)
- **Config**: [`config/security.php` → `waf`](../../config/security.php)
- **Admin UI**: `/admin/security/waf-banned-ips`
- **Audit events**: `security.waf.blocked`, `security.waf.ip_banned`, `security.waf.ip_unbanned`
- **Tests**: [`tests/Feature/Security/RequestFirewallTest.php`](../../tests/Feature/Security/RequestFirewallTest.php) (skipped placeholders)

## Why

This is **defence in depth**, not a primary security control. The application already does:

- Eloquent / query-builder parameter binding (no string-concat SQL)
- Blade auto-escaping + an explicit HtmlSanitizer for user-submitted comments
- File-upload magic-byte validation + ClamAV scanning
- CSP headers via `SecurityHeaders`

WAF-lite adds a cheap pre-controller check that catches:

1. Drive-by scanners spraying generic OWASP payloads at the public surface
2. Malicious actors probing for misconfigurations before the framework defences engage
3. Mistakes in future code (e.g. a new endpoint that forgets to bind a parameter)

When a generic scanner hits `?id=1' OR 1=1`, we want to drop the request *and* throttle the source IP — not return a polite 200 for the bot to re-probe later.

## Rule catalogue

| Category            | Patterns                                                                                         |
| ------------------- | ------------------------------------------------------------------------------------------------ |
| Path traversal      | `../`, `..\\`, `%2e%2e/`, `..\\..\\windows`                                                      |
| SQLi                | `' OR 1=1`, `UNION SELECT`, `SELECT … FROM information_schema`, `; DROP TABLE`, `0x[hex]{20+}`   |
| XSS                 | `<script`, `javascript:`, `onerror=`, `onload=`, `onclick=`, `<iframe`, `<svg/onload`            |
| Command injection   | `;cat /etc/`, `\|nc `, `&& curl `, `$(`, backtick-quoted commands                                |
| PHP code injection  | `<?php`, `<?=`, `eval(`, `base64_decode(`                                                        |
| LFI / RFI           | `file:///`, `php://filter`, `php://input`, `expect://`                                           |
| Webshells           | `c99shell`, `r57`, `wso2`, `b374k`                                                               |
| Header CRLF         | `\r` or `\n` in any `X-*` request header (excluding framework-set ones)                          |

The full source of truth lives in `RequestFirewall::PATTERNS`. Each pattern returns on FIRST match — order in the array reflects logging clarity, not severity.

## Inspection surface

| Surface           | Inspected? | Notes                                                                                                          |
| ----------------- | ---------- | -------------------------------------------------------------------------------------------------------------- |
| URL path          | Always     | `../etc/passwd` in a path is never legitimate, regardless of route allowlist.                                  |
| Query string      | Always     | Both raw and `rawurldecode()`'d forms are scanned.                                                             |
| Custom headers    | Always     | Only CRLF injection in `X-*` headers (excluding framework + bypass + CSRF + requested-with).                   |
| Cookies           | Allowlistable | Framework cookies (`session.cookie`, `XSRF-TOKEN`, `remember_*`) are skipped — they're encrypted blobs.    |
| Form / JSON body  | Allowlistable | Walked recursively; up to 500 scalars per request; each value capped at 8192 bytes for ReDoS protection.   |
| File uploads      | Never      | Handled by `App\Support\MagicBytes` + ClamAV in the upload pipeline.                                           |

## Route allowlist

Listed routes skip **body and cookie** inspection only — path/query/header always run.

Default allowlist (in `config/security.php`):

- `comment.*` / `comment` / `comment/*` — comment text legitimately contains `<script>` strings; `HtmlSanitizer` handles it downstream.
- `admin.*` / `admin/*` — admins paste markdown / SQL / PHP into pitch-deck and movie descriptions.
- `chat.*` / `chat` — AI chatbot input may include code samples or error logs.

Patterns are matched against the route name first, then the path (via `Illuminate\Support\Str::is()` — supports `*` glob).

## Modes

| Mode       | Behaviour                                                       | Use when                                  |
| ---------- | --------------------------------------------------------------- | ----------------------------------------- |
| `block`    | Log to `audit_logs` (action `security.waf.blocked`) + return 403 | Production (default)                      |
| `log_only` | Log only; request proceeds                                      | Dev / staging while tuning rules          |

Set via `WAF_MODE=block|log_only`.

## IP throttling

| Cache key              | TTL                       | Purpose                                  |
| ---------------------- | ------------------------- | ---------------------------------------- |
| `waf:ip:hits:{ip}`     | 5 min (rolling)           | Rolling-window hit counter per IP        |
| `waf:ip:ban:{ip}`      | `WAF_IP_BAN_MINUTES` (60) | Active ban — request short-circuits to 403 |

When `waf:ip:hits:{ip}` reaches `WAF_IP_BAN_THRESHOLD` (default 5), `waf:ip:ban:{ip}` is set and a `security.waf.ip_banned` audit row is written. Subsequent requests from the IP return 403 without running the rule scan.

The ban list can be inspected and unbanned at **`/admin/security/waf-banned-ips`**:

- On Redis (production), IPs are listed via SCAN over the `waf:ip:ban:*` keyspace.
- On other cache drivers (dev `array`/`file`/`database`), the list is sourced from recent `security.waf.ip_banned` audit rows and probed against the cache. TTL countdown is unavailable on non-Redis drivers.

## Bypass token

For legitimate testing (DAST scans against staging), set `WAF_BYPASS_TOKEN=<long-random-string>` and send the header `X-Bypass-Waf: <same-string>` on every request. When the header matches, the entire WAF is skipped — path, query, body, cookies, headers, ban list.

The token comparison uses `hash_equals()` (constant-time). Never enable this in production without rotating the token regularly and restricting the originating IP at the load balancer.

## Environment variables

| Var                       | Default | Purpose                                          |
| ------------------------- | ------- | ------------------------------------------------ |
| `WAF_ENABLED`             | `true`  | Master kill switch                               |
| `WAF_MODE`                | `block` | `block` or `log_only`                            |
| `WAF_BYPASS_TOKEN`        | (unset) | Header value for `X-Bypass-Waf` (disabled if empty) |
| `WAF_IP_BAN_THRESHOLD`    | `5`     | Hits in 5 min before temp-ban                    |
| `WAF_IP_BAN_MINUTES`      | `60`    | Ban duration                                     |

## Audit events

| Action                       | Severity | Meta                                                                       |
| ---------------------------- | -------- | -------------------------------------------------------------------------- |
| `security.waf.blocked`       | Medium   | `matched_pattern`, `location`, `sample`, `method`, `path`, `route`, `mode` |
| `security.waf.ip_banned`     | High     | `ip`, `hits`, `threshold`, `ban_minutes`                                   |
| `security.waf.ip_unbanned`   | (admin)  | `ip`, `was_active`, `unbanned_by`                                          |

All three are filterable from `/admin/audit-logs` via the action prefix `security.waf`.

## Tuning a false positive

When a legitimate route trips a rule:

1. Check `/admin/audit-logs` and filter by `security.waf` to find the exact pattern label.
2. If the rule itself is wrong, edit `RequestFirewall::PATTERNS` and add a regression note.
3. If the rule is right but the route legitimately needs the input (e.g. a new admin form for raw HTML), add the route name or path to `config/security.php` → `waf.route_allowlist`.
4. Verify with `WAF_MODE=log_only` first, then flip back to `block`.

## Operational runbook

- **Sudden ban surge**: check `/admin/security/waf-banned-ips` → "Recent Block Events". A burst from a single IP usually means a scanner; a burst across many IPs with the same pattern label likely means a new legitimate feature is hitting a rule (start with `log_only`).
- **Locked yourself out**: SSH in, run `php artisan tinker` and `\Cache::forget('waf:ip:ban:YOUR.IP.HERE')`. Or set `WAF_ENABLED=false` and re-deploy.
- **CI / DAST flooding the audit log**: set `WAF_BYPASS_TOKEN` in the CI environment and pass `X-Bypass-Waf` from the scanner config.

## Limitations

- **Not a full WAF** — no behavioural analysis, no anomaly scoring, no bot fingerprinting. Use Cloudflare / Bunny Shield in front for that tier.
- **Pattern-matching is fundamentally bypassable** — encoding tricks, polyglot payloads, and sufficiently weird charsets will slip past. Treat this as a noise filter, not a guarantee.
- **No HTTPS interception** — we see whatever Laravel sees post-decryption. TLS-level attacks are out of scope.
- **Ban list is per-cache-driver** — production uses Redis (shared across web nodes). Dev uses array (per-process, resets on restart). File / database drivers work but listing the ban set falls back to audit-log derivation.
