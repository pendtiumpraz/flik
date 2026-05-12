<x-layout title="Pilihan Family Night — FLiK">
    <main class="min-h-screen bg-black pt-24 pb-20">
        <div class="container mx-auto px-4 md:px-8 lg:px-16 max-w-7xl">

            {{-- ── Header ─────────────────────────────────────────── --}}
            <div class="text-center mb-8 md:mb-10">
                <div class="inline-flex items-center justify-center gap-2 mb-4">
                    <x-icon name="sparkles" :size="18" class="text-[#C5A55A]" />
                    <span class="text-[11px] md:text-xs font-bold uppercase tracking-[0.25em]" style="color: #C5A55A">
                        Hasil Family Night
                    </span>
                    <x-icon name="sparkles" :size="18" class="text-[#C5A55A]" />
                </div>

                <h1 class="font-heading text-3xl md:text-4xl font-bold text-white leading-tight">
                    Pilihan untuk
                    <span style="background: linear-gradient(135deg, #C5A55A, #E8D5A3); -webkit-background-clip: text; background-clip: text; color: transparent;">{{ count($selectedIds) }}</span>
                    penonton malam ini
                </h1>

                {{-- Constraints summary --}}
                @if(!empty($constraints))
                    <div class="mt-3 flex flex-wrap items-center justify-center gap-2">
                        @if(!empty($constraints['min_age']))
                            <span class="text-[11px] px-3 py-1 rounded-full"
                                  style="background: rgba(197,165,90,0.08); border: 1px solid rgba(197,165,90,0.25); color: #E8D5A3">
                                Umur termuda: {{ (int) $constraints['min_age'] }}
                            </span>
                        @endif
                        @if(!empty($constraints['duration_max_minutes']))
                            <span class="text-[11px] px-3 py-1 rounded-full"
                                  style="background: rgba(197,165,90,0.08); border: 1px solid rgba(197,165,90,0.25); color: #E8D5A3">
                                Maks {{ (int) $constraints['duration_max_minutes'] }} menit
                            </span>
                        @endif
                        @if(!empty($constraints['language']))
                            <span class="text-[11px] px-3 py-1 rounded-full"
                                  style="background: rgba(197,165,90,0.08); border: 1px solid rgba(197,165,90,0.25); color: #E8D5A3">
                                Bahasa: {{ strtoupper($constraints['language']) }}
                            </span>
                        @endif
                        @if(!empty($constraints['mood']))
                            <span class="text-[11px] px-3 py-1 rounded-full"
                                  style="background: rgba(197,165,90,0.08); border: 1px solid rgba(197,165,90,0.25); color: #E8D5A3">
                                Mood: {{ ucfirst(str_replace('-', ' ', $constraints['mood'])) }}
                            </span>
                        @endif
                    </div>
                @endif
            </div>

            {{-- ── Error ──────────────────────────────────────────── --}}
            @if(!empty($error))
                <div class="max-w-xl mx-auto mb-8 p-4 rounded-xl text-center"
                     style="background: rgba(220,38,38,0.08); border: 1px solid rgba(220,38,38,0.4); color: #FCA5A5">
                    <p class="text-sm">{{ $error }}</p>
                </div>
            @endif

            {{-- ── Picks ──────────────────────────────────────────── --}}
            @if($movies->count() > 0)
                <div class="mb-6 flex items-center gap-3">
                    <div class="flex-1 h-px" style="background: linear-gradient(90deg, transparent, rgba(197,165,90,0.4), transparent)"></div>
                    <div class="text-center px-4">
                        <p class="text-[11px] md:text-xs uppercase tracking-[0.25em] font-bold" style="color: #C5A55A">
                            Top {{ $movies->count() }} pilihan AI
                        </p>
                    </div>
                    <div class="flex-1 h-px" style="background: linear-gradient(90deg, transparent, rgba(197,165,90,0.4), transparent)"></div>
                </div>

                <x-movies :movies="$movies" :genres="$genres" density="large">
                    <x-slot:category>
                        <x-icon name="film" :size="16" class="text-[#C5A55A]" />
                        <span>Pas untuk semua di ruangan</span>
                    </x-slot:category>
                </x-movies>
            @elseif(empty($error))
                <div class="text-center py-16 rounded-xl max-w-xl mx-auto"
                     style="background: rgba(20,18,16,0.5); border: 1px solid rgba(197,165,90,0.15)">
                    <x-icon name="film" :size="40" class="mx-auto text-gray-700 mb-3" />
                    <p class="text-gray-300 font-semibold">Belum ada yang cocok</p>
                    <p class="text-gray-500 text-xs mt-1.5 max-w-sm mx-auto">
                        Coba longgarkan batasan (umur, durasi, bahasa, atau mood) lalu kirim lagi.
                    </p>
                </div>
            @endif

            {{-- ── Re-run / adjust form ────────────────────────────── --}}
            <div class="mt-12 md:mt-16">
                <div class="text-center mb-6">
                    <p class="text-[11px] md:text-xs uppercase tracking-[0.25em] font-bold" style="color: #C5A55A">
                        Atur ulang
                    </p>
                    <p class="text-gray-400 text-sm mt-1">Ubah penonton atau batasan, lalu cariin lagi.</p>
                </div>

                @include('family-night.partials.form', [
                    'viewers'        => $viewers,
                    'currentUserId'  => $currentUserId,
                    'selectedIds'    => $selectedIds,
                    'moods'          => $moods,
                    'languages'      => $languages,
                    'constraints'    => $constraints,
                ])
            </div>
        </div>
    </main>
</x-layout>
