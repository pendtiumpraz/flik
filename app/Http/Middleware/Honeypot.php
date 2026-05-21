<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Audit\AuditLogger;
use App\Support\SecurityEvents;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Honeypot anti-bot middleware.
 *
 * Two cheap behavioural checks applied to public POST forms (login, register,
 * forgot/reset password, newsletter). Both look at signals that a real user
 * leaves alone but a naive form-flooder fills in / bypasses:
 *
 *   1. Hidden trap field — `<x-honeypot />` renders an off-screen input
 *      (default name `website_url`) with `tabindex=-1`, `aria-hidden=true`,
 *      `autocomplete="off"` and visually-hidden CSS. Real users never see
 *      or focus it. Any non-empty submission is therefore a bot.
 *
 *   2. Form fill-time floor — the same component stamps `_form_start_time`
 *      with the unix timestamp of the render. If the POST arrives within
 *      `min_seconds` (default 2 s) the submission is treated as automated.
 *      The 2 s floor is well below realistic human form-fill latency on the
 *      shortest auth form (login: email + password) yet rejects almost
 *      every drive-by script that POSTs immediately after a GET.
 *
 * Detection response is silent on purpose: we return a generic HTTP 200 with
 * an empty body so a scraper sees what looks like a successful submission
 * and stops retrying / probing for the real failure signal. We log the hit
 * to `audit_logs` with `action='security.honeypot_hit'` plus the trigger
 * reason, IP and user agent so ops can spot bursts.
 *
 * Authenticated users are skipped: the bulk of bot traffic targets pre-auth
 * endpoints, and we never want to break a logged-in user who happens to
 * trip a check (browser autofill putting a value into a same-named field,
 * a slow page that triggers the time floor on resubmit, etc.).
 *
 * Disable globally with `HONEYPOT_ENABLED=false` — the middleware then
 * passes every request through untouched.
 */
final class Honeypot
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Cheapest exits first.
        if (! $this->isEnabled()) {
            return $next($request);
        }

        // Authenticated users are trusted: a logged-in account with a stolen
        // session is a different threat model handled elsewhere (rate limits,
        // 2FA, audit_logs on the action itself). Don't add false-positive
        // friction for real customers.
        if ($request->user() !== null) {
            return $next($request);
        }

        // Only inspect form submissions. GET / HEAD pass through so the
        // component can render the trap field on the way in.
        if (! $request->isMethod('POST')) {
            return $next($request);
        }

        $reason = $this->detect($request);

        if ($reason === null) {
            return $next($request);
        }

        $this->record($request, $reason);

        // Silent fail: HTTP 200, empty body, no flash. A scraper looking
        // for a redirect-to-login or a JSON error sees neither and has no
        // signal to differentiate from success.
        return response('', 200);
    }

    /**
     * Returns null when the request looks human, or a short reason code
     * ('hidden_field' | 'too_fast') when a bot signal fired.
     */
    private function detect(Request $request): ?string
    {
        $field = $this->fieldName();
        $trap = $request->input($field);

        // Anything non-empty in the trap field is a bot. We treat a real
        // string and a string of whitespace identically — humans never
        // touch the field at all.
        if (is_string($trap) && trim($trap) !== '') {
            return 'hidden_field';
        }

        // Some bots send arrays / nested values to break naive checks.
        // Treat any non-string + non-empty value as a hit too.
        if ($trap !== null && ! is_string($trap)) {
            return 'hidden_field';
        }

        $startedAtRaw = $request->input('_form_start_time');

        // Missing timestamp is suspicious — the component always renders
        // it on every protected form. We *don't* fail closed here though,
        // because env-toggling the component off mid-deploy or partial
        // rollouts of templates would otherwise lock real users out.
        // Fall through to the time check only if a value is present.
        if ($startedAtRaw === null || $startedAtRaw === '') {
            return null;
        }

        $startedAt = filter_var($startedAtRaw, FILTER_VALIDATE_INT);
        if ($startedAt === false) {
            // A non-integer here means the field was tampered with —
            // a real form always emits `now()->timestamp`. Treat as bot.
            return 'too_fast';
        }

        $elapsed = max(0, time() - $startedAt);

        if ($elapsed < $this->minSeconds()) {
            return 'too_fast';
        }

        return null;
    }

    /**
     * Persist a single audit row per hit. We use AuditLogger::security()
     * (not log()) so the row is flagged with `is_security=true` and the
     * SecurityEventLogged event fires — driving the admin bell + the
     * Slack/Discord alert pipeline (severity-gated + throttled to 5 min
     * per (event, user) so routine bot traffic does not pager-bomb ops).
     *
     * Failures are swallowed: a broken audit pipeline must never convert
     * a 200 into a 500.
     */
    private function record(Request $request, string $reason): void
    {
        try {
            $this->audit->security(
                event: SecurityEvents::HONEYPOT_HIT,
                subject: null,
                meta: [
                    'reason' => $reason,
                    'route' => $request->route()?->getName() ?: $request->path(),
                    'path' => '/'.ltrim($request->path(), '/'),
                    'method' => $request->getMethod(),
                    'ip' => $request->ip(),
                    'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                ],
            );
        } catch (\Throwable $e) {
            Log::channel('security')->warning('Honeypot: audit write failed', [
                'message' => $e->getMessage(),
                'reason' => $reason,
                'path' => $request->path(),
            ]);
        }
    }

    private function isEnabled(): bool
    {
        return (bool) config('security.honeypot.enabled', true);
    }

    private function fieldName(): string
    {
        $name = (string) config('security.honeypot.field', 'website_url');

        // Defensive default — config could be blanked accidentally.
        return $name !== '' ? $name : 'website_url';
    }

    private function minSeconds(): int
    {
        return max(0, (int) config('security.honeypot.min_seconds', 2));
    }
}
