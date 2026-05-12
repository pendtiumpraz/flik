<x-layout title="Pencarian — FLiK">
    <main class="min-h-screen bg-black pt-24 pb-20">
        <div class="container mx-auto px-4 md:px-8 lg:px-16 max-w-7xl">

            {{-- ── Header / Search bar ───────────────────────────── --}}
            <div class="text-center mb-8 md:mb-10">
                <div class="inline-flex items-center justify-center gap-2 mb-3">
                    <x-icon name="sparkles" :size="18" class="text-[#C5A55A]" />
                    <span class="text-[11px] md:text-xs font-bold uppercase tracking-[0.25em]" style="color: #C5A55A">
                        Smart Search
                    </span>
                    <x-icon name="sparkles" :size="18" class="text-[#C5A55A]" />
                </div>

                <h1 class="font-heading text-2xl md:text-4xl font-bold text-white leading-tight">
                    Hasil untuk
                    <span style="background: linear-gradient(135deg, #C5A55A, #E8D5A3); -webkit-background-clip: text; background-clip: text; color: transparent;">"{{ $query }}"</span>
                </h1>

                @if($intent && $intent !== 'title')
                    <p class="text-gray-400 mt-2.5 text-xs md:text-sm">
                        AI mendeteksi:
                        <span class="px-2 py-0.5 rounded-full text-[11px] font-bold uppercase tracking-wider ml-1"
                              style="background: rgba(197,165,90,0.12); color: #E8D5A3; border: 1px solid rgba(197,165,90,0.3)">
                            {{ $intent }}
                        </span>
                        @if($normalized && $normalized !== $query)
                            <span class="text-gray-500">·</span>
                            <em class="text-gray-400">"{{ $normalized }}"</em>
                        @endif
                        @if($confidence > 0)
                            <span class="text-gray-500 text-[11px] ml-1">({{ number_format($confidence * 100, 0) }}% confidence)</span>
                        @endif
                    </p>
                @endif
            </div>

            {{-- ── Re-search bar ──────────────────────────────────── --}}
            <div class="max-w-2xl mx-auto mb-10 md:mb-14">
                <x-search.smart-bar :initial="$query" :standalone="true" />

                {{-- Intent override chips --}}
                <div class="mt-4 flex flex-wrap items-center justify-center gap-2">
                    <span class="text-[10px] uppercase tracking-[0.2em] text-gray-500 font-bold mr-1">Switch intent</span>
                    @foreach([
                        'auto'     => 'Auto',
                        'title'    => 'Judul',
                        'actor'    => 'Aktor',
                        'director' => 'Sutradara',
                        'vibe'     => 'Vibe',
                        'year'     => 'Tahun',
                        'genre'    => 'Genre',
                    ] as $key => $label)
                        @php $active = $intent === $key || ($key === 'auto' && false); @endphp
                        <a href="{{ route('search.smart') }}?q={{ urlencode($query) }}{{ $key !== 'auto' ? '&intent=' . $key : '' }}"
                           class="text-[11px] px-3 py-1.5 rounded-full transition-all hover:scale-[1.03]"
                           style="background: {{ $intent === $key ? 'rgba(197,165,90,0.2)' : 'rgba(197,165,90,0.06)' }}; border: 1px solid {{ $intent === $key ? '#C5A55A' : 'rgba(197,165,90,0.2)' }}; color: {{ $intent === $key ? '#E8D5A3' : '#C5A55A' }}">
                            {{ $label }}
                        </a>
                    @endforeach
                </div>
            </div>

            {{-- ── Errors ─────────────────────────────────────────── --}}
            @if(!empty($error))
                <div class="max-w-2xl mx-auto mb-6 px-4 py-3 rounded-lg text-center"
                     style="background: rgba(220,38,38,0.08); border: 1px solid rgba(220,38,38,0.3); color: #FCA5A5">
                    <p class="text-sm">{{ $error }}</p>
                </div>
            @endif

            {{-- ── Result groups ──────────────────────────────────── --}}
            @if(!empty($groups))
                <div class="space-y-12">
                    @foreach($groups as $group)
                        <section>
                            <div class="mb-5 flex items-center gap-3">
                                <div class="flex-1 h-px" style="background: linear-gradient(90deg, transparent, rgba(197,165,90,0.4), transparent)"></div>
                                <div class="text-center px-4">
                                    <p class="text-[11px] md:text-xs uppercase tracking-[0.25em] font-bold" style="color: #C5A55A">
                                        {{ $group['label'] }}
                                    </p>
                                    <p class="text-[11px] text-gray-500 mt-1">{{ $group['movies']->count() }} film</p>
                                </div>
                                <div class="flex-1 h-px" style="background: linear-gradient(90deg, transparent, rgba(197,165,90,0.4), transparent)"></div>
                            </div>

                            <x-movies :movies="$group['movies']" :genres="$genres" density="large">
                                <x-slot:category>
                                    <x-icon name="film" :size="16" class="text-[#C5A55A]" />
                                    <span>{{ $group['label'] }}</span>
                                </x-slot:category>
                            </x-movies>
                        </section>
                    @endforeach
                </div>
            @else
                {{-- Empty state --}}
                <div class="text-center py-16 rounded-xl max-w-xl mx-auto"
                     style="background: rgba(20,18,16,0.5); border: 1px solid rgba(197,165,90,0.15)">
                    <x-icon name="film" :size="40" class="mx-auto text-gray-700 mb-3" />
                    <p class="text-gray-300 font-semibold">Tidak ada hasil</p>
                    <p class="text-gray-500 text-xs mt-1.5 max-w-sm mx-auto">
                        Coba kata kunci lain, atau switch intent di atas (judul, aktor, vibe, dll).
                    </p>
                </div>
            @endif

            {{-- ── Quick links footer ─────────────────────────────── --}}
            <div class="max-w-3xl mx-auto mt-16 grid grid-cols-1 md:grid-cols-3 gap-3">
                <a href="{{ route('discovery.mood.form') }}"
                   class="rounded-xl p-4 text-center transition-all hover:scale-[1.02]"
                   style="background: rgba(20,18,16,0.5); border: 1px solid rgba(197,165,90,0.15)">
                    <x-icon name="sparkles" :size="18" class="mx-auto text-[#C5A55A] mb-2" />
                    <p class="font-heading font-semibold text-white text-sm">Discover by Mood</p>
                </a>
                <a href="{{ route('search.vibe.form') }}"
                   class="rounded-xl p-4 text-center transition-all hover:scale-[1.02]"
                   style="background: rgba(20,18,16,0.5); border: 1px solid rgba(197,165,90,0.15)">
                    <x-icon name="eye" :size="18" class="mx-auto text-[#C5A55A] mb-2" />
                    <p class="font-heading font-semibold text-white text-sm">Decade & Vibe</p>
                </a>
                <a href="{{ route('search.person.form') }}"
                   class="rounded-xl p-4 text-center transition-all hover:scale-[1.02]"
                   style="background: rgba(20,18,16,0.5); border: 1px solid rgba(197,165,90,0.15)">
                    <x-icon name="user" :size="18" class="mx-auto text-[#C5A55A] mb-2" />
                    <p class="font-heading font-semibold text-white text-sm">Director / Actor</p>
                </a>
            </div>
        </div>
    </main>
</x-layout>
