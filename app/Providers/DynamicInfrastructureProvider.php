<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/**
 * DynamicInfrastructureProvider
 *
 * Maps rows in the `settings` table (group: drm/cdn/storage/payment/email/
 * realtime/integrations) onto live Laravel config() paths during boot.
 *
 * Net effect: when an admin saves `payment.midtrans.server_key` at
 * /admin/infrastructure, the next HTTP request sees that value via
 * `config('services.midtrans.server_key')` WITHOUT any service code
 * change + WITHOUT editing .env + WITHOUT restart.
 *
 * Strategy: single static MAP table = setting-key → config-path. Boot
 * iterates the map, queries the matching settings in one round-trip,
 * calls config()->set() for each.
 *
 * Performance: full settings query cached 5 minutes. Cache busts on
 * any Setting::save()/delete() via the model boot hook.
 *
 * Safety:
 *   - Wrapped in try/catch — never blocks app boot.
 *   - Honours empty/null — does NOT overwrite the .env default when the
 *     DB row is blank. (Operators rotating keys want to be able to delete
 *     a DB-set credential and fall back to .env defaults.)
 *   - Skipped entirely if `settings` table absent (fresh install).
 */
class DynamicInfrastructureProvider extends ServiceProvider
{
    /**
     * The single source of truth for Setting-key → config-path bindings.
     * Add to this when introducing a new dynamic knob in InfrastructureController.
     *
     * @var array<string, string>
     */
    protected const MAP = [
        // ─── DRM ───────────────────────────────────────────────
        'drm.provider'                    => 'drm.provider',
        'drm.license_server_url'          => 'drm.license_server_url',
        'drm.license_token_secret'        => 'drm.license_token_secret',
        'drm.allow_episode_raw_mp4'       => 'drm.allow_episode_raw_mp4',
        'drm.concurrent_lock_ttl'         => 'drm.concurrent_lock_ttl',
        'drm.jwt_lifetime_minutes'        => 'drm.jwt_lifetime_minutes',
        'drm.forensic_watermark_enabled'  => 'drm.forensic_watermark_enabled',

        // ─── CDN ───────────────────────────────────────────────
        'cdn.driver'                      => 'services.cdn.driver',
        'cdn.bunny.storage_zone'          => 'services.bunny.storage_zone',
        'cdn.bunny.access_key'            => 'services.bunny.storage_key',
        'cdn.bunny.pull_zone_url'         => 'services.bunny.pull_zone_url',
        'cdn.bunny.token_key'             => 'services.bunny.token_key',
        'cdn.s3.region'                   => 'filesystems.disks.s3.region',
        'cdn.s3.bucket'                   => 'filesystems.disks.s3.bucket',
        'cdn.s3.access_key'               => 'filesystems.disks.s3.key',
        'cdn.s3.secret_key'               => 'filesystems.disks.s3.secret',
        'cdn.s3.cloudfront_domain'        => 'services.cloudfront.domain',
        'cdn.r2.account_id'               => 'services.r2.account_id',
        'cdn.r2.access_key'               => 'services.r2.access_key',
        'cdn.r2.secret_key'               => 'services.r2.secret_key',
        'cdn.r2.bucket'                   => 'services.r2.bucket',
        'cdn.spaces.region'               => 'services.spaces.region',
        'cdn.spaces.bucket'               => 'services.spaces.bucket',
        'cdn.spaces.access_key'           => 'services.spaces.access_key',
        'cdn.spaces.secret_key'           => 'services.spaces.secret_key',
        'cdn.signed_url_ttl_minutes'      => 'services.cdn.signed_url_ttl_minutes',

        // ─── Storage ───────────────────────────────────────────
        'storage.master_disk'             => 'filesystems.master_disk',
        'storage.master_keep_after_encode' => 'filesystems.master_keep_after_encode',
        'storage.image_disk'              => 'filesystems.image_disk',
        'storage.subtitle_disk'           => 'filesystems.subtitle_disk',

        // ─── Realtime ──────────────────────────────────────────
        'realtime.driver'                 => 'broadcasting.default',
        'realtime.pusher.app_id'          => 'broadcasting.connections.pusher.app_id',
        'realtime.pusher.app_key'         => 'broadcasting.connections.pusher.key',
        'realtime.pusher.app_secret'      => 'broadcasting.connections.pusher.secret',
        'realtime.pusher.cluster'         => 'broadcasting.connections.pusher.options.cluster',
        'realtime.reverb.host'            => 'broadcasting.connections.reverb.options.host',
        'realtime.reverb.port'            => 'broadcasting.connections.reverb.options.port',
        'realtime.reverb.scheme'          => 'broadcasting.connections.reverb.options.scheme',
        'realtime.ably.api_key'           => 'broadcasting.connections.ably.key',
        'realtime.polling_interval_seconds' => 'broadcasting.polling_interval_seconds',

        // ─── Payment ───────────────────────────────────────────
        'payment.provider'                => 'services.payment.provider',
        'payment.midtrans.is_production'  => 'services.midtrans.is_production',
        'payment.midtrans.server_key'     => 'services.midtrans.server_key',
        'payment.midtrans.client_key'     => 'services.midtrans.client_key',
        'payment.midtrans.merchant_id'    => 'services.midtrans.merchant_id',
        'payment.xendit.secret_key'       => 'services.xendit.secret_key',
        'payment.xendit.webhook_token'    => 'services.xendit.webhook_token',
        'payment.xendit.callback_url'     => 'services.xendit.callback_url',
        'payment.doku.client_id'          => 'services.doku.client_id',
        'payment.doku.secret_key'         => 'services.doku.secret_key',
        'payment.stripe.publishable_key'  => 'services.stripe.publishable_key',
        'payment.stripe.secret_key'       => 'services.stripe.secret_key',
        'payment.stripe.webhook_secret'   => 'services.stripe.webhook_secret',

        // ─── Email ─────────────────────────────────────────────
        'email.driver'                    => 'mail.default',
        'email.from_address'              => 'mail.from.address',
        'email.from_name'                 => 'mail.from.name',
        'email.smtp.host'                 => 'mail.mailers.smtp.host',
        'email.smtp.port'                 => 'mail.mailers.smtp.port',
        'email.smtp.username'             => 'mail.mailers.smtp.username',
        'email.smtp.password'             => 'mail.mailers.smtp.password',
        'email.smtp.encryption'           => 'mail.mailers.smtp.encryption',
        'email.ses.region'                => 'services.ses.region',
        'email.ses.access_key'            => 'services.ses.key',
        'email.ses.secret_key'            => 'services.ses.secret',
        'email.mailgun.domain'            => 'services.mailgun.domain',
        'email.mailgun.secret'            => 'services.mailgun.secret',
        'email.postmark.token'            => 'services.postmark.token',
        'email.resend.api_key'            => 'services.resend.key',
        'email.sendgrid.api_key'          => 'services.sendgrid.api_key',

        // ─── Integrations ──────────────────────────────────────
        'integrations.tmdb_api_key'       => 'services.tmdb.api_key',
        'integrations.google_client_id'   => 'services.google.client_id',
        'integrations.google_client_secret' => 'services.google.client_secret',
        'integrations.mailchimp_api_key'  => 'services.mailchimp.api_key',
        'integrations.turnstile_site_key' => 'services.turnstile.site_key',
        'integrations.turnstile_secret_key' => 'services.turnstile.secret_key',
        'integrations.maxmind_account_id' => 'services.maxmind.account_id',
        'integrations.maxmind_license_key' => 'services.maxmind.license_key',

        // ─── Queue ─────────────────────────────────────────────
        // Overrides the default queue connection (sync/database/redis). Note:
        // a running `queue:work` worker reads this at boot — flipping it here
        // only affects newly dispatched jobs until workers are restarted.
        'queue.driver'                    => 'queue.default',
    ];

