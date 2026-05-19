<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\EmailCampaign;
use App\Models\EmailLinkClick;
use App\Models\EmailRecipient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Public, unauthenticated tracking endpoints for email campaigns.
 *
 * These are hit by mail clients on EVERY image-render and link-click. They
 * must:
 *   - Never throw to the user (always 1x1 gif / redirect).
 *   - Be idempotent at the per-recipient level (first open/click flips a
 *     timestamp; subsequent ones are no-ops for the aggregate counter).
 *   - Tolerate broken/expired/forged tracking IDs by returning a no-op
 *     pixel or redirecting to the home page (don't leak status via 404).
 *
 * No CSRF because the calls originate from mail clients, not our forms.
 * The tracking_id itself acts as a capability token — 32 chars of CSPRNG
 * randomness is ~190 bits of entropy, well beyond brute-force feasibility.
 *
 * The routes are throttled at the network edge (the standard `throttle:1000,1`
 * on /email/track/* in routes/web.php) so an attacker can't DoS the open
 * counters with a botnet.
 */
class EmailTrackingController extends Controller
{
    /**
     * 1×1 transparent GIF served back on every open-pixel request.
     * Inlined so we never miss a hit due to disk/CDN latency.
     */
    private const PIXEL_BYTES = "\x47\x49\x46\x38\x39\x61\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xFF\xFF\xFF\x21\xF9\x04\x01\x00\x00\x00\x00\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00\x3B";

    /**
     * Open-pixel endpoint. The .gif suffix on the URL is purely cosmetic
     * (some mail clients only fetch image-looking URLs) — the {trackingId}
     * route parameter is the bare 32-char token.
     */
    public function open(string $trackingId): Response
    {
        $token = $this->normalizeTrackingId($trackingId);

        if ($token !== null) {
            try {
                $this->recordOpen($token);
            } catch (\Throwable $e) {
                // Never fail-loud on a tracking pixel — mail clients hit
                // this on EVERY render. A blip in the DB shouldn't show
                // up as a broken image in the recipient's inbox.
                Log::warning('EmailTrackingController open() failed', [
                    'tracking_id' => $token,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        return response(self::PIXEL_BYTES, 200, [
            'Content-Type'  => 'image/gif',
            'Content-Length' => (string) strlen(self::PIXEL_BYTES),
            // Strong no-cache so an ISP/transparent proxy doesn't cache the
            // pixel and rob us of subsequent opens.
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma'        => 'no-cache',
            'Expires'       => '0',
        ]);
    }

    /**
     * Click-tracker. Validates the base64url-encoded ?u= URL, records the
     * click, then 302s the user to the original destination.
     *
     * Open-redirect protection: we only redirect to URLs whose scheme is
     * http/https. Any other scheme (javascript:, data:, file:) is rejected
     * with a redirect to home. This is defence-in-depth — the URLs we wrap
     * come from admin-authored campaign HTML, but a forged ?u= param from
     * a phishing copycat would otherwise let attackers abuse our domain
     * as an open redirector.
     */
    public function click(string $trackingId, Request $request): RedirectResponse
    {
        $token = $this->normalizeTrackingId($trackingId);
        $encoded = (string) $request->query('u', '');

        $destination = $this->decodeAndValidateUrl($encoded);

        if ($token !== null && $destination !== null) {
            try {
                $this->recordClick($token, $destination);
            } catch (\Throwable $e) {
                Log::warning('EmailTrackingController click() failed', [
                    'tracking_id' => $token,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        // Fallback target: home page — never leak status via 404 for forged
        // tracking IDs (would let attackers enumerate valid tokens).
        $redirect = $destination ?? url('/');

        return redirect()->away($redirect);
    }

    // ── Internal ───────────────────────────────────────────────

    private function recordOpen(string $trackingId): void
    {
        $recipient = EmailRecipient::query()->where('tracking_id', $trackingId)->first();

        if (!$recipient || $recipient->opened_at !== null) {
            // Either no such recipient OR already opened — both are no-ops
            // for the aggregate counter.
            return;
        }

        DB::transaction(function () use ($recipient): void {
            // Re-check inside the transaction so two parallel renders can't
            // both bump the counter.
            $updated = EmailRecipient::query()
                ->where('id', $recipient->id)
                ->whereNull('opened_at')
                ->update(['opened_at' => now()]);

            if ($updated === 1) {
                EmailCampaign::query()
                    ->where('id', $recipient->email_campaign_id)
                    ->increment('open_count');
            }
        });
    }

    private function recordClick(string $trackingId, string $url): void
    {
        $recipient = EmailRecipient::query()->where('tracking_id', $trackingId)->first();

        if (!$recipient) {
            return;
        }

        DB::transaction(function () use ($recipient, $url): void {
            // Always record the click row — that's how we power the per-link
            // breakdown in the report.
            EmailLinkClick::create([
                'email_recipient_id' => $recipient->id,
                'original_url'       => mb_substr($url, 0, 2000),
                'clicked_at'         => now(),
            ]);

            // First click also implies an open — many mail clients suppress
            // images entirely, so a click is the only signal we ever see.
            $opens = 0;
            if ($recipient->opened_at === null) {
                $opens = EmailRecipient::query()
                    ->where('id', $recipient->id)
                    ->whereNull('opened_at')
                    ->update(['opened_at' => now()]);
            }

            $firstClick = false;
            if ($recipient->first_clicked_at === null) {
                $updated = EmailRecipient::query()
                    ->where('id', $recipient->id)
                    ->whereNull('first_clicked_at')
                    ->update(['first_clicked_at' => now()]);
                $firstClick = $updated === 1;
            }

            // Bump the campaign-level counters only on first events.
            $bumps = [];
            if ($opens === 1) {
                $bumps[] = 'open_count';
            }
            if ($firstClick) {
                $bumps[] = 'click_count';
            }

            foreach ($bumps as $col) {
                EmailCampaign::query()
                    ->where('id', $recipient->email_campaign_id)
                    ->increment($col);
            }
        });
    }

    /**
     * Validate the route parameter is plausibly a tracking token. Returns
     * the normalised 32-char token, or null if the segment is malformed.
     * Tolerant of trailing '.gif' that Laravel might leave in the value
     * when the route is mounted with a regex constraint that absorbs the
     * suffix.
     */
    private function normalizeTrackingId(string $raw): ?string
    {
        $value = trim($raw);

        // Strip trailing .gif if present (open-pixel suffix).
        if (str_ends_with(strtolower($value), '.gif')) {
            $value = substr($value, 0, -4);
        }

        // 32 chars of Str::random alphabet — alnum.
        if (!preg_match('/\A[A-Za-z0-9]{32}\z/', $value)) {
            return null;
        }

        return $value;
    }

    /**
     * Decode the base64url ?u= parameter and verify the result is a safe
     * http(s) URL. Returns null on any failure (caller falls back to /).
     */
    private function decodeAndValidateUrl(string $encoded): ?string
    {
        if ($encoded === '') {
            return null;
        }

        // Cap length so we don't try to decode multi-megabyte garbage.
        if (strlen($encoded) > 2048) {
            return null;
        }

        $b64 = strtr($encoded, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad > 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }

        $decoded = base64_decode($b64, true);
        if ($decoded === false || $decoded === '') {
            return null;
        }

        $scheme = parse_url($decoded, PHP_URL_SCHEME);
        if (!is_string($scheme)) {
            return null;
        }

        $scheme = strtolower($scheme);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        // Final guard: filter_var rejects anything that PHP's URL parser
        // considers structurally broken (control chars, bare host, etc.).
        if (filter_var($decoded, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return $decoded;
    }
}
