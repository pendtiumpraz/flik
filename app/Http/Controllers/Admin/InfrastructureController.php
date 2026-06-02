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
                    'default' => '', 'help' => 'Required for widevine/playready/fairplay/multi-cenc (PallyCon, ezDRM, BuyDRM).',
                    'show_when' => ['drm.provider' => ['widevine', 'fairplay', 'playready', 'multi-cenc']]],
                ['key' => 'drm.license_token_secret', 'label' => 'License Token Secret', 'type' => 'password',
                    'default' => '', 'help' => 'Shared secret for signing DRM license tokens.', 'secret' => true,
                    'show_when' => ['drm.provider' => ['widevine', 'fairplay', 'playready', 'multi-cenc']]],
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
                ['key' => 'cdn.bunny.storage_zone', 'label' => 'Bunny Storage Zone', 'type' => 'text', 'default' => '',
                    'show_when' => ['cdn.driver' => ['bunny']]],
                ['key' => 'cdn.bunny.access_key', 'label' => 'Bunny Storage Access Key', 'type' => 'password', 'default' => '', 'secret' => true,
                    'show_when' => ['cdn.driver' => ['bunny']]],
                ['key' => 'cdn.bunny.pull_zone_url', 'label' => 'Bunny Pull Zone URL', 'type' => 'url', 'default' => 'https://flik.b-cdn.net',
                    'show_when' => ['cdn.driver' => ['bunny']]],
                ['key' => 'cdn.bunny.token_key', 'label' => 'Bunny Token Authentication Key', 'type' => 'password', 'default' => '', 'secret' => true,
                    'help' => 'For signed URL — leak-resistant segment delivery.',
                    'show_when' => ['cdn.driver' => ['bunny']]],

                // S3 fields
                ['key' => 'cdn.s3.region', 'label' => 'AWS Region', 'type' => 'text', 'default' => 'ap-southeast-1',
                    'show_when' => ['cdn.driver' => ['s3']]],
                ['key' => 'cdn.s3.bucket', 'label' => 'S3 Bucket Name', 'type' => 'text', 'default' => '',
                    'show_when' => ['cdn.driver' => ['s3']]],
                ['key' => 'cdn.s3.access_key', 'label' => 'AWS Access Key ID', 'type' => 'password', 'default' => '', 'secret' => true,
                    'show_when' => ['cdn.driver' => ['s3']]],
                ['key' => 'cdn.s3.secret_key', 'label' => 'AWS Secret Access Key', 'type' => 'password', 'default' => '', 'secret' => true,
                    'show_when' => ['cdn.driver' => ['s3']]],
                ['key' => 'cdn.s3.cloudfront_domain', 'label' => 'CloudFront Domain (optional)', 'type' => 'text', 'default' => '',
                    'help' => 'Misal: d1234.cloudfront.net — kosongkan kalau pakai S3 langsung.',
                    'show_when' => ['cdn.driver' => ['s3']]],

                // Cloudflare R2
                ['key' => 'cdn.r2.account_id', 'label' => 'Cloudflare Account ID', 'type' => 'text', 'default' => '',
                    'show_when' => ['cdn.driver' => ['r2']]],
                ['key' => 'cdn.r2.access_key', 'label' => 'R2 Access Key', 'type' => 'password', 'default' => '', 'secret' => true,
                    'show_when' => ['cdn.driver' => ['r2']]],
                ['key' => 'cdn.r2.secret_key', 'label' => 'R2 Secret Key', 'type' => 'password', 'default' => '', 'secret' => true,
                    'show_when' => ['cdn.driver' => ['r2']]],
                ['key' => 'cdn.r2.bucket', 'label' => 'R2 Bucket Name', 'type' => 'text', 'default' => '',
                    'show_when' => ['cdn.driver' => ['r2']]],

                // DO Spaces
                ['key' => 'cdn.spaces.region', 'label' => 'DO Spaces Region', 'type' => 'text', 'default' => 'sgp1',
                    'show_when' => ['cdn.driver' => ['spaces']]],
                ['key' => 'cdn.spaces.bucket', 'label' => 'Spaces Bucket', 'type' => 'text', 'default' => '',
                    'show_when' => ['cdn.driver' => ['spaces']]],
                ['key' => 'cdn.spaces.access_key', 'label' => 'Spaces Access Key', 'type' => 'password', 'default' => '', 'secret' => true,
                    'show_when' => ['cdn.driver' => ['spaces']]],
                ['key' => 'cdn.spaces.secret_key', 'label' => 'Spaces Secret', 'type' => 'password', 'default' => '', 'secret' => true,
                    'show_when' => ['cdn.driver' => ['spaces']]],

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
                ['key' => 'realtime.pusher.app_id', 'label' => 'Pusher App ID', 'type' => 'text', 'default' => '',
                    'show_when' => ['realtime.driver' => ['pusher']]],
                ['key' => 'realtime.pusher.app_key', 'label' => 'Pusher App Key', 'type' => 'text', 'default' => '',
                    'show_when' => ['realtime.driver' => ['pusher']]],
                ['key' => 'realtime.pusher.app_secret', 'label' => 'Pusher App Secret', 'type' => 'password', 'default' => '', 'secret' => true,
                    'show_when' => ['realtime.driver' => ['pusher']]],
                ['key' => 'realtime.pusher.cluster', 'label' => 'Pusher Cluster', 'type' => 'text', 'default' => 'ap1',
                    'help' => 'ap1=Singapore (closest to ID), ap2=Mumbai, us2=N. Virginia.',
                    'show_when' => ['realtime.driver' => ['pusher', 'reverb', 'soketi']]],

                // Reverb / Soketi self-host
                ['key' => 'realtime.reverb.host', 'label' => 'Reverb/Soketi Host', 'type' => 'text', 'default' => '',
                    'help' => 'Misal: websocket.flik.id atau localhost:8080',
                    'show_when' => ['realtime.driver' => ['reverb', 'soketi']]],
                ['key' => 'realtime.reverb.port', 'label' => 'Reverb/Soketi Port', 'type' => 'int', 'default' => '8080',
                    'show_when' => ['realtime.driver' => ['reverb', 'soketi']]],
                ['key' => 'realtime.reverb.scheme', 'label' => 'Scheme', 'type' => 'select', 'options' => ['https' => 'HTTPS (wss)', 'http' => 'HTTP (ws)'], 'default' => 'https',
                    'show_when' => ['realtime.driver' => ['reverb', 'soketi']]],

                // Ably
                ['key' => 'realtime.ably.api_key', 'label' => 'Ably API Key', 'type' => 'password', 'default' => '', 'secret' => true,
                    'show_when' => ['realtime.driver' => ['ably']]],

                ['key' => 'realtime.polling_interval_seconds', 'label' => 'Polling Interval (s) for Fallback', 'type' => 'int',
                    'default' => '30', 'help' => 'Berlaku saat driver=polling atau Pusher gagal connect.',
                    'show_when' => ['realtime.driver' => ['polling']]],
            ],

            'payment' => [
                ['key' => 'payment.provider', 'label' => 'Payment Gateway', 'type' => 'select', 'options' => [
                    'midtrans' => 'Midtrans (Indonesia native, GoPay/OVO/etc)',
                    'xendit'   => 'Xendit (Asia Tenggara, similar coverage)',
                    'doku'     => 'Doku',
                    'stripe'   => 'Stripe (global, no GoPay/OVO)',
                    'none'     => 'Disabled (free-plan only)',
                ], 'default' => 'midtrans'],
                // Midtrans fields
                ['key' => 'payment.midtrans.is_production', 'label' => 'Midtrans Production Mode', 'type' => 'bool',
                    'default' => '0', 'help' => 'OFF = sandbox (test mode). Turn ON only after merchant verified.',
                    'show_when' => ['payment.provider' => ['midtrans']]],
                ['key' => 'payment.midtrans.server_key', 'label' => 'Midtrans Server Key', 'type' => 'password', 'default' => '', 'secret' => true,
                    'show_when' => ['payment.provider' => ['midtrans']]],
                ['key' => 'payment.midtrans.client_key', 'label' => 'Midtrans Client Key', 'type' => 'text', 'default' => '',
                    'show_when' => ['payment.provider' => ['midtrans']]],
                ['key' => 'payment.midtrans.merchant_id', 'label' => 'Midtrans Merchant ID', 'type' => 'text', 'default' => '',
                    'show_when' => ['payment.provider' => ['midtrans']]],

                // Xendit fields
                ['key' => 'payment.xendit.secret_key', 'label' => 'Xendit Secret Key', 'type' => 'password', 'default' => '', 'secret' => true,
                    'show_when' => ['payment.provider' => ['xendit']]],
                ['key' => 'payment.xendit.webhook_token', 'label' => 'Xendit Webhook Verification Token', 'type' => 'password', 'default' => '', 'secret' => true,
                    'show_when' => ['payment.provider' => ['xendit']]],
                ['key' => 'payment.xendit.callback_url', 'label' => 'Xendit Callback URL', 'type' => 'url', 'default' => '',
                    'show_when' => ['payment.provider' => ['xendit']]],

                // Doku fields
                ['key' => 'payment.doku.client_id', 'label' => 'Doku Client ID', 'type' => 'text', 'default' => '',
                    'show_when' => ['payment.provider' => ['doku']]],
                ['key' => 'payment.doku.secret_key', 'label' => 'Doku Secret Key', 'type' => 'password', 'default' => '', 'secret' => true,
                    'show_when' => ['payment.provider' => ['doku']]],

                // Stripe fields
                ['key' => 'payment.stripe.publishable_key', 'label' => 'Stripe Publishable Key', 'type' => 'text', 'default' => '',
                    'help' => 'pk_test_... atau pk_live_...',
                    'show_when' => ['payment.provider' => ['stripe']]],
                ['key' => 'payment.stripe.secret_key', 'label' => 'Stripe Secret Key', 'type' => 'password', 'default' => '', 'secret' => true,
                    'help' => 'sk_test_... atau sk_live_...',
                    'show_when' => ['payment.provider' => ['stripe']]],
                ['key' => 'payment.stripe.webhook_secret', 'label' => 'Stripe Webhook Signing Secret', 'type' => 'password', 'default' => '', 'secret' => true,
                    'show_when' => ['payment.provider' => ['stripe']]],
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
                // SMTP fields
                ['key' => 'email.smtp.host', 'label' => 'SMTP Host', 'type' => 'text', 'default' => '',
                    'help' => 'Misal: smtp.gmail.com, smtp.zoho.com, mail.your-domain.com',
                    'show_when' => ['email.driver' => ['smtp']]],
                ['key' => 'email.smtp.port', 'label' => 'SMTP Port', 'type' => 'int', 'default' => '587',
                    'show_when' => ['email.driver' => ['smtp']]],
                ['key' => 'email.smtp.username', 'label' => 'SMTP Username', 'type' => 'text', 'default' => '',
                    'show_when' => ['email.driver' => ['smtp']]],
                ['key' => 'email.smtp.password', 'label' => 'SMTP Password', 'type' => 'password', 'default' => '', 'secret' => true,
                    'show_when' => ['email.driver' => ['smtp']]],
                ['key' => 'email.smtp.encryption', 'label' => 'SMTP Encryption', 'type' => 'select', 'options' => ['tls' => 'TLS', 'ssl' => 'SSL', 'none' => 'None'], 'default' => 'tls',
                    'show_when' => ['email.driver' => ['smtp']]],

                // AWS SES
                ['key' => 'email.ses.region', 'label' => 'AWS SES Region', 'type' => 'text', 'default' => 'ap-southeast-1',
                    'show_when' => ['email.driver' => ['ses']]],
                ['key' => 'email.ses.access_key', 'label' => 'AWS Access Key', 'type' => 'password', 'default' => '', 'secret' => true,
                    'show_when' => ['email.driver' => ['ses']]],
                ['key' => 'email.ses.secret_key', 'label' => 'AWS Secret Key', 'type' => 'password', 'default' => '', 'secret' => true,
                    'show_when' => ['email.driver' => ['ses']]],

                // Mailgun
                ['key' => 'email.mailgun.domain', 'label' => 'Mailgun Domain', 'type' => 'text', 'default' => '',
                    'help' => 'mg.your-domain.com',
                    'show_when' => ['email.driver' => ['mailgun']]],
                ['key' => 'email.mailgun.secret', 'label' => 'Mailgun API Secret', 'type' => 'password', 'default' => '', 'secret' => true,
                    'show_when' => ['email.driver' => ['mailgun']]],

                // Postmark
                ['key' => 'email.postmark.token', 'label' => 'Postmark Server Token', 'type' => 'password', 'default' => '', 'secret' => true,
                    'show_when' => ['email.driver' => ['postmark']]],

                // Resend
                ['key' => 'email.resend.api_key', 'label' => 'Resend API Key', 'type' => 'password', 'default' => '', 'secret' => true,
                    'help' => 're_...',
                    'show_when' => ['email.driver' => ['resend']]],

                // SendGrid
                ['key' => 'email.sendgrid.api_key', 'label' => 'SendGrid API Key', 'type' => 'password', 'default' => '', 'secret' => true,
                    'help' => 'SG....',
                    'show_when' => ['email.driver' => ['sendgrid']]],
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

            'queue' => [
                ['key' => 'queue.driver', 'label' => 'Job Queue Driver', 'type' => 'select', 'options' => [
                    'sync'     => 'Sync — jalan langsung saat request (tanpa worker)',
                    'database' => 'Database — async via tabel jobs (butuh worker)',
                    'redis'    => 'Redis — async throughput tinggi (butuh Redis + worker)',
                ], 'default' => 'sync', 'help' => 'SYNC: tiap job (transcode, email, AI) dikerjakan langsung di dalam request — lambat untuk tugas berat tapi TANPA worker, cocok shared hosting. DATABASE/REDIS: job dikerjakan worker di background (wajib `php artisan queue:work` berjalan terus, mis. di VPS/VM). Ganti driver hanya berlaku untuk job BARU; worker yang sedang jalan perlu di-restart dengan `php artisan queue:restart`.'],
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
