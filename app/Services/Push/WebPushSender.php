<?php

declare(strict_types=1);

namespace App\Services\Push;

use App\Models\PushMessage;
use App\Models\PushSubscription;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WebPushSender — minimal RFC 8030 / 8291 / 8292 implementation.
 *
 * Why we hand-roll the protocol instead of pulling in minishlink/web-push:
 *   - composer.json is intentionally lean (no Mozilla crypto deps).
 *   - The aes128gcm + VAPID JWT path is ~250 lines and well-specified.
 *
 * Trade-offs vs. the library:
 *   - Single content encoding (`aes128gcm` only — the modern RFC 8188
 *     variant). We do NOT speak the legacy `aesgcm` encoding, which means
 *     very old Chrome (<60) / Firefox (<55) browsers will fail to decrypt.
 *     That's a 2017 cutoff and not a real-world concern in 2026.
 *   - Single VAPID alg (`ES256` — the only one the spec accepts today).
 *   - No batching, no retries — the caller (BroadcastPushMessage job)
 *     handles retry semantics and the broadcaster prunes hard-failing rows.
 *
 * Graceful degradation: when VAPID env vars are absent every entry point
 * returns ['success' => false, 'reason' => 'not_configured'] so callers
 * never crash on a fresh install.
 */
class WebPushSender
{
    public function __construct(
        private readonly HttpFactory $http,
    ) {
    }

    /**
     * Is VAPID configured well enough to attempt deliveries?
     */
    public function enabled(): bool
    {
        return $this->publicKeyRaw() !== null && $this->privateKeyRaw() !== null;
    }

    /**
     * Send a single payload to one subscription.
     *
     * Returns ['status' => int, 'success' => bool, 'reason' => string|null].
     * The caller is responsible for marking the subscription as
     * delivered/failed (the broadcaster does this in bulk).
     *
     * @param  array<string, mixed>  $payload
     * @return array{status:int, success:bool, reason:?string}
     */
    public function send(PushSubscription $sub, array $payload): array
    {
        if (! $this->enabled()) {
            return ['status' => 0, 'success' => false, 'reason' => 'not_configured'];
        }

        try {
            $body = $this->encryptPayload($sub, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $headers = $this->buildHeaders($sub, strlen($body));

            $response = $this->httpClient()
                ->withHeaders($headers)
                ->withBody($body, 'application/octet-stream')
                ->post($sub->endpoint);

            $status = $response->status();
            $success = $status >= 200 && $status < 300;

            return [
                'status'  => $status,
                'success' => $success,
                'reason'  => $success ? null : 'http_' . $status,
            ];
        } catch (Throwable $e) {
            Log::warning('WebPushSender: send failed', [
                'subscription_id' => $sub->id,
                'endpoint'        => substr($sub->endpoint, 0, 80),
                'error'           => $e->getMessage(),
            ]);

            return ['status' => 0, 'success' => false, 'reason' => 'exception'];
        }
    }

    /**
     * Fan a message out to every subscription matching its audience.
     * Aggregates totals onto the message row and prunes subscriptions
     * the push service has revoked (HTTP 404/410).
     *
     * @return array{sent:int, success:int, failure:int}
     */
    public function sendToAll(PushMessage $message): array
    {
        $totals = ['sent' => 0, 'success' => 0, 'failure' => 0];

        if (! $this->enabled()) {
            Log::warning('WebPushSender: refusing to broadcast — VAPID not configured', [
                'message_id' => $message->id,
            ]);
            return $totals;
        }

        $payload = $message->toPayload();

        PushSubscription::query()
            ->forAudience($message->audience)
            ->healthy()
            ->chunkById(500, function ($subs) use ($payload, &$totals) {
                foreach ($subs as $sub) {
                    $result = $this->send($sub, $payload);
                    $totals['sent']++;

                    if ($result['success']) {
                        $totals['success']++;
                        $sub->markDelivered();
                        continue;
                    }

                    $totals['failure']++;

                    // 404 / 410 — push service has revoked this subscription.
                    // No point retrying ever; drop the row.
                    if (in_array($result['status'], [404, 410], true)) {
                        $sub->delete();
                        continue;
                    }

                    $sub->markFailed();
                }
            });

        $message->forceFill([
            'sent_at'       => now(),
            'sent_count'    => $totals['sent'],
            'success_count' => $totals['success'],
            'failure_count' => $totals['failure'],
        ])->save();

        return $totals;
    }

    // ────────────────────────────────────────────────────────────
    // Internal: HTTP client
    // ────────────────────────────────────────────────────────────

    private function httpClient(): PendingRequest
    {
        return $this->http->timeout(15)->connectTimeout(5);
    }

    // ────────────────────────────────────────────────────────────
    // Internal: header assembly (VAPID JWT + Crypto-Key + TTL)
    // ────────────────────────────────────────────────────────────

    /**
     * @return array<string, string>
     */
    private function buildHeaders(PushSubscription $sub, int $bodyLength): array
    {
        $audience = $this->audienceFor($sub->endpoint);
        $jwt = $this->buildVapidJwt($audience);
        $publicKeyB64 = self::base64UrlEncode($this->publicKeyRaw());

        return [
            'Content-Type'     => 'application/octet-stream',
            'Content-Encoding' => 'aes128gcm',
            'Content-Length'   => (string) $bodyLength,
            'TTL'              => '2419200', // 4 weeks (push service max)
            'Urgency'          => 'normal',
            // RFC 8292 §2 — single VAPID header replaces the legacy
            // Authorization + Crypto-Key pair used by aesgcm.
            'Authorization'    => sprintf('vapid t=%s, k=%s', $jwt, $publicKeyB64),
        ];
    }

    /**
     * Extract `https://host` from a push endpoint URL. The push service
     * compares this against the `aud` claim in the VAPID JWT.
     */
    private function audienceFor(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        if (! is_array($parts) || empty($parts['host'])) {
            return $endpoint;
        }

        $scheme = $parts['scheme'] ?? 'https';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return sprintf('%s://%s%s', $scheme, $parts['host'], $port);
    }

    /**
     * Build + sign a VAPID JWT. ES256 (ECDSA P-256 + SHA-256) is the only
     * algorithm the push services accept today.
     */
    private function buildVapidJwt(string $audience): string
    {
        $header = ['typ' => 'JWT', 'alg' => 'ES256'];
        $payload = [
            'aud' => $audience,
            'exp' => time() + 12 * 3600, // 12h — spec ceiling is 24h
            'sub' => (string) config('services.push.subject'),
        ];

        $headerB64 = self::base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES));
        $payloadB64 = self::base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signingInput = $headerB64 . '.' . $payloadB64;

