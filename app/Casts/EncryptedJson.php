<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * EncryptedJson
 * --------------------------------------------------------------------------
 * Cast for JSON columns that contain PII (e.g. user_preferences.* fields
 * with sensitive answers, KYC payloads, demographic survey responses).
 *
 * Why a custom cast and not the built-in `encrypted:array`?
 *   - The built-in `encrypted:array` works, but it does not gracefully
 *     handle the migration window where some rows are still plaintext JSON
 *     (during the rollout of `php artisan flik:security:reencrypt-pii`).
 *     This cast tries decrypt first, falls back to a plaintext JSON parse,
 *     and finally returns null — never throws on read.
 *   - Writes always go through `Crypt::encryptString(json_encode(...))`,
 *     so once a row is touched it lands in the canonical encrypted form.
 *
 * Usage:
 *
 *   protected $casts = [
 *       'kyc_payload' => \App\Casts\EncryptedJson::class,
 *   ];
 *
 * Notes:
 *   - Keys are NOT searchable. If you need WHERE-by-key, denormalize the
 *     queryable bits into their own (hashed or plaintext) columns.
 *   - The cipher is AES-256-CBC keyed by APP_KEY (Laravel default).
 *     Rotation is handled via `flik:security:reencrypt-pii`.
 *
 * @implements CastsAttributes<array<string,mixed>|null, array<string,mixed>|null>
 */
class EncryptedJson implements CastsAttributes
{
    /**
     * Decrypt + json_decode. Tolerates plaintext rows during migration.
     *
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            return null;
        }

        // Happy path: decrypt then decode.
        try {
            $decrypted = Crypt::decryptString($value);
            $decoded = json_decode($decrypted, true);
            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable $e) {
            // Fallback 1: legacy plaintext JSON (pre-encryption rollout).
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                Log::warning('EncryptedJson: read plaintext JSON, will be re-encrypted on next write', [
                    'model' => $model::class,
                    'key'   => $key,
                    'id'    => $model->getKey(),
                ]);
                return $decoded;
            }

            // Fallback 2: garbage. Log and surface null so the caller can
            // decide what to do (most callers treat null as "no data").
            Log::warning('EncryptedJson: failed to decrypt and not valid JSON', [
                'model' => $model::class,
                'key'   => $key,
                'id'    => $model->getKey(),
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * json_encode + encrypt. Always writes the canonical encrypted form.
     *
     * @param  array<string,mixed>  $attributes
     * @return array<string,string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        if (! is_array($value)) {
            throw new \InvalidArgumentException(sprintf(
                'EncryptedJson cast for %s::%s requires an array value, got %s.',
                $model::class,
                $key,
                get_debug_type($value),
            ));
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \InvalidArgumentException(sprintf(
                'EncryptedJson cast for %s::%s could not json_encode the value: %s',
                $model::class,
                $key,
                json_last_error_msg(),
            ));
        }

        return [$key => Crypt::encryptString($json)];
    }
}
