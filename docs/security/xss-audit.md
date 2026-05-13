# XSS Audit — FLiK (swarm 26)

Date: 2026-05-13
Scope: every Blade template, every JS rendering site, every JSON
endpoint that may be re-rendered into HTML downstream.

This document records the audit findings, the classification, and the
remediation that was applied. Re-run the searches in
[Methodology](#methodology) before each release; if a new `{!! !!}`,
`v-html`, `x-html`, or raw `.innerHTML =` shows up that isn't on the
allow list below, treat it as a release blocker until classified.

---

## TL;DR

| Surface | Total | SAFE (server-controlled) | RISKY (user input) |
|---|---|---|---|
| `{!! !!}` in Blade | 5 | 5 | 0 |
| `x-html` in Blade | 1 | 1 (escapes first) | 0 |
| `.innerHTML =` in JS | 25 | 24 (static templates / clearing) | 1 (chat — uses `escapeHtml()`) |

No live XSS sinks were found. We added defence-in-depth anyway:

1. New `App\Services\Security\HtmlSanitizer` service — DOM-based,
   whitelist tags + per-tag attribute allow list, validates `href` for
   http/https, strips every `on*` event handler.
2. `Comment::setBodyAttribute` now sanitizes on save. The DB never
   stores raw user HTML.
3. `WatchPartyChat` event sanitizes the chat message at construction
   time so the broadcast payload is always safe.
4. New `@safe($html)` Blade directive for render-time sanitization
   (defence in depth on top of save-time sanitization).
5. PHPUnit suite `tests/Unit/HtmlSanitizerTest.php` with 17 OWASP-style
   payloads as regression tests.

---

## Methodology

```bash
# 1. Find every {!! !!} sink
rg -n '\{!!' resources/views/

# 2. Find every JS DOM-write
rg -n 'innerHTML|outerHTML|insertAdjacentHTML|v-html|x-html|document\.write' resources/

# 3. Find JSON responses that may carry user input
rg -n 'response\(\)->json' app/Http/Controllers/
```

---

## `{!! !!}` Findings

| File | Line | Classification | Notes |
|---|---|---|---|
| `resources/views/components/icon.blade.php` | 69 | **SAFE** | Renders one of a hard-coded `$paths[]` SVG path string keyed by `$name`. `$svg` is server-controlled and falls back to `''` when the name is unknown. No user input ever reaches this sink. |
| `resources/views/components/seo/movie-jsonld.blade.php` | 81 | **SAFE** | Emits `<script type="application/ld+json">` body using `json_encode(..., JSON_UNESCAPED_SLASHES \| JSON_UNESCAPED_UNICODE \| JSON_THROW_ON_ERROR)`. JSON-LD inside a `application/ld+json` block is parsed as data, not script. Movie title / description come from the admin-curated catalog. |
| `resources/views/admin/sentiment/dashboard.blade.php` | 163 | **SAFE** | `$proportionBar(...)` is a closure defined two blocks above; output is purely arithmetic (`width:%` style) with no string interpolation from user data. |
| `resources/views/admin/sentiment/dashboard.blade.php` | 244 | **SAFE** | Same closure — per-row metrics. Numbers only. |
| `resources/views/emails/daily-admin-report.blade.php` | 77 | **SAFE** | Uses the canonical `nl2br(e(trim($paragraph)))` pattern: `e()` runs first, `nl2br()` only adds `<br>` between already-escaped lines. Standard Laravel idiom for "preserve newlines, escape everything else". |

No remediation required for any `{!! !!}` site.

---

## `x-html` / `v-html` Findings

| File | Line | Classification | Notes |
|---|---|---|---|
| `resources/views/components/home/chatbot-widget.blade.php` | 50 | **SAFE (with caveat)** | Bot responses are rendered with `renderMarkdown()`. That function (a) escapes HTML first, then (b) re-introduces a tiny allow list (`<strong>`, `<em>`, `<a>`, `<ul>`, `<li>`, `<br>`). The link regex rejects schemes other than `http://`, `https://`, or `/movie/...`. Caveat: any future markdown rule MUST keep the escape-first ordering — added an inline comment to make this hard to miss. |

User messages on the same widget go through `escapeHtml()` (no
markdown). Safe.

---

## `.innerHTML =` Findings (frontend)

Categorised:

- **Static template strings (24)** — all in admin/marketing-ai blade
  views. Each is either:
  - Setting `innerHTML = ''` (clearing — no XSS surface), or
  - Writing literal HTML strings with no dynamic interpolation, or
  - Writing dynamic HTML using `escapeHtml()` for every interpolated
    field.

  Files: `resources/views/admin/marketing-ai/{social,banner}.blade.php`,
  `resources/views/admin/marketing-ops/{cs-reply-drafter,tiktok-clips,email-subjects,title-alternatives}.blade.php`,
  `resources/js/player/xray-overlay.js` (clearing only — actor name and
  bio use `textContent`).

- **Dynamic user data (1)** — `resources/js/watch-party.js` line 63.
  Already wraps both `name` and `message` in `escapeHtml()`. We
  added a defensive comment + the server-side `WatchPartyChat` event
  now sanitizes via `HtmlSanitizer` so the broadcast payload is safe
  even if the frontend regresses.

No DOMPurify dependency was added — the existing `escapeHtml()`
pattern is sufficient for the current sites. If a future feature
needs to render HTML from user input (e.g. rich-text comments rendered
via Alpine), load DOMPurify via CDN and feed every interpolation
through `DOMPurify.sanitize(s, { ALLOWED_TAGS: ['strong','em','a',...] })`.

---

## JSON Response Audit

JSON endpoints that may carry user-controlled strings:

| Endpoint | User-input field | Risk | Notes |
|---|---|---|---|
| `POST /chat` | `data.reply` | low | The reply is AI-generated, not user input. Frontend renders via `renderMarkdown()` which escapes first. |
| `GET /api/search` (`SmartSearchController`) | `movies[].title` etc. | none | Fields come from the admin-curated catalog. |
| `POST /api/movies/{id}/plot-explain` | `data.summary` | low | AI text. Rendered via `escapeHtml()` in the consumer. |
| `POST /watch-party/{code}/chat` | `data.warning` | none | Server-emitted error string (not user data). Chat content goes through Pusher, not the JSON response. |

None of the JSON endpoints route user-controlled HTML straight back to
an `innerHTML` sink without the consumer escaping first.

---

## Remediation Summary

### 1. New service: `App\Services\Security\HtmlSanitizer`

DOM-based whitelist sanitizer. See `app/Services/Security/HtmlSanitizer.php`
and `tests/Unit/HtmlSanitizerTest.php` for the spec + test suite.

**Allowed tags** (per audit spec):
`b, strong, i, em, u, p, br, a, ul, ol, li, blockquote, code, pre`.

**Allowed attributes**: `href` on `<a>` (validated http://, https://,
relative `/...`, or protocol-relative `//host`).

**Always stripped**:
- `<script>`, `<style>`, `<iframe>`, `<object>`, `<embed>`, `<form>`,
  `<input>` (regex pre-pass + whitelist enforcement).
- Every `on*` event handler attribute.
- Any unknown tag is **unwrapped** (children kept as text/elements,
  the wrapper itself removed) so legitimate content survives.
- `<a>` with no surviving `href` is unwrapped to its text.

### 2. `Comment::setBodyAttribute`

```php
public function setBodyAttribute(?string $value): void
{
    $this->attributes['body'] = app(HtmlSanitizer::class)->sanitize($value);
}
```

Comments are sanitized on save. Existing rows are unchanged — a
backfill is not required because the rendering side uses `{{ }}`
(escaped). For new rows, the DB value is already safe.

### 3. `WatchPartyChat` event

`__construct` now runs `app(HtmlSanitizer::class)->sanitize($message)`
before storing on `$this->message`. The Pusher payload, the future
moderation log, and any transcript export will all see the safe form.

### 4. `@safe($html)` Blade directive

Registered in `App\Providers\AppServiceProvider::boot()`:

```blade
{{-- prefer this when rendering server-stored HTML --}}
@safe($comment->body)

{{-- equivalent inline form --}}
{!! app(\App\Services\Security\HtmlSanitizer::class)->sanitize($comment->body) !!}
```

Currently NOT wired into `resources/views/components/movies/show.blade.php`
because every comment site there uses `{{ $comment->body }}` (escaped),
which is already XSS-safe. If the UI ever switches to rendering HTML
formatting in comments (bold, italics, links), swap those `{{ }}` for
`@safe(...)` and the formatting will render safely.

### 5. Tests

`tests/Unit/HtmlSanitizerTest.php` — 17 tests, 45 assertions, covers:

- `<script>alert(1)</script>` payload
- `<img src=x onerror=alert(1)>` payload
- `onclick="..."` on whitelisted tag
- `javascript:`, `data:`, `vbscript:` URLs
- `<iframe>`, `<style>`, `<form>`, `<input>` strip
- nested-mutation `<scr<script>ipt>` payload
- relative + protocol-relative URL preservation
- legitimate `<strong>`/`<em>`/`<p>` round trip
- Indonesian Unicode preservation (em-dash, accented chars)
- null / empty / whitespace-only input

Run with:

```bash
./vendor/bin/phpunit tests/Unit/HtmlSanitizerTest.php
```

---

## Files Changed

- `app/Services/Security/HtmlSanitizer.php` (new)
- `app/Models/Comment.php` (mutator added)
- `app/Events/WatchPartyChat.php` (sanitize on construct)
- `app/Providers/AppServiceProvider.php` (register service + `@safe` directive)
- `resources/js/watch-party.js` (defensive comment)
- `resources/views/components/home/chatbot-widget.blade.php` (defensive comment)
- `tests/Unit/HtmlSanitizerTest.php` (new)
- `docs/security/xss-audit.md` (this file)
