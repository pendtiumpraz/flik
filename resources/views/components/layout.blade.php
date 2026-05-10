@props(['hideHeader' => false, 'title' => null])

<!DOCTYPE html>
<html lang="id" x-data="{ darkMode: localStorage.getItem('theme') !== 'light' }"
      :class="darkMode ? 'dark' : 'light'"
      x-init="$watch('darkMode', val => localStorage.setItem('theme', val ? 'dark' : 'light'))">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="FLiK — Rumah Sinema Indonesia. Platform streaming film premium dengan koleksi terlengkap film Indonesia dan internasional.">
    <meta name="theme-color" content="#0A0A0A">
    <meta name="msapplication-TileColor" content="#C5A55A">
    @auth
    <meta name="user-name" content="{{ auth()->user()->name }}">
    @endauth
    
    <!-- Open Graph -->
    <meta property="og:title" content="FLiK — Rumah Sinema Indonesia">
    <meta property="og:description" content="Platform streaming film premium dengan koleksi terlengkap.">
    <meta property="og:image" content="{{ asset('img/flik-logo.png') }}">
    <meta property="og:type" content="website">
    
    <title>{{ $title ?? 'FLiK — Rumah Sinema Indonesia' }}</title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="manifest" href="/manifest.json">
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        h1, h2, h3, h4, h5, h6, .font-heading { font-family: 'Outfit', sans-serif; }
        .brand-gold { color: #C5A55A; }
    </style>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="stylesheet" href="https://unpkg.com/flickity@2/dist/flickity.min.css">
    <script src="https://unpkg.com/flickity@2/dist/flickity.pkgd.min.js"></script>
</head>
<style>
    [x-cloak] { display: none !important; }
</style>
<body>

    @unless($hideHeader)
        @auth
            <x-header />
        @endauth
    @endunless

    {{ $slot }}

    <x-flash />


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
    @stack('scripts')
</body>
</html>
