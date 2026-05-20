{{--
    Server-rendered mirror of /offline.html (the static asset pre-cached by
    public/sw.js). Useful when a route is requested while online but the
    network round-trip is slow — the controller/route can short-circuit to
    this view. The static HTML is the canonical SW fallback because the SW
    can pre-cache a file URL but not a Laravel route.
--}}
<x-layout :hideHeader="true" title="FLiK — Kamu sedang offline">
    <div class="min-h-screen flex flex-col items-center justify-center text-center px-6 py-12" style="background: #0a0a0a;">
        <div class="w-16 h-16 rounded-2xl flex items-center justify-center mb-6"
             style="background: linear-gradient(135deg, #C5A55A, #E8D5A3); box-shadow: 0 10px 40px rgba(197,165,90,0.2);">
            <img src="{{ asset('img/flik-logo.png') }}" alt="FLiK" class="w-10 h-10 object-contain">
        </div>
        <div class="text-[#C5A55A] text-xs font-semibold uppercase tracking-widest mb-3">FLiK</div>
        <h1 class="text-3xl font-extrabold text-white mb-2 font-heading">Kamu sedang offline</h1>
        <p class="text-neutral-400 max-w-md mb-8 leading-relaxed">
            Tidak ada koneksi internet saat ini. Coba periksa Wi-Fi atau data
            selularmu, lalu muat ulang halaman ini.
        </p>
        <div class="flex flex-col gap-3 w-full max-w-xs">
            <button onclick="location.reload()" class="px-6 py-3 rounded-lg font-semibold text-black"
                    style="background: linear-gradient(135deg, #C5A55A, #E8D5A3);">
                Muat Ulang
            </button>
            <a href="/my-list" class="px-6 py-3 rounded-lg text-neutral-300 hover:text-white border border-neutral-800 hover:bg-neutral-900 transition">
                Buka My List (offline)
            </a>
            <a href="/" class="px-6 py-3 rounded-lg text-neutral-300 hover:text-white border border-neutral-800 hover:bg-neutral-900 transition">
                Beranda
            </a>
        </div>
    </div>
</x-layout>
