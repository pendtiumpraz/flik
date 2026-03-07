<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="description" content="FLiK — Rumah Sinema Indonesia. Platform streaming film premium dengan koleksi terlengkap.">
    <title>FLiK — Rumah Sinema Indonesia</title>
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        h1, h2, h3, h4, h5, h6, .font-heading { font-family: 'Outfit', sans-serif; }
    </style>
    @vite('resources/css/app.css')
    <!-- Alpine Plugins -->
    <script defer src="https://unpkg.com/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>
    <!-- Alpine CDN -->
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
</head>
<style>
    [x-cloak] { display: none !important; }
</style>
<body class="bg-black text-gray-100">

<x-header />

<nav class="mt-14 md:mt-16">
    <x-navbar />
</nav>

<x-gap />

<!-- Feature Section 1 -->
<section>
    <div class="flex flex-col md:flex-row items-center justify-center p-8 md:p-12 gap-8">
        <div class="w-full md:w-2/5 text-center md:text-left order-2 md:order-1">
            <div class="font-heading text-2xl md:text-4xl font-bold">Enjoy on your TV.</div>
            <div class="text-lg md:text-2xl mt-3 text-gray-300">
                Watch on Smart TV, Playstation, Xbox, Chromecast, Apple TV, Blu-ray players, and more.
            </div>
        </div>
        <img class="w-full max-w-md md:max-w-lg order-1 md:order-2" src="{{ asset('img/img1.png') }}" alt="TV" />
    </div>
</section>

<x-gap />

<!-- Feature Section 2 -->
<section>
    <div class="flex flex-col md:flex-row items-center justify-center p-8 md:p-12 gap-8">
        <img class="w-full max-w-md md:max-w-lg" src="{{ asset('img/img2.png') }}" alt="Download" />
        <div class="w-full md:w-2/5 text-center md:text-left">
            <div class="font-heading text-2xl md:text-4xl font-bold">Download your shows to watch offline.</div>
            <div class="text-lg md:text-2xl mt-3 text-gray-300">
                Save your favorites easily and always have something to watch.
            </div>
        </div>
    </div>
</section>

<x-gap />

<!-- Feature Section 3 -->
<section>
    <div class="flex flex-col md:flex-row items-center justify-center p-8 md:p-12 gap-8">
        <div class="w-full md:w-2/5 text-center md:text-left order-2 md:order-1">
            <div class="font-heading text-2xl md:text-4xl font-bold">Watch everywhere.</div>
            <div class="text-lg md:text-2xl mt-3 text-gray-300">
                Stream unlimited movies and TV shows on your phone, tablet, laptop, and TV.
            </div>
        </div>
        <img class="w-full max-w-md md:max-w-lg order-1 md:order-2" src="{{ asset('img/img3.png') }}" alt="Devices" />
    </div>
</section>

<x-gap />

<!-- FAQ -->
<section>
    <x-faq />
</section>
<!-- End FAQ -->

<section class="z-30 flex flex-col items-center justify-center py-20 md:py-32 text-gray-100">
    <x-newsletter />
</section>

<x-gap />

<x-footer />

<x-flash />
</body>
</html>