    public function register(): void
    {
        // Nothing — all work in boot() after Schema facade is available.
    }

    public function boot(): void
    {
        try {
            if (! Schema::hasTable('settings')) {
                return;
            }

            $settings = Cache::remember(
                'flik.dynamic_infrastructure.values',
                300, // 5 minutes
                fn () => Setting::query()
                    ->whereIn('key', array_keys(self::MAP))
                    ->pluck('value', 'key')
                    ->all()
            );

            foreach (self::MAP as $settingKey => $configPath) {
                if (! array_key_exists($settingKey, $settings)) {
                    continue; // not stored → keep .env default
                }
                $value = $settings[$settingKey];
                if ($value === null || $value === '') {
                    continue; // explicitly empty → keep .env default
                }

                // Cast where appropriate
                $value = $this->castValue($settingKey, (string) $value);

                config()->set($configPath, $value);
            }

            // Invalidate cache on any Setting save/delete so admins see
            // changes on the very next request.
            $this->wireCacheBust();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('DynamicInfrastructureProvider: boot failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Type-cast known boolean / int paths so config consumers get the
     * expected PHP type. Defaults to string (config()->set accepts any).
     */
    protected function castValue(string $settingKey, string $raw): mixed
    {
        $boolKeys = [
            'drm.allow_episode_raw_mp4',
            'drm.forensic_watermark_enabled',
            'storage.master_keep_after_encode',
            'payment.midtrans.is_production',
        ];
        if (in_array($settingKey, $boolKeys, true)) {
            return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
        }

        $intKeys = [
            'drm.concurrent_lock_ttl',
            'drm.jwt_lifetime_minutes',
            'cdn.signed_url_ttl_minutes',
            'realtime.pusher.app_id',
            'realtime.reverb.port',
            'realtime.polling_interval_seconds',
            'email.smtp.port',
        ];
        if (in_array($settingKey, $intKeys, true)) {
            return (int) $raw;
        }

        return $raw;
    }

    /**
     * Hook Setting model boot to bust our cache on any write. Keeps the
     * "save in admin → effective next request" UX guarantee tight.
     */
    protected function wireCacheBust(): void
    {
        if (! class_exists(Setting::class)) {
            return;
        }
        Setting::saved(static function ($model) {
            Cache::forget('flik.dynamic_infrastructure.values');
        });
        Setting::deleted(static function ($model) {
            Cache::forget('flik.dynamic_infrastructure.values');
        });
    }
}