        // Sign with the VAPID private key. We need the raw 32-byte D scalar
        // to import into openssl as a P-256 key.
        $privateKeyDer = $this->buildEcPrivateKeyPem();
        $signatureDer = '';

        if (! openssl_sign($signingInput, $signatureDer, $privateKeyDer, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('WebPushSender: openssl_sign failed for VAPID JWT');
        }

        // JOSE wants R||S concatenated (64 bytes for P-256), not DER.
        $signatureRaw = self::derToJoseSignature($signatureDer);

        return $signingInput . '.' . self::base64UrlEncode($signatureRaw);
    }

    // ────────────────────────────────────────────────────────────
    // Internal: aes128gcm payload encryption (RFC 8291)
    // ────────────────────────────────────────────────────────────

    /**
     * Encrypt a plaintext payload for delivery to one subscription using
     * the RFC 8291 (aes128gcm) Web Push encryption scheme.
     */
    private function encryptPayload(PushSubscription $sub, string $plaintext): string
    {
        $clientPublicKey = self::base64UrlDecode($sub->p256dh);
        $clientAuthSecret = self::base64UrlDecode($sub->auth_key);

        // ── 1. Generate an ephemeral ECDH keypair (server-side).
        [$serverPublicKey, $serverPrivateKey] = $this->generateEphemeralKeypair();

        // ── 2. ECDH shared secret (raw 32 bytes).
        $sharedSecret = $this->ecdh($serverPrivateKey, $clientPublicKey);

        // ── 3. Random 16-byte salt.
        $salt = random_bytes(16);

        // ── 4. PRK_key = HMAC-SHA256(authSecret, sharedSecret)
        // Per RFC 8291 §3.3 — uses the client's auth secret as the HKDF salt
        // to derive the input keying material.
        $keyInfo = "WebPush: info\x00" . $clientPublicKey . $serverPublicKey;
        $prkKey = self::hkdf($clientAuthSecret, $sharedSecret, $keyInfo, 32);

        // ── 5. Derive content-encryption key (CEK, 16 bytes) and nonce (12 bytes).
        $cek = self::hkdf($salt, $prkKey, "Content-Encoding: aes128gcm\x00", 16);
        $nonce = self::hkdf($salt, $prkKey, "Content-Encoding: nonce\x00", 12);

        // ── 6. Pad + delimiter (RFC 8188 §2.1).
        // Single record, no padding for simplicity. The trailing 0x02 marks
        // the LAST record (0x01 would mark a non-last record).
        $padded = $plaintext . "\x02";

        // ── 7. AES-128-GCM encrypt.
        $tag = '';
        $ciphertext = openssl_encrypt(
            $padded,
            'aes-128-gcm',
            $cek,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '', // no AAD
            16,
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('WebPushSender: openssl_encrypt failed');
        }

        // ── 8. Assemble RFC 8188 header block + body.
        // Header layout:
        //   salt (16) | record-size (4, BE uint32) | idlen (1) | keyid (idlen)
        // For aes128gcm Web Push the keyid IS the server ECDH public key.
        $recordSize = 4096; // browsers must support ≥4096
        $keyId = $serverPublicKey;
        $header = $salt
            . pack('N', $recordSize)
            . chr(strlen($keyId))
            . $keyId;

        return $header . $ciphertext . $tag;
    }

