<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\EmailCampaign;
use App\Models\EmailRecipient;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * CampaignMailable — renders an EmailCampaign for a specific recipient.
 *
 * Responsibilities:
 *   - Replace personalization tokens ({{first_name}}, {{plan_name}}, …) in
 *     subject + body using values pulled from the recipient's User row.
 *   - Inject the 1×1 tracking pixel at the bottom of the HTML body.
 *   - Rewrite every <a href> in the HTML body to route through the click
 *     tracker (which then 302s back to the original URL).
 *
 * The actual Blade shell (`emails.campaign`) wraps the prepared HTML body
 * in the gold/dark FLiK brand chrome. Plain-text fallback (campaign.plain_body
 * or auto-generated) is included so spam filters score the message higher.
 */
class CampaignMailable extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public EmailCampaign $campaign,
        public EmailRecipient $recipient,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->renderedSubject(),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.campaign',
            with: [
                'campaign'   => $this->campaign,
                'recipient'  => $this->recipient,
                'subject'    => $this->renderedSubject(),
                'preheader'  => $this->renderedPreheader(),
                'bodyHtml'   => $this->prepareHtmlBody(),
                'bodyText'   => $this->prepareTextBody(),
            ],
        );
    }

    // ── Personalization ───────────────────────────────────────

    /**
     * @return array<string, string>
     */
    private function tokens(): array
    {
        $user = $this->recipient->user;

        $name = $user?->name ?? '';
        $first = $name !== ''
            ? (strtok($name, ' ') ?: $name)
            : 'Sobat FLiK';

        $planName = '';
        if ($user) {
            try {
                $planName = (string) ($user->currentPlan()?->name ?? '');
            } catch (\Throwable) {
                // currentPlan() may hit DB columns that don't exist in tests
                $planName = '';
            }
        }

        return [
            '{{first_name}}' => $first,
            '{{name}}'       => $name !== '' ? $name : 'Sobat FLiK',
            '{{email}}'      => (string) $this->recipient->email,
            '{{plan_name}}'  => $planName !== '' ? $planName : 'FLiK',
        ];
    }

    private function renderedSubject(): string
    {
        $subject = strtr((string) $this->campaign->subject, $this->tokens());
        // Subject must be single line + reasonable length.
        $subject = trim(preg_replace('/\s+/', ' ', $subject) ?? $subject);
        return mb_substr($subject, 0, 200);
    }

    private function renderedPreheader(): string
    {
        $pre = (string) ($this->campaign->preheader ?? '');
        return $pre === '' ? '' : strtr($pre, $this->tokens());
    }

    // ── Body preparation ──────────────────────────────────────

    private function prepareHtmlBody(): string
    {
        $html = strtr((string) $this->campaign->html_body, $this->tokens());
        $html = $this->rewriteLinks($html);
        $html .= $this->trackingPixelHtml();
        return $html;
    }

    private function prepareTextBody(): string
    {
        $plain = (string) ($this->campaign->plain_body ?? '');

        if ($plain === '') {
            // Auto-generate plain-text fallback from the HTML.
            $plain = trim(strip_tags((string) $this->campaign->html_body));
        }

        return strtr($plain, $this->tokens());
    }

    /**
     * Replace every <a href="X"> with a click-tracker URL that 302s back
     * to X. Anchors without href, mailto:/tel:/javascript: links, and the
     * tracker URL itself are left alone (avoid double-wrapping on retry).
     */
    private function rewriteLinks(string $html): string
    {
        if ($html === '') return '';

        $trackingId = (string) $this->recipient->tracking_id;

        // Build the tracker base. Pass the route through url() so APP_URL
        // is honoured (we're rendering OUTSIDE a real HTTP request — the
        // queue worker has no request context).
        $base = route('email.track.click', ['trackingId' => $trackingId]);

        return preg_replace_callback(
            '/<a\b([^>]*?)\bhref\s*=\s*("|\')([^"\']+)\2([^>]*)>/i',
            function (array $m) use ($base): string {
                $before = $m[1];
                $quote  = $m[2];
                $url    = $m[3];
                $after  = $m[4];

                // Skip non-trackable schemes + already-wrapped links.
                $lower = strtolower($url);
                if (str_starts_with($lower, 'mailto:')
                    || str_starts_with($lower, 'tel:')
                    || str_starts_with($lower, 'sms:')
                    || str_starts_with($lower, 'javascript:')
                    || str_starts_with($lower, '#')
                    || str_starts_with($url, $base)
                ) {
                    return $m[0];
                }

                $wrapped = $base . '?u=' . $this->base64UrlEncode($url);

                return '<a' . $before . 'href=' . $quote . htmlspecialchars($wrapped, ENT_QUOTES, 'UTF-8') . $quote . $after . '>';
            },
            $html,
        ) ?? $html;
    }

    private function trackingPixelHtml(): string
    {
        // Route pattern is /email/track/open/{trackingId}.gif — the .gif is
        // a literal suffix on the URL but the {trackingId} parameter is JUST
        // the 32-char token. The controller strips the .gif at the routing
        // layer, so the URL builder needs to append it explicitly.
        $url = route('email.track.open', ['trackingId' => $this->recipient->tracking_id]);

        // Render the pixel last so any mail-client image-blocker that
        // strips images doesn't also strip the body.
        return '<img src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
             . '" alt="" width="1" height="1" style="display:block;border:0;width:1px;height:1px" />';
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
