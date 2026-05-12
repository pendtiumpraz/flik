<?php

namespace App\Services\Drm;

use App\Models\DrmSession;

/**
 * Device fingerprinting helper.
 *
 * Produces a stable hash from a bundle of browser-side signals
 * (canvas, webgl, audio context, screen, timezone, languages) that
 * uniquely identifies a device/browser combination with high enough
 * entropy to detect session-token sharing across machines.
 *
 * The hash is opaque — we never persist the raw components, only the
 * SHA-256 digest in `drm_sessions.device_fingerprint`.
 */
class DeviceFingerprinter
{
    /**
     * Component keys we expect from the client. Order is irrelevant
     * because we sort + JSON-encode canonically before hashing.
     */
    public const EXPECTED_KEYS = [
        'canvas',
        'webgl',
        'audio',
        'screen',
        'timezone',
        'languages',
    ];

    /**
     * Hash a bundle of device components into a stable SHA-256 digest.
     *
     * Canonicalisation rules:
     *  - Unknown keys are dropped (defends against client-injected noise).
     *  - Missing keys are tolerated (recorded as empty string).
     *  - Keys are sorted alphabetically before encoding so two clients
     *    sending identical components in different order still match.
     *
     * @param  array<string, mixed>  $components
     */
    public function hash(array $components): string
    {
        $canonical = [];
        foreach (self::EXPECTED_KEYS as $key) {
            $value = $components[$key] ?? '';
            if (is_array($value)) {
                // Languages typically arrives as ["id-ID","en-US"]; sort for stability.
                sort($value);
                $value = implode(',', array_map('strval', $value));
            }
            $canonical[$key] = (string) $value;
        }
        ksort($canonical);

        $payload = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', (string) $payload);
    }

    /**
     * Validate a client-supplied fingerprint claim against the session record.
     *
     * Behaviour:
     *  - First-time use (no fingerprint stored on the session) is always allowed
     *    — the caller is expected to persist `$session->device_fingerprint`
     *    immediately afterwards so subsequent requests get strict comparison.
     *  - Subsequent requests must hash to the SAME digest as the stored value.
     *
     * @param  array<string, mixed>  $clientFingerprint  raw component bundle from browser
     */
    public function validateClaim(array $clientFingerprint, DrmSession $session): bool
    {
        $stored = $session->device_fingerprint;

        // First-time bind: trust-on-first-use (TOFU).
        if (empty($stored)) {
            return true;
        }

        $claim = $this->hash($clientFingerprint);

        return hash_equals((string) $stored, $claim);
    }

    /**
     * JS snippet the player loads in the browser to compute a fingerprint
     * bundle and POST it back to the key-issuance endpoint.
     *
     * The script is intentionally minimal and dependency-free. It exposes
     * `window.flikFingerprint()` returning a Promise<object> shaped like
     * the bundle expected by {@see hash()}.
     *
     * NOTE: deliberately NOT obfuscated — defence-in-depth, not security
     * by obscurity. Real anti-piracy guarantees come from server-side
     * key gating + commercial Widevine/PlayReady. This fingerprint exists
     * primarily to detect *casual* session-token sharing.
     */
    public function generateClientFingerprintScript(): string
    {
        return <<<'JS'
        (function () {
            function canvasHash() {
                try {
                    var c = document.createElement('canvas');
                    c.width = 240; c.height = 60;
                    var ctx = c.getContext('2d');
                    ctx.textBaseline = 'top';
                    ctx.font = "14px 'Arial'";
                    ctx.fillStyle = '#f60';
                    ctx.fillRect(125, 1, 62, 20);
                    ctx.fillStyle = '#069';
                    ctx.fillText('FLiK✨DRM', 2, 15);
                    ctx.fillStyle = 'rgba(102,204,0,0.7)';
                    ctx.fillText('FLiK✨DRM', 4, 17);
                    return c.toDataURL();
                } catch (e) { return ''; }
            }

            function webglHash() {
                try {
                    var c = document.createElement('canvas');
                    var gl = c.getContext('webgl') || c.getContext('experimental-webgl');
                    if (!gl) return '';
                    var dbg = gl.getExtension('WEBGL_debug_renderer_info');
                    var vendor = dbg ? gl.getParameter(dbg.UNMASKED_VENDOR_WEBGL) : '';
                    var renderer = dbg ? gl.getParameter(dbg.UNMASKED_RENDERER_WEBGL) : '';
                    return [vendor, renderer, gl.getParameter(gl.VERSION), gl.getParameter(gl.SHADING_LANGUAGE_VERSION)].join('|');
                } catch (e) { return ''; }
            }

            function audioHash() {
                try {
                    var AC = window.OfflineAudioContext || window.webkitOfflineAudioContext;
                    if (!AC) return '';
                    var ctx = new AC(1, 5000, 44100);
                    var osc = ctx.createOscillator();
                    osc.type = 'triangle';
                    osc.frequency.value = 1000;
                    var compressor = ctx.createDynamicsCompressor();
                    osc.connect(compressor);
                    compressor.connect(ctx.destination);
                    osc.start(0);
                    return ctx.startRendering ? 'ac:' + AC.name + ':' + ctx.sampleRate : '';
                } catch (e) { return ''; }
            }

            function screenSig() {
                try {
                    return [screen.width, screen.height, screen.colorDepth, window.devicePixelRatio || 1].join('x');
                } catch (e) { return ''; }
            }

            function tz() {
                try { return Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) { return ''; }
            }

            function langs() {
                try { return navigator.languages || [navigator.language || '']; } catch (e) { return []; }
            }

            window.flikFingerprint = function () {
                return Promise.resolve({
                    canvas:    canvasHash(),
                    webgl:     webglHash(),
                    audio:     audioHash(),
                    screen:    screenSig(),
                    timezone:  tz(),
                    languages: langs(),
                });
            };
        })();
        JS;
    }
}
