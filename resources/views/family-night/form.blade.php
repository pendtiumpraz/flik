<x-layout title="Family Movie Night — FLiK">
    <main class="min-h-screen bg-black pt-24 pb-20">
        <div class="container mx-auto px-4 md:px-8 lg:px-16 max-w-4xl">

            {{-- ── Header ─────────────────────────────────────────── --}}
            <div class="text-center mb-10 md:mb-12">
                <div class="inline-flex items-center justify-center gap-2 mb-4">
                    <x-icon name="sparkles" :size="18" class="text-[#C5A55A]" />
                    <span class="text-[11px] md:text-xs font-bold uppercase tracking-[0.25em]" style="color: #C5A55A">
                        Family Movie Night
                    </span>
                    <x-icon name="sparkles" :size="18" class="text-[#C5A55A]" />
                </div>

                <h1 class="font-heading text-3xl md:text-5xl font-bold text-white leading-tight">
                    Nonton bareng?
                    <span style="background: linear-gradient(135deg, #C5A55A, #E8D5A3); -webkit-background-clip: text; background-clip: text; color: transparent;">FLiK pilihkan</span>
                </h1>
                <p class="text-gray-400 mt-3 text-sm md:text-base max-w-xl mx-auto">
                    Pilih siapa saja yang akan nonton, atur batasan (umur, durasi, mood),
                    biar AI cariin 5 film yang cocok untuk semua orang di ruangan.
                </p>
            </div>

            @include('family-night.partials.form', [
                'viewers'        => $viewers,
                'currentUserId'  => $currentUserId,
                'selectedIds'    => $selectedIds ?? [$currentUserId],
                'moods'          => $moods,
                'languages'      => $languages,
                'constraints'    => $constraints ?? [],
            ])

            {{-- ── Pre-search hint ────────────────────────────────── --}}
            <div class="max-w-3xl mx-auto grid grid-cols-1 md:grid-cols-3 gap-4 mt-12">
                @foreach ([
                    ['icon' => 'plus',     'title' => '1. Pilih penonton',    'desc' => 'Tambah anggota keluarga atau teman yang ikut nonton.'],
                    ['icon' => 'sparkles', 'title' => '2. Atur batasan',     'desc' => 'Umur termuda, batas durasi, bahasa, mood malam ini.'],
                    ['icon' => 'film',     'title' => '3. Dapat 5 pilihan', 'desc' => 'AI ramu film yang cocok untuk seluruh ruangan.'],
                ] as $step)
                    <div class="rounded-xl p-5 text-center"
                         style="background: rgba(20,18,16,0.5); border: 1px solid rgba(197,165,90,0.12)">
                        <div class="inline-flex items-center justify-center w-10 h-10 rounded-full mb-3"
                             style="background: rgba(197,165,90,0.12); border: 1px solid rgba(197,165,90,0.3)">
                            <x-icon :name="$step['icon']" :size="18" class="text-[#C5A55A]" />
                        </div>
                        <p class="font-heading font-semibold text-white text-sm">{{ $step['title'] }}</p>
                        <p class="text-gray-400 text-xs mt-1.5 leading-relaxed">{{ $step['desc'] }}</p>
                    </div>
                @endforeach
            </div>

        </div>
    </main>
</x-layout>
