<!DOCTYPE html>
<html lang="id" x-data="{ darkMode: localStorage.getItem('theme') !== 'light' }" 
      :class="darkMode ? 'dark' : 'light'"
      x-init="$watch('darkMode', val => localStorage.setItem('theme', val ? 'dark' : 'light'))">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
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
    @vite('resources/css/app.css')
    <link rel="stylesheet" href="https://unpkg.com/flickity@2/dist/flickity.min.css">
    <script src="https://unpkg.com/flickity@2/dist/flickity.pkgd.min.js"></script>
</head>
<style>
    [x-cloak] { display: none !important; }
</style>
<body>

    {{ $slot }}

    <x-flash />


    <script src="/js/app.js"></script>
    <script src="/js/content-protection.js"></script>
    <script>
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/sw.js').catch(() => {});
        }
    </script>
    @stack('scripts')
</body>
</html>
