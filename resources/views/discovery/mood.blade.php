<x-layout title="Mood Discovery — FLiK">
    <main class="min-h-screen bg-black pt-24 pb-20">
        <div class="container mx-auto px-4 md:px-8 lg:px-16 max-w-7xl">

            {{-- ── Header ─────────────────────────────────────────── --}}
            <div class="text-center mb-10 md:mb-12">
                <div class="inline-flex items-center justify-center gap-2 mb-4">
                    <x-icon name="sparkles" :size="18" class="text-[#C5A55A]" />
                    <span class="text-[11px] md:text-xs font-bold uppercase tracking-[0.25em]" style="color: #C5A55A">
                        Mood Discovery
                    </span>
                    <x-icon name="sparkles" :size="18" class="text-[#C5A55A]" />
                </div>

                <h1 class="font-heading text-3xl md:text-5xl font-bold text-white leading-tight">
                    Lagi <span style="background: linear-gradient(135deg, #C5A55A, #E8D5A3); -webkit-background-clip: text; background-clip: text; color: transparent;">mood</span> apa hari ini?
                </h1>
                <p class="text-gray-400 mt-3 text-sm md:text-base max-w-xl mx-auto">
                    Ceritain perasaanmu, FLiK akan racik tontonan yang pas.
                    Contoh: <em class="text-gray-300">"lagi sedih"</em>,
                    <em class="text-gray-300">"mau yang seru-seruan"</em>,
                    <em class="text-gray-300">"santai sebelum tidur"</em>.
                </p>
            </div>

            {{-- ── Form ───────────────────────────────────────────── --}}
            <form method="POST"
                  action="{{ route('discovery.mood.discover') }}"
                  class="max-w-2xl mx-auto mb-12 md:mb-16">
                @csrf

                <div class="relative rounded-xl overflow-hidden"
                     style="background: rgba(20,18,16,0.7); border: 1px solid rgba(197,165,90,0.3); box-shadow: 0 8px 32px -8px rgba(197,165,90,0.15)">
                    <div class="flex items-stretch">
                        <div class="flex items-center pl-4 pr-2 text-[#C5A55A]">
                            <x-icon name="heart" :size="20" />
                        </div>
                        <input type="text"
                               name="mood"
                               value="{{ $moodInput ?? '' }}"
                               placeholder="Ketik mood-mu di sini…"
                               required
                               minlength="2"
                               maxlength="200"
                               autocomplete="off"
                               class="flex-1 bg-transparent text-white placeholder-gray-500 px-2 py-3.5 md:py-4 text-sm md:text-base focus:outline-none">
                        <button type="submit"
                                class="px-4 md:px-6 m-1.5 rounded-lg font-bold text-black text-sm inline-flex items-center gap-2 transition-all hover:opacity-95"
                                style="background: linear-gradient(135deg, #C5A55A, #E8D5A3)">
                            <x-icon name="sparkles" :size="14" />
                            <span class="hidden sm:inline">Temukan</span>
                        </button>
                    </div>
                </div>

                @error('mood')
                    <p class="mt-2 text-xs text-red-400 text-center">{{ $message }}</p>
                @enderror

                @if(!empty($error))
                    <p class="mt-2 text-xs text-red-400 text-center">{{ $error }}</p>
                @endif

                {{-- Suggestion chips --}}
                <div class="mt-5 flex flex-wrap items-center justify-center gap-2">
                    @foreach (['lagi sedih', 'mau yang seru-seruan', 'santai sebelum tidur', 'butuh inspirasi', 'romantis', 'nostalgia masa kecil'] as $suggestion)
                        <button type="submit"
                                name="mood"
                                value="{{ $suggestion }}"
                                class="text-[11px] md:text-xs px-3 py-1.5 rounded-full transition-all hover:scale-[1.03]"
                                style="background: rgba(197,165,90,0.08); border: 1px solid rgba(197,165,90,0.25); color: #E8D5A3">
                            {{ $suggestion }}
                        </button>
                    @endforeach
                </div>
            </form>

            {{-- ── Results ────────────────────────────────────────── --}}
            @if($submitted)
                @if($movies->count() > 0)
                    <div class="mb-6 flex items-center gap-3">
                        <div class="flex-1 h-px" style="background: linear-gradient(90deg, transparent, rgba(197,165,90,0.4), transparent)"></div>
                        <div class="text-center px-4">
                            <p class="text-[11px] md:text-xs uppercase tracking-[0.25em] font-bold" style="color: #C5A55A">
                                {{ $movies->count() }} film untuk mood-mu
                            </p>
                            @if(!empty($moodInput))
                                <p class="text-xs md:text-sm text-gray-400 mt-1 italic">"{{ $moodInput }}"</p>
                            @endif
                        </div>
                        <div class="flex-1 h-px" style="background: linear-gradient(90deg, transparent, rgba(197,165,90,0.4), transparent)"></div>
                    </div>

                    <x-movies :movies="$movies" :genres="$genres" density="large">
                        <x-slot:category>
                            <x-icon name="film" :size="16" class="text-[#C5A55A]" />
                            <span>Rekomendasi untukmu</span>
                        </x-slot:category>
                    </x-movies>
                @else
                    <div class="text-center py-16 rounded-xl max-w-xl mx-auto"
                         style="background: rgba(20,18,16,0.5); border: 1px solid rgba(197,165,90,0.15)">
                        <x-icon name="film" :size="40" class="mx-auto text-gray-700 mb-3" />
                        <p class="text-gray-300 font-semibold">Belum ada yang cocok</p>
                        <p class="text-gray-500 text-xs mt-1.5 max-w-sm mx-auto">
                            Coba ungkapkan mood-mu dengan cara lain, atau pilih salah satu saran di atas.
                        </p>
                    </div>
                @endif
            @else
                {{-- Pre-search hint: explain how it works --}}
                <div class="max-w-3xl mx-auto grid grid-cols-1 md:grid-cols-3 gap-4 mt-8">
                    @foreach ([
                        ['icon' => 'heart',    'title' => '1. Cerita mood-mu',    'desc' => 'Bebas bahasa Indonesia atau Inggris.'],
                        ['icon' => 'sparkles', 'title' => '2. AI baca perasaan',  'desc' => 'Diterjemahkan ke tag mood film.'],
                        ['icon' => 'film',     'title' => '3. Rekomendasi pas',   'desc' => 'Diurutkan dari yang paling populer.'],
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
            @endif
        </div>
    </main>
</x-layout>
