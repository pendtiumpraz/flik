@php
    /**
     * Cloudflare Turnstile widget — drop-in CAPTCHA component.
     *
     * Renders nothing when TURNSTILE_SITE_KEY / TURNSTILE_SECRET_KEY are
     * unset (FLiK's standard env-gating pattern). When enabled, injects:
     *   - the Turnstile <div class="cf-turnstile"> widget anchor,
     *   - a single <script> tag (pushed to @stack('scripts') so it loads
     *     once even if multiple components are on the same page).
     *
     * Form integration: the widget auto-injects a hidden input named
     * `cf-turnstile-response` into the surrounding <form> so the
     * controller's `new CaptchaPassed` validation rule picks it up
     * with no extra wiring.
     *
     * Props:
     *   - $action  optional context label (login / register / comment)
     *              shown to Cloudflare for analytics splits.
     *   - $theme   "dark" | "light" | "auto". Defaults to "dark" to
     *              match FLiK's OTT-premium dark UI.
     */
    /** @var \App\Services\Security\TurnstileVerifier $turnstile */
    $turnstile = app(\App\Services\Security\TurnstileVerifier::class);

    $siteKey = (string) config('services.turnstile.site_key', '');
    $action = $action ?? null;
    $theme = $theme ?? 'dark';
@endphp

@if ($turnstile->enabled() && $siteKey !== '')
    {{-- The CF widget auto-injects `cf-turnstile-response` into the parent form. --}}
    <div class="mt-4 flex justify-center"
         data-turnstile-host="1">
        <div class="cf-turnstile"
             data-sitekey="{{ $siteKey }}"
             data-theme="{{ $theme }}"
             @if($action) data-action="{{ $action }}" @endif></div>
    </div>

    {{-- Surface a clear error when validation rejects the token. The field
         name (`cf-turnstile-response`) matches what CaptchaPassed validates. --}}
    @error('cf-turnstile-response')
        <p class="mt-2 text-xs text-center" style="color:#f87171">{{ $message }}</p>
    @enderror

    {{-- Loaded once per render. Async + defer so it never blocks the form.
         We try @push('scripts') first (works with <x-layout>) and fall back
         to an inline <script> for standalone pages (auth/* views don't use
         the shared layout). The duplicate-load is a no-op — Cloudflare's
         api.js short-circuits when window.turnstile is already defined. --}}
    @once
        @push('scripts')
            <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
        @endpush

        {{-- Auth/standalone fallback: emit inline so pages without an
             @stack('scripts') still get the loader. --}}
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endonce
@endif
