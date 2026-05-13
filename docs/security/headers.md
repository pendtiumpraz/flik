# HTTP Security Headers

Owner: Platform Security
Last reviewed: 2026-05-13
Sibling docs: [`secrets-audit.md`](secrets-audit.md), [`dast-runbook.md`](dast-runbook.md), [`sql-injection-audit.md`](sql-injection-audit.md)

This document records which HTTP security headers FLiK emits, where they are
configured, and the deployment checklist for HSTS preload eligibility.

---

## 1. HTTPS enforcement (the foundation)

Three layers cooperate to guarantee every byte leaves the box on TLS:

| Layer | Where | What it does |
|---|---|---|
| Outbound URL generation | `App\Providers\AppServiceProvider::boot()` | `URL::forceScheme('https')` in production — `route()` / `url()` / `asset()` always emit `https://` |
| Inbound redirect | `App\Http\Middleware\ForceHttps` | 301 redirects any plain-HTTP request to its HTTPS equivalent (skipped in `local` + `testing`) |
| Proxy awareness | `App\Http\Middleware\TrustProxies` | `$proxies = '*'` + `HEADER_X_FORWARDED_PROTO` so `$request->isSecure()` reflects the external scheme behind a load balancer |

`ForceHttps` is registered in `app/Http/Kernel.php` **after** `TrustProxies`
in the global stack — that ordering is load-bearing. If `ForceHttps` ran
first, every TLS-terminated request would 301-loop because PHP would still
see the inbound HTTP from the internal LB hop.

---

## 2. Headers shipped by `App\Http\Middleware\SecurityHeaders`

| Header | Value | Notes |
|---|---|---|
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains; preload` | Sent only when `app()->environment('production') && $request->isSecure()` |
| `X-Content-Type-Options` | `nosniff` | Static |
| `X-Frame-Options` | `SAMEORIGIN` | Static (overridable per-route) |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | Static |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=(), payment=(self), interest-cohort=()` | Static |
| `Cross-Origin-Opener-Policy` | `same-origin` | Static |
| `Cross-Origin-Resource-Policy` | `same-site` | Static |
| `Content-Security-Policy[-Report-Only]` | from `config/security.php → headers.csp` | Built dynamically, with optional `report-uri` if `security.csp-report` route exists |

Binary responses (image/*, video/*, application/octet-stream, BinaryFileResponse)
are skipped to avoid bloating CDN cache entries with irrelevant meta-headers.

### HSTS preload directive — VERIFIED PRESENT

The HSTS header includes the `preload` directive, satisfying the first
requirement of the [HSTS Preload List](https://hstspreload.org). Source:
`app/Http/Middleware/SecurityHeaders.php` line ~70.

---

## 3. HSTS Preload eligibility checklist

Per <https://hstspreload.org/#submission-requirements>, the apex domain only
becomes eligible once **all** of the following are true in production:

- [x] HSTS sent on every HTTPS response — handled by `SecurityHeaders` middleware.
- [x] `max-age` >= 31536000 (one year) — currently `31536000`.
- [x] `includeSubDomains` directive present.
- [x] `preload` directive present.
- [ ] Apex domain serves a valid TLS certificate **AND** all subdomains it
      claims to cover. Verify with `openssl s_client -connect flik.id:443`
      and `openssl s_client -connect www.flik.id:443` once production DNS is live.
- [ ] HTTP -> HTTPS redirect on the apex domain returns 301 (not 302) and
      includes the HSTS header on the HTTPS response. `ForceHttps` returns
      301 — confirm with `curl -I http://flik.id/`.
- [ ] No subdomain serves HTTP-only content. Audit DNS with `dig flik.id any`
      and ensure every A/AAAA record either redirects to HTTPS or has its
      own valid certificate.

### Submission steps (post-deploy, do not run before)

1. Wait at least 7 days after deploying with the production HSTS header so
   you can confirm there are no broken subdomains. Removing yourself from
   the preload list later takes months, so this is a one-way door.
2. Visit <https://hstspreload.org> and submit `flik.id`.
3. Track approval via the same form (it shows pending / approved / removed).
4. After approval, Chrome / Firefox / Safari / Edge will hard-refuse plain
   HTTP for `flik.id` and every subdomain — even on first visit.

> Domain registration to be performed by Platform Ops once the production
> domain is finalized. This file documents the contract; `SecurityHeaders`
> already sends the correct directive value.

---

## 4. Cookie security (paired requirement)

`config/session.php`:

- `secure` => `env('SESSION_SECURE_COOKIE', null)` — must be `true` in prod.
- `same_site` => `'lax'` — default, mitigates CSRF on top-level navigations.
- `http_only` => `true` — JS cannot read the session cookie (defence vs XSS theft).

Without `SESSION_SECURE_COOKIE=true`, the session cookie can leak over
plain HTTP if any layer (inbound or outbound) misconfiguration falls back
to HTTP. The `.env.example` carries this reminder inline.

---

## 5. Verifying in production

```bash
# Confirm headers on a real request
curl -sSI https://flik.id/ | grep -iE 'strict-transport|x-frame|content-security|x-content-type|referrer-policy|permissions-policy|cross-origin'

# Confirm HTTP -> HTTPS 301
curl -sSI http://flik.id/ | head -1   # expect: HTTP/1.1 301 Moved Permanently

# Confirm Set-Cookie carries Secure + HttpOnly + SameSite=Lax for the session
curl -sSI -X POST https://flik.id/login | grep -i set-cookie
```

If any of these fail in production, treat as P1 and fix before continuing
with the HSTS preload submission.
