<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * /admin/infrastructure — Dynamic tech-stack configuration.
 *
 * One stop UI for switching DRM provider, CDN target, storage disk,
 * realtime broker, payment gateway, email driver, etc. — all without
 * touching .env or redeploying. Values land in the `settings` table
 * (group columns: drm/cdn/storage/realtime/payment/email/integrations).
 *
 * The downstream services read these via `setting('drm.provider', 'aes')`
 * etc. — so flipping a value here changes runtime behaviour on the
 * next request.
 */
class InfrastructureController extends Controller
{
    /**
     * Catalogue of every knob exposed via the UI. The view loops over
     * this and renders the right input type per row. Adding a new
     * setting? Add it here + seed a row in settings table.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function catalogue(): array
    {
        return [
            'drm' => [
                ['key' => 'drm.provider', 'label' => 'DRM Provider', 'type' => 'select', 'options' => [
                    'aes-128'    => 'HLS AES-128 (built-in, free)',
                    'widevine'   => 'Widevine L3 (Chrome/Android/Edge)',
                    'fairplay'   => 'FairPlay (Safari/iOS/tvOS)',
                    'playready'  => 'PlayReady (Edge/Xbox)',
                    'multi-cenc' => 'Multi-DRM CENC (Widevine + PlayReady + FairPlay)',
                ], 'default' => 'aes-128', 'help' => 'Switch only after license server is configured below.'],
                ['key' => 'drm.license_server_url', 'label' => 'License Server URL', 'type' => 'url',
                    'default' => '', 'help' => 'Required for widevine/playready/fairplay/multi-cenc (PallyCon, ezDRM, BuyDRM).', 'secret' => true],
                ['key' => 'drm.license_token_secret', 'label' => 'License Token Secret', 'type' => 'password',
                    'default' => '', 'help' => 'Shared secret for signing DRM license tokens.', 'secret' => true],
                ['key' => 'drm.allow_episode_raw_mp4', 'label' => 'Allow raw mp4 fallback for episodes', 'type' => 'bool',
                    'default' => '0', 'help' => 'Dev only — keep OFF in production. When on, episode playback skips DRM if HLS not ready.'],
                ['key' => 'drm.concurrent_lock_ttl', 'label' => 'Concurrent Lock TTL (seconds)', 'type' => 'int',
                    'default' => '120', 'help' => 'Auto-cleanup grace after player stops sending heartbeat.'],
                ['key' => 'drm.jwt_lifetime_minutes', 'label' => 'DRM JWT Lifetime (minutes)', 'type' => 'int',
                    'default' => '240', 'help' => 'How long a key-fetch token stays valid (default 4 jam).'],
                ['key' => 'drm.forensic_watermark_enabled', 'label' => 'Forensic Watermark', 'type' => 'bool',
                    'default' => '1', 'help' => 'Embed per-session ID in manifest metadata for leak tracing.'],
            ],

            'cdn' => [
                ['key' => 'cdn.driver', 'label' => 'CDN / Storage Backend', 'type' => 'select', 'options' => [
                    'bunny'       => 'Bunny CDN (recommended, cheap, HLS-optimized)',
                    's3'          => 'AWS S3 + CloudFront',
                    'local'       => 'Local disk (no CDN — dev/small scale only)',
                    'r2'          => 'Cloudflare R2',
                    'spaces'      => 'DigitalOcean Spaces',
                ], 'default' => 'bunny', 'help' => 'Where encoded HLS segments live + serve from.'],
                ['key' => 'cdn.bunny.storage_zone', 'label' => 'Bunny Storage Zone', 'type' => 'text', 'default' => ''],
                ['key' => 'cdn.bunny.access_key', 'label' => 'Bunny Storage Access Key', 'type' => 'password', 'default' => '', 'secret' => true],
                ['key' => 'cdn.bunny.pull_zone_url', 'label' => 'Bunny Pull Zone URL', 'type' => 'url', 'default' => 'https://flik.b-cdn.net'],
                ['key' => 'cdn.bunny.token_key', 'label' => 'Bunny Token Authentication Key', 'type' => 'password', 'default' => '', 'secret' => true,
                    'help' => 'For signed URL — leak-resistant segment delivery.'],
                ['key' => 'cdn.signed_url_ttl_minutes', 'label' => 'Signed Segment URL TTL (minutes)', 'type' => 'int',
                    'default' => '120', 'help' => 'How long a segment URL stays valid before user must re-request.'],
            ],

            'storage' => [
                ['key' => 'storage.master_disk', 'label' => 'Master Video Storage Disk', 'type' => 'select', 'options' => [
                    'private' => 'Local private (storage/app/private)',
                    's3'      => 'AWS S3 (encrypted)',
                    'bunny'   => 'Bunny Storage (cheaper than S3)',
                    'glacier' => 'AWS S3 Glacier (cold archive — slow restore)',
                ], 'default' => 'private', 'help' => 'Where uncompressed HD master file is kept (for re-encoding to higher resolution later).'],
                ['key' => 'storage.master_keep_after_encode', 'label' => 'Keep Master After Encoding', 'type' => 'bool',
                    'default' => '1', 'help' => 'Off = delete master after first encoding finishes (saves storage, but cannot re-encode to higher quality later).'],
                ['key' => 'storage.image_disk', 'label' => 'Poster & Backdrop Disk', 'type' => 'select', 'options' => [
                    'public' => 'Local public (storage/app/public, asset() URL)',
                    's3'     => 'AWS S3',
                    'bunny'  => 'Bunny Storage',
                ], 'default' => 'public'],
                ['key' => 'storage.subtitle_disk', 'label' => 'Subtitle WebVTT Disk', 'type' => 'select', 'options' => [
                    'public' => 'Local public',
                    's3'     => 'AWS S3',
                    'bunny'  => 'Bunny Storage',
                ], 'default' => 'public'],
            ],

            'realtime' => [
                ['key' => 'realtime.driver', 'label' => 'Realtime Broker', 'type' => 'select', 'options' => [
                    'pusher'   => 'Pusher Channels (managed SaaS)',
                    'reverb'   => 'Laravel Reverb (self-host, Pusher-protocol)',
                    'soketi'   => 'Soketi (self-host, Pusher-protocol)',
                    'ably'     => 'Ably (managed SaaS)',
                    'polling'  => 'Polling only (no realtime — fallback)',
                ], 'default' => 'pusher'],
                ['key' => 'realtime.pusher.app_id', 'label' => 'Pusher App ID', 'type' => 'text', 'default' => ''],
                ['key' => 'realtime.pusher.app_key', 'label' => 'Pusher App Key', 'type' => 'text', 'default' => ''],
                ['key' => 'realtime.pusher.app_secret', 'label' => 'Pusher App Secret', 'type' => 'password', 'default' => '', 'secret' => true],
                ['key' => 'realtime.pusher.cluster', 'label' => 'Pusher Cluster', 'type' => 'text', 'default' => 'ap1',
                    'help' => 'ap1=Singapore (closest to ID), ap2=Mumbai, us2=N. Virginia.'],
                ['key' => 'realtime.polling_interval_seconds', 'label' => 'Polling Interval (s) for Fallback', 'type' => 'int',
                    'default' => '30'],
            ],

            'payment' => [
                ['key' => 'payment.provider', 'label' => 'Payment Gateway', 'type' => 'select', 'options' => [
                    'midtrans' => 'Midtrans (Indonesia native, GoPay/OVO/etc)',
                    'xendit'   => 'Xendit (Asia Tenggara, similar coverage)',
                    'doku'     => 'Doku',
                    'stripe'   => 'Stripe (global, no GoPay/OVO)',
                    'none'     => 'Disabled (free-plan only)',
                ], 'default' => 'midtrans'],
                ['key' => 'payment.midtrans.is_production', 'label' => 'Midtrans Production Mode', 'type' => 'bool',
                    'default' => '0', 'help' => 'OFF = sandbox (test mode). Turn ON only after merchant verified.'],
                ['key' => 'payment.midtrans.server_key', 'label' => 'Midtrans Server Key', 'type' => 'password', 'default' => '', 'secret' => true],
                ['key' => 'payment.midtrans.client_key', 'label' => 'Midtrans Client Key', 'type' => 'text', 'default' => ''],
                ['key' => 'payment.midtrans.merchant_id', 'label' => 'Midtrans Merchant ID', 'type' => 'text', 'default' => ''],
            ],

            'email' => [
                ['key' => 'email.driver', 'label' => 'Mail Driver', 'type' => 'select', 'options' => [
                    'smtp'      => 'SMTP (any provider)',
                    'ses'       => 'AWS SES (cheap, high volume)',
                    'mailgun'   => 'Mailgun',
                    'postmark'  => 'Postmark (highest deliverability)',
                    'resend'    => 'Resend (developer-friendly)',
                    'sendgrid'  => 'SendGrid',
                    'log'       => 'Log only (dev — writes to laravel.log)',
                ], 'default' => 'smtp'],
                ['key' => 'email.from_address', 'label' => 'From Address', 'type' => 'email', 'default' => 'noreply@flik.id'],
                ['key' => 'email.from_name', 'label' => 'From Name', 'type' => 'text', 'default' => 'FLiK'],
                ['key' => 'email.smtp.host', 'label' => 'SMTP Host', 'type' => 'text', 'default' => ''],
                ['key' => 'email.smtp.port', 'label' => 'SMTP Port', 'type' => 'int', 'default' => '587'],
                ['key' => 'email.smtp.username', 'label' => 'SMTP Username', 'type' => 'text', 'default' => ''],
                ['key' => 'email.smtp.password', 'label' => 'SMTP Password', 'type' => 'password', 'default' => '', 'secret' => true],
                ['key' => 'email.smtp.encryption', 'label' => 'SMTP Encryption', 'type' => 'select', 'options' => ['tls' => 'TLS', 'ssl' => 'SSL', 'none' => 'None'], 'default' => 'tls'],
            ],

            'integrations' => [
                ['key' => 'integrations.tmdb_api_key', 'label' => 'TMDB API Key', 'type' => 'password', 'default' => '', 'secret' => true,
                    'help' => 'For /admin/tmdb-import wizard. Get free key at themoviedb.org/settings/api'],
                ['key' => 'integrations.google_client_id', 'label' => 'Google OAuth Client ID', 'type' => 'text', 'default' => ''],
                ['key' => 'integrations.google_client_secret', 'label' => 'Google OAuth Client Secret', 'type' => 'password', 'default' => '', 'secret' => true],
                ['key' => 'integrations.mailchimp_api_key', 'label' => 'Mailchimp API Key', 'type' => 'password', 'default' => '', 'secret' => true,
                    'help' => 'For newsletter signup integration.'],
                ['key' => 'integrations.turnstile_site_key', 'label' => 'Cloudflare Turnstile Site Key', 'type' => 'text', 'default' => '',
                    'help' => 'CAPTCHA on login + register + comment forms.'],
                ['key' => 'integrations.turnstile_secret_key', 'label' => 'Cloudflare Turnstile Secret Key', 'type' => 'password', 'default' => '', 'secret' => true],
                ['key' => 'integrations.maxmind_account_id', 'label' => 'MaxMind Account ID', 'type' => 'text', 'default' => '',
                    'help' => 'For weekly GeoLite2 mmdb download (flik:geo:update cron).'],
                ['key' => 'integrations.maxmind_license_key', 'label' => 'MaxMind License Key', 'type' => 'password', 'default' => '', 'secret' => true],
            ],
        ];
    }

    public function index(): View
    {
        $tabs = self::catalogue();
        $current = $this->loadCurrentValues($tabs);

        return view('admin.infrastructure.index', [
            'tabs'    => $tabs,
            'current' => $current,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $tabs = self::catalogue();
        $allKeys = [];
        foreach ($tabs as $group => $items) {
            foreach ($items as $item) {
                $allKeys[] = $item['key'];
            }
        }

        $updated = 0;
        foreach ($allKeys as $key) {
            // Read raw form input (checkbox absent = 0)
            $value = $request->input(str_replace('.', '_', $key));
            if ($value === null) {
                continue;
            }

            try {
                Setting::set($key, (string) $value);
                $updated++;
            } catch (\Throwable $e) {
                Log::warning('Infrastructure: failed to save setting', [
                    'key' => $key, 'error' => $e->getMessage(),
                ]);
            }
        }

        return redirect()->route('admin.infrastructure.index')
            ->with('success', "Berhasil simpan {$updated} setting. Beberapa setting butuh queue worker restart untuk effect penuh.");
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $tabs
     * @return array<string, string>
     */
    protected function loadCurrentValues(array $tabs): array
    {
        $current = [];

        if (! Schema::hasTable('settings')) {
            // Settings table missing — return defaults so UI still renders.
            foreach ($tabs as $items) {
                foreach ($items as $item) {
                    $current[$item['key']] = (string) ($item['default'] ?? '');
                }
            }
            return $current;
        }

        foreach ($tabs as $items) {
            foreach ($items as $item) {
                try {
                    $current[$item['key']] = (string) Setting::get($item['key'], $item['default'] ?? '');
                } catch (\Throwable $e) {
                    $current[$item['key']] = (string) ($item['default'] ?? '');
                }
            }
        }

        return $current;
    }
}
