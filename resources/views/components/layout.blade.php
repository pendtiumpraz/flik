@props([
    'hideHeader' => false,
    'title' => 'FLiK — Rumah Sinema Indonesia',
    'description' => null,
    'ogImage' => null,
])

@php
    $metaDescription = $description
        ?? 'FLiK — Rumah Sinema Indonesia. Platform streaming film premium dengan koleksi terlengkap film Indonesia dan internasional.';
    $metaOgImage = $ogImage ?: asset('img/flik-logo.png');
    if ($metaOgImage && !\Illuminate\Support\Str::startsWith($metaOgImage, ['http://', 'https://'])) {
        $metaOgImage = url($metaOgImage);
    }
    $metaUrl = url()->current();

    // i18n — pull the current locale + its metadata from config/locales.php
    // so RTL languages render the page with dir="rtl". The CSS shim below
    // hands off the rest (logical properties + a few legacy margin flips).
    $appLocale = app()->getLocale();
    $localeMeta = (array) config('locales.available.' . $appLocale, []);
    $isRtl = (bool) ($localeMeta['rtl'] ?? false);
@endphp

<!DOCTYPE html>
<html lang="{{ $appLocale }}"
      dir="{{ $isRtl ? 'rtl' : 'ltr' }}"
      x-data="{ darkMode: localStorage.getItem('theme') !== 'light' }"
      :class="darkMode ? 'dark' : 'light'"
      x-init="$watch('darkMode', val => localStorage.setItem('theme', val ? 'dark' : 'light'))">
