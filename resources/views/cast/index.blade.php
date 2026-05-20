<x-layout title="Cast & Sutradara — FLiK"
          description="Telusuri aktor dan sutradara di katalog FLiK. Lihat filmografi, biografi, dan analisis auteur untuk setiap nama.">

    <div class="bg-[#0a0a0a] text-white min-h-screen">
        <div class="max-w-[1600px] mx-auto px-4 md:px-8 lg:px-16 py-10">

            {{-- ━━━ Header ━━━ --}}
            <div class="mb-8">
                <div class="flex items-center gap-3 text-xs uppercase tracking-[0.25em] text-[#C5A55A]/80 mb-2">
                    <span class="inline-block w-8 h-px bg-[#C5A55A]"></span>
                    <span>People</span>
                </div>
                <h1 class="font-heading text-3xl md:text-5xl font-bold tracking-tight">
                    Aktor &amp; <span class="text-[#C5A55A]">Sutradara</span>
                </h1>
                <p class="mt-2 text-gray-400 text-sm md:text-base max-w-2xl">
                    Telusuri filmografi lengkap, biografi, dan analisis auteur dari setiap nama
                    yang muncul di katalog FLiK.
                </p>
            </div>

            {{-- ━━━ Filter bar ━━━ --}}
            <form method="GET" action="{{ route('public.cast.index') }}"
                  class="mb-8 flex flex-col md:flex-row gap-3 items-stretch md:items-center">
                <div class="flex-1 relative">
                    <input type="text"
                           name="q"
                           value="{{ $q }}"
                           placeholder="Cari nama aktor atau sutradara…"
                           class="w-full px-4 py-3 pl-11 rounded-xl bg-[#141210] border border-white/5 focus:border-[#C5A55A]/60 focus:ring-1 focus:ring-[#C5A55A]/40 text-sm text-white placeholder-gray-500 outline-none transition">
                    <svg class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-500"
                         fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                </div>

                <div class="flex gap-2">
                    @foreach(['all' => 'Semua', 'actor' => 'Aktor', 'director' => 'Sutradara'] as $val => $label)
                        <a href="{{ route('public.cast.index', array_filter(['q' => $q ?: null, 'role' => $val === 'all' ? null : $val])) }}"
                           class="px-4 py-2.5 rounded-xl text-xs font-semibold uppercase tracking-wider transition
                                  {{ $role === $val ? 'bg-[#C5A55A] text-black' : 'bg-[#141210] border border-white/5 text-gray-300 hover:border-[#C5A55A]/40' }}">
                            {{ $label }}
                        </a>
                    @endforeach
                </div>

                <button type="submit"
                        class="px-5 py-2.5 rounded-xl bg-[#C5A55A] hover:bg-[#D4B36A] text-black text-sm font-semibold transition">
                    Cari
                </button>
            </form>

            {{-- ━━━ Results meta ━━━ --}}
            <div class="mb-4 text-xs text-gray-500">
                Menampilkan {{ $casts->firstItem() ?? 0 }}–{{ $casts->lastItem() ?? 0 }}
                dari {{ $casts->total() }} hasil
            </div>

            {{-- ━━━ Grid ━━━ --}}
            @if($casts->isEmpty())
                <div class="py-20 text-center text-gray-400">
                    <div class="text-5xl mb-3 text-[#C5A55A]/40">∅</div>
                    <p>Tidak ada hasil. Coba kata kunci lain atau ubah filter.</p>
                </div>
            @else
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-4 md:gap-5">
                    @foreach($casts as $cast)
                        @include('cast.partials.cast-card', ['cast' => $cast])
                    @endforeach
                </div>

                <div class="mt-10">
                    {{ $casts->onEachSide(1)->links() }}
                </div>
            @endif

        </div>
    </div>
</x-layout>
