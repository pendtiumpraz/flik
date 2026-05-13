<?php

declare(strict_types=1);

namespace App\Rules;

use App\Services\Security\TurnstileVerifier;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validation rule that requires a successful Cloudflare Turnstile challenge.
 *
 * Usage (Laravel 12 ValidationRule):
 *   $request->validate([
 *       'cf-turnstile-response' => ['required', new CaptchaPassed()],
 *   ]);
 *
 * Behaviour (FLiK env-gating pattern):
 *   - When {@see TurnstileVerifier::enabled()} is false (keys not set),
 *     the rule passes unconditionally and produces no errors. This keeps
 *     dev environments and CI green without Cloudflare keys.
 *   - When enabled, the rule pulls the request's IP via the Laravel
 *     container so we don't have to thread it through every caller.
 *   - The error message is bilingual (Indonesian + English) to match the
 *     style established by {@see StrongPassword} and {@see NotBreached}.
 *
 * Conventionally attach this rule to the special `cf-turnstile-response`
 * field — that's the name Cloudflare's widget injects into the form. If
 * the field is missing or empty, the rule rejects (the verifier short-
 * circuits empty tokens before round-tripping to Cloudflare).
 */
final class CaptchaPassed implements ValidationRule
{
    public function __construct(
        // Resolved lazily so tests can rebind the verifier in the container.
        private ?TurnstileVerifier $verifier = null,
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $verifier = $this->verifier ?? app(TurnstileVerifier::class);

        // Graceful no-op when keys are missing (FLiK standard pattern).
        if (! $verifier->enabled()) {
            return;
        }

        $token = is_string($value) ? $value : '';
        $remoteIp = request()->ip();

        if (! $verifier->verify($token, $remoteIp)) {
            $fail('Verifikasi anti-bot gagal. Silakan coba lagi. / CAPTCHA verification failed. Please try again.');
        }
    }
}