<head>
    <meta charset="UTF-8">
    {{-- viewport-fit=cover (FIX #10, audit 19 M-1): required for iOS standalone
         PWA to honour env(safe-area-inset-*) on iPhone X-class notched / dynamic
         island devices. Without this, the bottom-nav strip's safe-area padding
         is silently ignored and the home-indicator can obscure the active tab. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="{{ $metaDescription }}">
    <meta name="theme-color" content="#0a0a0a" media="(prefers-color-scheme: dark)">
    <meta name="theme-color" content="#0a0a0a" media="(prefers-color-scheme: light)">
    <meta name="msapplication-TileColor" content="#C5A55A">
    @auth
    <meta name="user-name" content="{{ auth()->user()->name }}">
    @endauth

    {{-- iOS PWA / "Add to Home Screen" support — these are Apple-proprietary
         tags (the W3C manifest is ignored by Safari for many flags). Without
         apple-mobile-web-app-capable Safari won't treat the saved icon as a
         standalone app; without the splash <link>s the launch flashes a blank
         white screen. See public/icons/ + the GenerateSplashScreens artisan
         command for asset generation. --}}
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="FLiK">
    <meta name="format-detection" content="telephone=no">

    {{-- iOS splash screens (apple-touch-startup-image) — one entry per common
         viewport. Media queries follow Apple's documented matrix; missing
         files degrade gracefully to a blank dark launch screen. Generate via
         `php artisan flik:pwa:generate-splash`. --}}
    <link rel="apple-touch-startup-image" href="/icons/splash-iphone-se.png"
          media="(device-width: 375px) and (device-height: 667px) and (-webkit-device-pixel-ratio: 2)">
    <link rel="apple-touch-startup-image" href="/icons/splash-iphone-14.png"
          media="(device-width: 390px) and (device-height: 844px) and (-webkit-device-pixel-ratio: 3)">
    <link rel="apple-touch-startup-image" href="/icons/splash-iphone-14-pro.png"
          media="(device-width: 393px) and (device-height: 852px) and (-webkit-device-pixel-ratio: 3)">
    <link rel="apple-touch-startup-image" href="/icons/splash-iphone-14-pro-max.png"
          media="(device-width: 430px) and (device-height: 932px) and (-webkit-device-pixel-ratio: 3)">
    <link rel="apple-touch-startup-image" href="/icons/splash-ipad-mini.png"
          media="(device-width: 744px) and (device-height: 1133px) and (-webkit-device-pixel-ratio: 2)">
    <link rel="apple-touch-startup-image" href="/icons/splash-ipad-pro-11.png"
          media="(device-width: 834px) and (device-height: 1194px) and (-webkit-device-pixel-ratio: 2)">
    <link rel="apple-touch-startup-image" href="/icons/splash-ipad-pro-12.png"
          media="(device-width: 1024px) and (device-height: 1366px) and (-webkit-device-pixel-ratio: 2)">

    <!-- Open Graph -->
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $metaDescription }}">
    <meta property="og:image" content="{{ $metaOgImage }}">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ $metaUrl }}">
    <meta property="og:site_name" content="FLiK">

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $title }}">
    <meta name="twitter:description" content="{{ $metaDescription }}">
    <meta name="twitter:image" content="{{ $metaOgImage }}">

    <!-- hreflang — one entry per available locale + x-default → site root.
         Pre-rendered from config('locales.available') so adding a locale
         automatically lights up its hreflang line without view edits. -->
    @foreach((array) config('locales.available', []) as $hlCode => $hlMeta)
        <link rel="alternate" hreflang="{{ $hlCode }}" href="{{ $metaUrl }}{{ str_contains($metaUrl, '?') ? '&' : '?' }}lang={{ $hlCode }}">
    @endforeach
    <link rel="alternate" hreflang="x-default" href="{{ $metaUrl }}">

    <title>{{ $title }}</title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="/icons/apple-touch-icon.png">
    <link rel="manifest" href="/manifest.json">

    {{-- VAPID public key for Web Push (read by resources/js/push-notifications.js).
         Emitted only when VAPID_PUBLIC_KEY is set; absent meta → graceful no-op. --}}
    @if (config('services.push.public_key'))
        <meta name="vapid-public-key" content="{{ config('services.push.public_key') }}">
    @endif
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        h1, h2, h3, h4, h5, h6, .font-heading { font-family: 'Outfit', sans-serif; }
        .brand-gold { color: #C5A55A; }

        /* ─── RTL shim (Arabic, future Hebrew/Persian) ──────────────────
         * Prefer Tailwind logical properties (me-*, ms-*, ps-*, pe-*) in
         * new views; this block only patches a handful of LEGACY views
         * that still ship physical-side classes. When dir="rtl" is set on
         * <html>, ml-* swaps to right-margin and mr-* to left-margin so
         * existing layouts mirror without being rewritten.
         *
         * Inline styles (`style="margin-left:..."`) are NOT covered — those
         * have to be migrated case-by-case (see docs/i18n-todo.md).
         */
        [dir="rtl"] .ml-1 { margin-left: 0; margin-right: 0.25rem; }
        [dir="rtl"] .ml-2 { margin-left: 0; margin-right: 0.5rem; }
        [dir="rtl"] .ml-3 { margin-left: 0; margin-right: 0.75rem; }
        [dir="rtl"] .ml-4 { margin-left: 0; margin-right: 1rem; }
        [dir="rtl"] .ml-6 { margin-left: 0; margin-right: 1.5rem; }
        [dir="rtl"] .ml-10 { margin-left: 0; margin-right: 2.5rem; }
        [dir="rtl"] .mr-1 { margin-right: 0; margin-left: 0.25rem; }
        [dir="rtl"] .mr-2 { margin-right: 0; margin-left: 0.5rem; }
        [dir="rtl"] .mr-3 { margin-right: 0; margin-left: 0.75rem; }
        [dir="rtl"] .mr-4 { margin-right: 0; margin-left: 1rem; }
        [dir="rtl"] .pl-3 { padding-left: 0; padding-right: 0.75rem; }
        [dir="rtl"] .pl-4 { padding-left: 0; padding-right: 1rem; }
        [dir="rtl"] .pr-3 { padding-right: 0; padding-left: 0.75rem; }
        [dir="rtl"] .pr-4 { padding-right: 0; padding-left: 1rem; }
        [dir="rtl"] .text-left { text-align: right; }
        [dir="rtl"] .text-right { text-align: left; }
        [dir="rtl"] .left-0 { left: auto; right: 0; }
        [dir="rtl"] .right-0 { right: auto; left: 0; }
        /* Flickity carousels — flip the slider direction so swipes
         * follow reading order. Re-init may be required if a slider
         * is mounted before dir attribute is applied (it isn't here). */
        [dir="rtl"] .flickity-prev-next-button.previous { left: auto; right: 10px; }
        [dir="rtl"] .flickity-prev-next-button.next { right: auto; left: 10px; }
    </style>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="stylesheet" href="https://unpkg.com/flickity@2/dist/flickity.min.css">
    <script src="https://unpkg.com/flickity@2/dist/flickity.pkgd.min.js"></script>

    {{-- Per-page <head> injections — pushed by views via @push('head').
         Notably used by <x-movie-seo> on movie detail to emit AI-generated
         SEO/OG/Twitter meta tags above the default site-wide ones. Putting
         this LAST means per-page tags win over the generic defaults in the
         browser's <meta> dedupe order. --}}
    @stack('head')
</head>
<style>
    [x-cloak] { display: none !important; }
</style>
<body class="pb-16 lg:pb-0 bg-[#0a0a0a] text-white min-h-screen">

    @unless($hideHeader)
        @auth
            <x-header />
        @endauth
    @endunless

    {{ $slot }}

    <x-flash />

    {{-- Global AI chatbot widget — available on every authed page (was previously
         only mounted on home). z-[200] keeps it above mobile-nav (z-40) + modals. --}}
    @auth
        <x-home.chatbot-widget />
    @endauth

    {{-- Mobile bottom tab bar — only when authed. Hidden on lg+ via Tailwind
         lg:hidden on the component itself; the body has pb-16 lg:pb-0 above
         to ensure page content isn't masked by the fixed nav on mobile. --}}
    @auth
        <x-mobile-nav />
    @endauth

    {{-- PWA install banner. JS in resources/js/pwa-install.js manages throttling
         + native prompt; this Blade component is purely the UI surface. Listens
         for `flik:show-install-prompt` (Chromium native + iOS fallback). --}}
    <x-pwa-install-banner />


    <script>
        // ━━━ Global image fallback handler ━━━
        // Replaces ANY broken image with an inline SVG placeholder (no network request).
        // Stops infinite retry loops from third-party placeholder services going down.
        (function () {
            function applyFallback(img) {
                if (img.dataset.fbApplied) return;
                img.dataset.fbApplied = '1';
                // Strip onerror handler to prevent further trigger from inline handlers
                img.onerror = null;
                var title = (img.alt || img.dataset.title || 'No Image').substring(0, 20);
                var w = img.getAttribute('width') || 300;
                var h = img.getAttribute('height') || (img.naturalHeight || 450);
                var svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="xMidYMid slice">' +
                    '<defs><linearGradient id="bgGrad" x1="0%" y1="0%" x2="100%" y2="100%">' +
                    '<stop offset="0%" stop-color="#1a1a1a"/><stop offset="100%" stop-color="#0a0a0a"/>' +
                    '</linearGradient></defs>' +
                    '<rect width="100%" height="100%" fill="url(#bgGrad)"/>' +
                    '<text x="50%" y="50%" font-family="sans-serif" font-size="' + Math.max(12, w / 20) + '" fill="#C5A55A" text-anchor="middle" dominant-baseline="middle" opacity="0.6">' +
                    title.replace(/[<>&"]/g, '') + '</text></svg>';
                img.src = 'data:image/svg+xml;utf8,' + encodeURIComponent(svg);
            }
            // Capture errors during capture phase (errors don't bubble)
            document.addEventListener('error', function (e) {
                if (e.target && e.target.tagName === 'IMG') {
                    applyFallback(e.target);
                }
            }, true);
        })();
    </script>
    <script src="/js/content-protection.js" onerror="this.remove()"></script>
    <script>
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/sw.js').catch(() => {});
        }
    </script>
    {{-- Shaka Player (DRM/HLS playback). Loaded site-wide for simplicity;
         the FlikPlayer wrapper only instantiates when a player view mounts. --}}
    <script src="https://cdn.jsdelivr.net/npm/shaka-player@4.7.11/dist/shaka-player.compiled.js" defer></script>

    {{-- Cookie consent banner (GDPR / UU PDP). Self-hides once a decision
         is persisted under localStorage `flik_cookie_consent`. The footer
         "Cookie Settings" link calls `window.FlikConsent.reopen()` to bring
         it back. --}}
    <x-cookie-banner />

    {{-- Web Push opt-in banner. Hides itself when:
         - Browser doesn't support Notification/PushManager
         - VAPID public key is not configured (no meta tag emitted above)
         - User already has an active push subscription
         - User dismissed it (localStorage flag) --}}
    <x-push-opt-in />

    @stack('scripts')
</body>
</html>
