<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Push\WebPushSender;
use Illuminate\Console\Command;

/**
 * flik:push:generate-vapid-keys
 * --------------------------------------------------------------------------
 * Mints a fresh ECDSA P-256 keypair for Web Push (VAPID — RFC 8292) using
 * only ext-openssl, so we have ZERO node/web-push dependencies.
 *
 * Output format matches the `npx web-push generate-vapid-keys` CLI so the
 * keys are interchangeable with the JS tooling.
 */
class GenerateVapidKeys extends Command
{
    protected $signature = 'flik:push:generate-vapid-keys';

    protected $description = 'Generate a fresh VAPID (P-256) keypair for Web Push notifications.';

    public function handle(): int
    {
        $opts = [
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ];

        // On Windows + bare PHP installs, openssl_pkey_new() refuses to
        // run without an openssl.cnf in scope. We auto-detect a missing
        // config and fall back to a minimal one so the command "just works"
        // for local dev on every platform. Linux containers ship with the
        // distro openssl.cnf so this branch is a Windows-only helper.
        if (PHP_OS_FAMILY === 'Windows' && getenv('OPENSSL_CONF') === false) {
            $tmpConf = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'flik_openssl_minimal.cnf';
            if (! file_exists($tmpConf)) {
                file_put_contents($tmpConf, "[req]\ndistinguished_name = req_dn\n[req_dn]\n");
            }
            $opts['config'] = $tmpConf;
        }

        $res = @openssl_pkey_new($opts);

        if ($res === false) {
            $errors = [];
            while ($e = openssl_error_string()) {
                $errors[] = $e;
            }
            $this->error('openssl_pkey_new() failed — is ext-openssl installed and configured?');
            if ($errors !== []) {
                $this->line('OpenSSL errors:');
                foreach ($errors as $e) {
                    $this->line('  - ' . $e);
                }
            }
            $this->line('');
            $this->line('On Windows, set OPENSSL_CONF to the path of your openssl.cnf,');
            $this->line('OR generate keys via Node instead: `npx web-push generate-vapid-keys`');
            return self::FAILURE;
        }

        $details = openssl_pkey_get_details($res);
        if (! is_array($details) || ! isset($details['ec']['x'], $details['ec']['y'], $details['ec']['d'])) {
            $this->error('Failed to extract EC details from the generated key.');
            return self::FAILURE;
        }

        // Uncompressed point: 0x04 || X || Y (65 bytes)
        $publicRaw = "\x04"
            . WebPushSender::padLeft($details['ec']['x'], 32)
            . WebPushSender::padLeft($details['ec']['y'], 32);

        // Private scalar is the raw 32-byte D value.
        $privateRaw = WebPushSender::padLeft($details['ec']['d'], 32);

        $publicB64 = WebPushSender::base64UrlEncode($publicRaw);
        $privateB64 = WebPushSender::base64UrlEncode($privateRaw);

        $this->line('');
        $this->info('=========================================================');
        $this->info('  VAPID keypair generated — copy into your .env file:');
        $this->info('=========================================================');
        $this->line('');
        $this->line("VAPID_PUBLIC_KEY={$publicB64}");
        $this->line("VAPID_PRIVATE_KEY={$privateB64}");
        $this->line('VAPID_SUBJECT="mailto:admin@flik.example.com"   # change me!');
        $this->line('');
        $this->warn('Keep the PRIVATE key secret — it signs every push you broadcast.');
        $this->warn('Rotating the keypair invalidates every existing browser subscription.');
        $this->line('');
        $this->line('After updating .env, restart php-fpm / artisan serve / your queue worker.');
        $this->line('');

        return self::SUCCESS;
    }
}
