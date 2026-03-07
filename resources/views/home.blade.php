<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="FLiK — Rumah Sinema Indonesia. Platform streaming film premium dengan koleksi terlengkap film Indonesia klasik & modern.">
    <meta name="theme-color" content="#C5A55A">
    <meta property="og:title" content="FLiK — Rumah Sinema Indonesia">
    <meta property="og:description" content="Streaming film Indonesia terlengkap. Dari film jadul legendaris hingga karya terbaru.">
    <meta property="og:image" content="{{ asset('img/hero-bg.png') }}">
    <title>FLiK — Rumah Sinema Indonesia</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <link rel="manifest" href="/manifest.json">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    @vite('resources/css/app.css')
    <script defer src="https://unpkg.com/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <style>
        [x-cloak] { display: none !important; }
        body { font-family: 'Inter', sans-serif; }
        h1,h2,h3,h4,h5,h6,.font-heading { font-family: 'Outfit', sans-serif; }

        .hero-gradient {
            background: linear-gradient(180deg, rgba(0,0,0,0.3) 0%, rgba(0,0,0,0.7) 50%, #0a0a0a 100%);
        }
        .gold-text { color: #C5A55A; }
        .gold-gradient-text {
            background: linear-gradient(135deg, #F0D78C, #C5A55A);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .feature-card {
            background: linear-gradient(180deg, rgba(30,30,30,0.8), rgba(20,20,20,0.95));
            border: 1px solid rgba(197,165,90,0.15);
            backdrop-filter: blur(10px);
        }
        .glow-gold {
            box-shadow: 0 0 60px rgba(197,165,90,0.15), 0 0 120px rgba(197,165,90,0.05);
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-float { animation: float 6s ease-in-out infinite; }
        .animate-in { animation: fadeInUp 0.8s ease-out forwards; }
        .animate-in-delay { animation: fadeInUp 0.8s ease-out 0.2s forwards; opacity: 0; }
        .animate-in-delay2 { animation: fadeInUp 0.8s ease-out 0.4s forwards; opacity: 0; }
    </style>
</head>
<body class="bg-[#0a0a0a] text-gray-100 overflow-x-hidden">

<!-- ═══════════════════════════════════════════
     HEADER
     ═══════════════════════════════════════════ -->
<header class="fixed top-0 left-0 right-0 z-50 transition-all duration-300" x-data="{ scrolled: false }"
        @scroll.window="scrolled = (window.scrollY > 50)"
        :class="scrolled ? 'bg-black/90 backdrop-blur-md shadow-lg' : 'bg-gradient-to-b from-black/80 to-transparent'">
    <div class="container mx-auto px-6 py-4 flex items-center justify-between">
        <a href="/" class="flex items-center gap-2">
            <img src="{{ asset('img/flik-logo.png') }}" alt="FLiK" class="h-8 md:h-10">
        </a>
        <div class="flex items-center gap-4">
            <a href="{{ route('login') }}" class="text-sm font-medium text-gray-300 hover:text-white transition-colors px-4 py-2">Masuk</a>
            <a href="{{ route('register') }}" class="text-sm font-bold text-black px-6 py-2.5 rounded-lg transition-all hover:scale-105"
               style="background:linear-gradient(135deg,#C5A55A,#E8D5A3)">
                Daftar Gratis
            </a>
        </div>
    </div>
</header>


<!-- ═══════════════════════════════════════════
     HERO — Full Screen Cinema Experience
     ═══════════════════════════════════════════ -->
<section class="relative min-h-screen flex items-center justify-center overflow-hidden">
    <!-- Background Image -->
    <div class="absolute inset-0">
        <img src="{{ asset('img/hero-bg.png') }}" alt="" class="w-full h-full object-cover" style="filter:brightness(0.4) saturate(0.8)">
        <div class="hero-gradient absolute inset-0"></div>
    </div>

    <!-- Ambient Gold Glow -->
    <div class="absolute top-1/4 left-1/2 -translate-x-1/2 w-[600px] h-[600px] rounded-full opacity-20"
         style="background:radial-gradient(circle,rgba(197,165,90,0.3),transparent 70%)"></div>

    <!-- Content -->
    <div class="relative z-10 text-center px-6 max-w-4xl mx-auto">
        <div class="animate-in">
            <span class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full text-xs font-semibold mb-6"
                  style="background:rgba(197,165,90,0.15);color:#C5A55A;border:1px solid rgba(197,165,90,0.3)">
                🎬 #1 Platform Streaming Indonesia
            </span>
        </div>
        <h1 class="font-heading text-4xl md:text-6xl lg:text-7xl font-black leading-tight animate-in">
            Rumah <span class="gold-gradient-text">Sinema</span><br>Indonesia
        </h1>
        <p class="mt-6 text-lg md:text-xl text-gray-300 max-w-2xl mx-auto leading-relaxed animate-in-delay">
            Dari film jadul legendaris hingga karya terbaru. Koleksi terlengkap film Indonesia dalam satu platform premium.
        </p>

        <div class="mt-10 flex flex-col sm:flex-row gap-4 justify-center animate-in-delay2">
            <a href="{{ route('register') }}" class="inline-flex items-center justify-center gap-2 px-8 py-4 rounded-xl text-base font-bold text-black transition-all hover:scale-105 hover:shadow-2xl"
               style="background:linear-gradient(135deg,#C5A55A,#E8D5A3)">
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                Mulai Nonton Gratis
            </a>
            <a href="{{ route('plans.index') }}" class="inline-flex items-center justify-center gap-2 px-8 py-4 rounded-xl text-base font-medium text-white transition-all hover:scale-105"
               style="background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.2);backdrop-filter:blur(10px)">
                Lihat Paket
            </a>
        </div>

        <!-- Stats -->
        <div class="mt-16 flex gap-8 md:gap-16 justify-center text-center animate-in-delay2">
            <div>
                <div class="font-heading text-2xl md:text-3xl font-bold gold-text">500+</div>
                <div class="text-xs text-gray-500 mt-1">Film & Series</div>
            </div>
            <div>
                <div class="font-heading text-2xl md:text-3xl font-bold gold-text">4K</div>
                <div class="text-xs text-gray-500 mt-1">Kualitas Ultra HD</div>
            </div>
            <div>
                <div class="font-heading text-2xl md:text-3xl font-bold gold-text">0</div>
                <div class="text-xs text-gray-500 mt-1">Iklan Mengganggu</div>
            </div>
        </div>
    </div>

    <!-- Scroll Indicator -->
    <div class="absolute bottom-8 left-1/2 -translate-x-1/2 animate-bounce">
        <svg class="w-6 h-6 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
    </div>
</section>


<!-- ═══════════════════════════════════════════
     FEATURED BANNER
     ═══════════════════════════════════════════ -->
<section class="relative py-24 overflow-hidden">
    <div class="absolute inset-0 opacity-30">
        <img src="{{ asset('img/hero-film.png') }}" alt="" class="w-full h-full object-cover" style="filter:brightness(0.5)">
        <div class="absolute inset-0 bg-gradient-to-r from-[#0a0a0a] via-transparent to-[#0a0a0a]"></div>
        <div class="absolute inset-0 bg-gradient-to-b from-[#0a0a0a] via-transparent to-[#0a0a0a]"></div>
    </div>
    <div class="relative container mx-auto px-6 text-center">
        <h2 class="font-heading text-3xl md:text-5xl font-bold">
            Film Indonesia yang <span class="gold-gradient-text">Tak Lekang Waktu</span>
        </h2>
        <p class="mt-4 text-gray-400 max-w-2xl mx-auto text-lg">
            Koleksi eksklusif film klasik Indonesia yang telah di-restore dalam kualitas premium. Nostalgia sinema Indonesia dari era keemasan.
        </p>
    </div>
</section>


<!-- ═══════════════════════════════════════════
     FEATURES — Why FLiK?
     ═══════════════════════════════════════════ -->
<section class="py-24 relative">
    <div class="container mx-auto px-6">
        <div class="text-center mb-16">
            <span class="text-sm font-semibold gold-text tracking-widest uppercase">Kenapa FLiK</span>
            <h2 class="font-heading text-3xl md:text-4xl font-bold mt-3">
                Pengalaman Nonton yang <span class="gold-gradient-text">Premium</span>
            </h2>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-8 max-w-5xl mx-auto">
            <!-- Feature 1 -->
            <div class="feature-card rounded-2xl p-8 text-center transition-all hover:scale-105 hover:border-gold-500/30">
                <div class="w-16 h-16 mx-auto rounded-2xl flex items-center justify-center text-3xl mb-6" style="background:rgba(197,165,90,0.15)">
                    🎬
                </div>
                <h3 class="font-heading text-xl font-bold text-white">Konten Eksklusif</h3>
                <p class="mt-3 text-sm text-gray-400 leading-relaxed">
                    Film Indonesia klasik yang sudah di-restore dan karya original FLiK yang tidak tersedia di platform lain.
                </p>
            </div>

            <!-- Feature 2 -->
            <div class="feature-card rounded-2xl p-8 text-center transition-all hover:scale-105 hover:border-gold-500/30">
                <div class="w-16 h-16 mx-auto rounded-2xl flex items-center justify-center text-3xl mb-6" style="background:rgba(197,165,90,0.15)">
                    📱
                </div>
                <h3 class="font-heading text-xl font-bold text-white">Multi-Device</h3>
                <p class="mt-3 text-sm text-gray-400 leading-relaxed">
                    Tonton di HP, tablet, laptop, atau Smart TV. Progress otomatis tersinkron di semua perangkat.
                </p>
            </div>

            <!-- Feature 3 -->
            <div class="feature-card rounded-2xl p-8 text-center transition-all hover:scale-105 hover:border-gold-500/30">
                <div class="w-16 h-16 mx-auto rounded-2xl flex items-center justify-center text-3xl mb-6" style="background:rgba(197,165,90,0.15)">
                    🎮
                </div>
                <h3 class="font-heading text-xl font-bold text-white">Rewards & Gamifikasi</h3>
                <p class="mt-3 text-sm text-gray-400 leading-relaxed">
                    Kumpulkan coins, raih achievement, naik level. Nonton jadi lebih seru dengan sistem gamifikasi.
                </p>
            </div>
        </div>
    </div>
</section>


<!-- ═══════════════════════════════════════════
     PRICING PREVIEW
     ═══════════════════════════════════════════ -->
<section class="py-24 relative" style="background:linear-gradient(180deg,#0a0a0a,#111,#0a0a0a)">
    <div class="container mx-auto px-6 text-center">
        <span class="text-sm font-semibold gold-text tracking-widest uppercase">Paket Berlangganan</span>
        <h2 class="font-heading text-3xl md:text-4xl font-bold mt-3">
            Mulai dari <span class="gold-gradient-text">Rp 0</span>
        </h2>
        <p class="mt-4 text-gray-400 max-w-xl mx-auto">
            Nikmati ribuan konten gratis, atau upgrade untuk pengalaman premium tanpa batas.
        </p>

        <div class="mt-12 flex flex-col sm:flex-row gap-6 justify-center items-center">
            <div class="feature-card rounded-xl p-6 w-64 text-left">
                <div class="text-sm text-gray-500">Free</div>
                <div class="font-heading text-2xl font-bold mt-1">Rp 0</div>
                <div class="text-xs text-gray-500 mt-2">Akses konten terbatas • 480p • 1 device</div>
            </div>
            <div class="feature-card rounded-xl p-6 w-64 text-left glow-gold" style="border-color:rgba(197,165,90,0.4)">
                <div class="text-sm gold-text font-semibold">⭐ Premium</div>
                <div class="font-heading text-2xl font-bold mt-1">Rp 59.000</div>
                <div class="text-xs text-gray-500 mt-2">Full akses • 1080p • 3 devices • No ads</div>
            </div>
            <div class="feature-card rounded-xl p-6 w-64 text-left">
                <div class="text-sm text-gray-500">Ultra</div>
                <div class="font-heading text-2xl font-bold mt-1">Rp 99.000</div>
                <div class="text-xs text-gray-500 mt-2">Full akses • 4K HDR • 5 devices • Download</div>
            </div>
        </div>

        <a href="{{ route('plans.index') }}" class="inline-block mt-10 px-8 py-3 rounded-xl text-sm font-semibold transition-all hover:scale-105 gold-text"
           style="border:1px solid rgba(197,165,90,0.3);background:rgba(197,165,90,0.1)">
            Lihat Semua Paket →
        </a>
    </div>
</section>


<!-- ═══════════════════════════════════════════
     FAQ
     ═══════════════════════════════════════════ -->
<section class="py-24">
    <div class="container mx-auto px-6 max-w-3xl">
        <div class="text-center mb-12">
            <h2 class="font-heading text-3xl md:text-4xl font-bold">
                Pertanyaan <span class="gold-gradient-text">Umum</span>
            </h2>
        </div>
        <x-faq />
    </div>
</section>


<!-- ═══════════════════════════════════════════
     CTA — Final
     ═══════════════════════════════════════════ -->
<section class="py-24 relative overflow-hidden">
    <div class="absolute inset-0" style="background:radial-gradient(ellipse at center, rgba(197,165,90,0.08) 0%, transparent 70%)"></div>
    <div class="relative container mx-auto px-6 text-center">
        <h2 class="font-heading text-3xl md:text-5xl font-bold">
            Siap Nonton?
        </h2>
        <p class="mt-4 text-gray-400 text-lg">
            Gabung dengan jutaan penonton di seluruh Indonesia.
        </p>
        <div class="mt-8 max-w-md mx-auto flex gap-3">
            <input type="email" placeholder="Masukkan email kamu..." class="flex-1 px-4 py-3 rounded-xl text-sm bg-white/10 border border-white/20 text-white placeholder-gray-500 focus:outline-none focus:border-gold-500">
            <a href="{{ route('register') }}" class="px-6 py-3 rounded-xl text-sm font-bold text-black whitespace-nowrap"
               style="background:linear-gradient(135deg,#C5A55A,#E8D5A3)">
                Mulai →
            </a>
        </div>
        <p class="mt-3 text-xs text-gray-600">Gratis 30 hari pertama. Batalkan kapan saja.</p>
    </div>
</section>


<x-footer />
<x-flash />

</body>
</html>