    // ────────────────────────────────────────────────────────────
    // Internal: ECC helpers
    // ────────────────────────────────────────────────────────────

    /**
     * Generate a P-256 ECDH keypair, returning [publicKeyUncompressed, privateKeyPem].
     *
     * @return array{0:string, 1:string}
     */
    private function generateEphemeralKeypair(): array
    {
        $opts = [
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ];

        // Windows + bare PHP install fallback (see GenerateVapidKeys for
        // context). No-op on Linux/macOS where the distro openssl.cnf is
        // already in scope.
        if (PHP_OS_FAMILY === 'Windows' && getenv('OPENSSL_CONF') === false) {
            $tmpConf = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'flik_openssl_minimal.cnf';
            if (! file_exists($tmpConf)) {
                @file_put_contents($tmpConf, "[req]\ndistinguished_name = req_dn\n[req_dn]\n");
            }
            $opts['config'] = $tmpConf;
        }

        $res = @openssl_pkey_new($opts);

        if ($res === false) {
            throw new \RuntimeException('WebPushSender: openssl_pkey_new failed (ext-openssl / openssl.cnf?)');
        }

        $details = openssl_pkey_get_details($res);
        if (! is_array($details) || ! isset($details['ec']['x'], $details['ec']['y'])) {
            throw new \RuntimeException('WebPushSender: failed to extract EC details');
        }

        // Uncompressed point: 0x04 || X(32) || Y(32) = 65 bytes
        $publicKey = "\x04" . self::padLeft($details['ec']['x'], 32) . self::padLeft($details['ec']['y'], 32);

        $privateKeyPem = '';
        openssl_pkey_export($res, $privateKeyPem);

        return [$publicKey, $privateKeyPem];
    }

    /**
     * Compute the raw 32-byte ECDH shared secret between our ephemeral
     * private key and the subscription's public key.
     */
    private function ecdh(string $serverPrivateKeyPem, string $clientPublicKeyRaw): string
    {
        // openssl_dh_compute_key works for ECDH on PHP 8.1+ when given EC keys.
        $serverKey = openssl_pkey_get_private($serverPrivateKeyPem);
        if ($serverKey === false) {
            throw new \RuntimeException('WebPushSender: failed to import server EC key');
        }

        $shared = openssl_pkey_derive(
            $this->buildPeerPublicKeyPem($clientPublicKeyRaw),
            $serverKey,
        );

        if ($shared === false || $shared === '') {
            throw new \RuntimeException('WebPushSender: openssl_pkey_derive failed (ECDH)');
        }

        return $shared;
    }

    /**
     * Wrap a 65-byte uncompressed P-256 public key in the PEM structure
     * openssl_pkey_derive expects.
     */
    private function buildPeerPublicKeyPem(string $publicKeyRaw): string
    {
        // SubjectPublicKeyInfo DER for an ECDSA P-256 public key:
        //   SEQUENCE {
        //     SEQUENCE { OID ecPublicKey, OID prime256v1 }
        //     BIT STRING { 0x00, uncompressed point }
        //   }
        $oidsHeader = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200');
        $der = $oidsHeader . $publicKeyRaw;
        $b64 = chunk_split(base64_encode($der), 64, "\n");

        return "-----BEGIN PUBLIC KEY-----\n{$b64}-----END PUBLIC KEY-----\n";
    }

