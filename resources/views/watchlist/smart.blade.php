<x-layout title="Smart Watchlist — FLiK">
    <main class="min-h-screen bg-black pt-24 pb-20">
        <div class="container mx-auto px-4 md:px-8 lg:px-16 max-w-7xl">

            {{-- ── Header ─────────────────────────────────────────── --}}
            <div class="text-center mb-10 md:mb-12">
                <div class="inline-flex items-center justify-center gap-2 mb-4">
                    <x-icon name="sparkles" :size="18" class="text-[#C5A55A]" />
                    <span class="text-[11px] md:text-xs font-bold uppercase tracking-[0.25em]" style="color: #C5A55A">
                        Smart Watchlist
                    </span>
                    <x-icon name="sparkles" :size="18" class="text-[#C5A55A]" />
                </div>

                <h1 class="font-heading text-3xl md:text-5xl font-bold text-white leading-tight">
                    Watchlist-mu, <span style="background: linear-gradient(135deg, #C5A55A, #E8D5A3); -webkit-background-clip: text; background-clip: text; color: transparent;">diurutkan AI</span>
                </h1>
                <p class="text-gray-400 mt-3 text-sm md:text-base max-w-xl mx-auto">
                    Film yang paling cocok kamu tonton <em class="text-gray-300">sekarang</em>
                    diangkat ke atas, berdasarkan kebiasaan nontonmu belakangan ini.
                </p>

                <div class="mt-5 flex flex-wrap items-center justify-center gap-2">
                    <a href="{{ route('watchlist.index') }}"
                       class="text-[11px] md:text-xs px-3 py-1.5 rounded-full transition-all hover:scale-[1.03]"
                       style="background: rgba(197,165,90,0.08); border: 1px solid rgba(197,165,90,0.25); color: #E8D5A3">
                        Lihat watchlist standar
                    </a>
                    <span class="text-[11px] md:text-xs text-gray-500">
                        Diperbarui otomatis tiap 1 jam
                    </span>
                </div>
            </div>

            {{-- ── Results ────────────────────────────────────────── --}}
            @if($movies->count() > 0)
                <div class="mb-6 flex items-center gap-3">
                    <div class="flex-1 h-px" style="background: linear-gradient(90deg, transparent, rgba(197,165,90,0.4), transparent)"></div>
                    <div class="text-center px-4">
                        <p class="text-[11px] md:text-xs uppercase tracking-[0.25em] font-bold" style="color: #C5A55A">
                            {{ $count }} film di watchlist-mu
                        </p>
                        <p class="text-xs md:text-sm text-gray-400 mt-1 italic">Diurutkan dari prioritas tertinggi</p>
                    </div>
                    <div class="flex-1 h-px" style="background: linear-gradient(90deg, transparent, rgba(197,165,90,0.4), transparent)"></div>
                </div>

                <x-movies :movies="$movies" :genres="$genres" density="large">
                    <x-slot:category>
                        <x-icon name="sparkles" :size="16" class="text-[#C5A55A]" />
                        <span>Tonton dulu yang ini</span>
                    </x-slot:category>
                </x-movies>
            @else
                <div class="text-center py-16 rounded-xl max-w-xl mx-auto"
                     style="background: rgba(20,18,16,0.5); border: 1px solid rgba(197,165,90,0.15)">
                    <x-icon name="film" :size="40" class="mx-auto text-gray-700 mb-3" />
                    <p class="text-gray-300 font-semibold">Watchlist-mu masih kosong</p>
                    <p class="text-gray-500 text-xs mt-1.5 max-w-sm mx-auto">
                        Tambahkan beberapa film favorit ke watchlist, lalu kembali ke sini.
                        AI akan bantu urutkan mana yang pas ditonton sekarang.
                    </p>
                    <a href="{{ route('velflix.index') }}"
                       class="mt-6 inline-flex items-center gap-2 px-6 py-3 rounded-lg font-semibold text-black"
                       style="background: linear-gradient(135deg, #C5A55A, #E8D5A3)">
                        Jelajahi Film
                    </a>
                </div>
            @endif
        </div>
    </main>
</x-layout>