    /**
     * Assemble a PEM-encoded EC private key from the VAPID raw 32-byte
     * private scalar + the matching public point. Cached per request so
     * we don't reparse on every send.
     */
    private function buildEcPrivateKeyPem(): string
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $privateScalar = $this->privateKeyRaw();
        $publicPoint   = $this->publicKeyRaw();

        if ($privateScalar === null || $publicPoint === null) {
            throw new \RuntimeException('WebPushSender: VAPID keys missing');
        }

        // RFC 5915 ECPrivateKey DER:
        //   SEQUENCE {
        //     INTEGER 1
        //     OCTET STRING (32-byte scalar)
        //     [0] OID prime256v1
        //     [1] BIT STRING (uncompressed public point)
        //   }
        $version = "\x02\x01\x01";
        $privKeyOctet = "\x04\x20" . self::padLeft($privateScalar, 32);
        $oidParam = "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
        $pubKeyBitString = "\xa1\x44\x03\x42\x00" . $publicPoint;

        $body = $version . $privKeyOctet . $oidParam . $pubKeyBitString;
        $der = "\x30" . self::derLength(strlen($body)) . $body;

        $b64 = chunk_split(base64_encode($der), 64, "\n");
        $cache = "-----BEGIN EC PRIVATE KEY-----\n{$b64}-----END EC PRIVATE KEY-----\n";

        return $cache;
    }

    private function publicKeyRaw(): ?string
    {
        $b64 = (string) config('services.push.public_key');
        if ($b64 === '') {
            return null;
        }
        $raw = self::base64UrlDecode($b64);
        return ($raw !== '' && strlen($raw) === 65) ? $raw : null;
    }

    private function privateKeyRaw(): ?string
    {
        $b64 = (string) config('services.push.private_key');
        if ($b64 === '') {
            return null;
        }
        $raw = self::base64UrlDecode($b64);
        return ($raw !== '' && strlen($raw) === 32) ? $raw : null;
    }

    // ────────────────────────────────────────────────────────────
    // Internal: low-level codecs + KDFs
    // ────────────────────────────────────────────────────────────

    /**
     * HKDF (RFC 5869) over SHA-256. PHP 7.1+ has hash_hkdf() native, but
     * we route through it via a thin wrapper so the order of arguments
     * mirrors the RFC and is easier to audit.
     */
    private static function hkdf(string $salt, string $ikm, string $info, int $length): string
    {
        return hash_hkdf('sha256', $ikm, $length, $info, $salt);
    }

    /**
     * Convert an ECDSA signature from DER (openssl_sign output) to the
     * fixed-width JOSE concatenation (R || S, 64 bytes for P-256) the
     * spec requires.
     */
    public static function derToJoseSignature(string $der): string
    {
        // SEQUENCE { INTEGER R, INTEGER S }
        // Quick-and-correct parser — we only handle the well-formed output
        // of openssl_sign so we don't need a full DER decoder.
        $offset = 0;
        if (($der[$offset] ?? '') !== "\x30") {
            throw new \RuntimeException('derToJoseSignature: missing SEQUENCE tag');
        }
        $offset++;

        // Length (could be short or long form)
        $lenByte = ord($der[$offset++]);
        if ($lenByte & 0x80) {
            $offset += ($lenByte & 0x7f);
        }

        $readInt = static function (string $der, int &$offset): string {
            if (($der[$offset] ?? '') !== "\x02") {
                throw new \RuntimeException('derToJoseSignature: missing INTEGER tag');
            }
            $offset++;
            $len = ord($der[$offset++]);
            $val = substr($der, $offset, $len);
            $offset += $len;
            // Strip leading 0x00 padding inserted to keep the integer positive.
            return ltrim($val, "\x00");
        };

        $r = self::padLeft($readInt($der, $offset), 32);
        $s = self::padLeft($readInt($der, $offset), 32);

        return $r . $s;
    }

    /** Left-pad a binary string to `$length` bytes with NUL bytes. */
    public static function padLeft(string $value, int $length): string
    {
        return str_pad($value, $length, "\x00", STR_PAD_LEFT);
    }

    /**
     * Encode a DER length field (short form < 128, long form otherwise).
     */
    private static function derLength(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }
        $bytes = '';
        $n = $length;
        while ($n > 0) {
            $bytes = chr($n & 0xff) . $bytes;
            $n >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $data): string
    {
        $padded = strtr($data, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        $decoded = base64_decode($padded, true);

        return $decoded === false ? '' : $decoded;
    }
}
