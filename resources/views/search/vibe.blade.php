<x-layout title="Decade Search — FLiK">
    <main class="min-h-screen bg-black pt-24 pb-20">
        <div class="container mx-auto px-4 md:px-8 lg:px-16 max-w-7xl">

            {{-- ── Header ─────────────────────────────────────────── --}}
            <div class="text-center mb-10 md:mb-12">
                <div class="inline-flex items-center justify-center gap-2 mb-4">
                    <x-icon name="sparkles" :size="18" class="text-[#C5A55A]" />
                    <span class="text-[11px] md:text-xs font-bold uppercase tracking-[0.25em]" style="color: #C5A55A">
                        Decade & Vibe Search
                    </span>
                    <x-icon name="sparkles" :size="18" class="text-[#C5A55A]" />
                </div>

                <h1 class="font-heading text-3xl md:text-5xl font-bold text-white leading-tight">
                    Mau film <span style="background: linear-gradient(135deg, #C5A55A, #E8D5A3); -webkit-background-clip: text; background-clip: text; color: transparent;">era</span> mana?
                </h1>
                <p class="text-gray-400 mt-3 text-sm md:text-base max-w-xl mx-auto">
                    Ceritain era + vibe yang kamu cari. Contoh:
                    <em class="text-gray-300">"film 70an seperti The Godfather"</em>,
                    <em class="text-gray-300">"film 90an romance"</em>.
                </p>
            </div>

            {{-- ── Form ───────────────────────────────────────────── --}}
            <form method="POST"
                  action="{{ url('/search/vibe') }}"
                  class="max-w-2xl mx-auto mb-12 md:mb-16">
                @csrf

                <div class="rounded-xl overflow-hidden"
                     style="background: rgba(20,18,16,0.7); border: 1px solid rgba(197,165,90,0.3); box-shadow: 0 8px 32px -8px rgba(197,165,90,0.15)">
                    <div class="flex items-stretch">
                        <div class="flex items-center pl-4 pr-2 text-[#C5A55A]">
                            <x-icon name="sparkles" :size="20" />
                        </div>
                        <input type="text"
                               name="vibe"
                               value="{{ $vibeInput ?? '' }}"
                               placeholder="film 70an seperti The Godfather…"
                               required
                               minlength="2"
                               maxlength="300"
                               autocomplete="off"
                               class="flex-1 bg-transparent text-white placeholder-gray-500 px-2 py-3.5 md:py-4 text-sm md:text-base focus:outline-none">
                        <button type="submit"
                                class="px-4 md:px-6 m-1.5 rounded-lg font-bold text-black text-sm inline-flex items-center gap-2 transition-all hover:opacity-95"
                                style="background: linear-gradient(135deg, #C5A55A, #E8D5A3)">
                            <x-icon name="search" :size="14" />
                            <span class="hidden sm:inline">Cari</span>
                        </button>
                    </div>
                </div>

                {{-- Optional explicit decade override --}}
                <div class="mt-3 flex items-center gap-2 justify-center">
                    <label for="era-input" class="text-[11px] uppercase tracking-[0.2em] text-gray-500 font-bold">
                        Decade override (optional)
                    </label>
                    <select id="era-input" name="era"
                            class="text-xs px-3 py-1.5 rounded-md bg-[rgba(20,18,16,0.7)] text-white focus:outline-none"
                            style="border: 1px solid rgba(197,165,90,0.25)">
                        <option value="">Auto</option>
                        @foreach ([1950, 1960, 1970, 1980, 1990, 2000, 2010, 2020] as $d)
                            <option value="{{ $d }}" @if(($eraInput ?? '') == (string) $d) selected @endif>{{ $d }}s</option>
                        @endforeach
                    </select>
                </div>

                @error('vibe')
                    <p class="mt-2 text-xs text-red-400 text-center">{{ $message }}</p>
                @enderror

                @if(!empty($error))
                    <p class="mt-2 text-xs text-red-400 text-center">{{ $error }}</p>
                @endif

                {{-- Suggestion chips --}}
                <div class="mt-5 flex flex-wrap items-center justify-center gap-2">
                    @foreach ([
                        'film 70an seperti The Godfather',
                        'film 80an action seru',
                        'film 90an romance',
                        'film 2000an thriller',
                        'film noir hitam putih',
                        'epic Hollywood klasik',
                    ] as $suggestion)
                        <button type="submit"
                                name="vibe"
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
                                {{ $movies->count() }} film cocok
                            </p>
                            @if(!empty($vibeInput))
                                <p class="text-xs md:text-sm text-gray-400 mt-1 italic">"{{ $vibeInput }}"</p>
                            @endif
                        </div>
                        <div class="flex-1 h-px" style="background: linear-gradient(90deg, transparent, rgba(197,165,90,0.4), transparent)"></div>
                    </div>

                    <x-movies :movies="$movies" :genres="$genres" density="large">
                        <x-slot:category>
                            <x-icon name="film" :size="16" class="text-[#C5A55A]" />
                            <span>Curated untukmu</span>
                        </x-slot:category>
                    </x-movies>
                @else
                    <div class="text-center py-16 rounded-xl max-w-xl mx-auto"
                         style="background: rgba(20,18,16,0.5); border: 1px solid rgba(197,165,90,0.15)">
                        <x-icon name="film" :size="40" class="mx-auto text-gray-700 mb-3" />
                        <p class="text-gray-300 font-semibold">Tidak ada yang cocok</p>
                        <p class="text-gray-500 text-xs mt-1.5 max-w-sm mx-auto">
                            Coba ungkapkan vibe-mu dengan cara lain, atau pilih salah satu saran di atas.
                        </p>
                    </div>
                @endif
            @else
                {{-- Pre-search hint --}}
                <div class="max-w-3xl mx-auto grid grid-cols-1 md:grid-cols-3 gap-4 mt-8">
                    @foreach ([
                        ['icon' => 'sparkles', 'title' => '1. Tulis era + vibe', 'desc' => 'Bahasa Indonesia atau Inggris.'],
                        ['icon' => 'clock',    'title' => '2. AI ekstrak filter','desc' => 'Decade + genre + style descriptor.'],
                        ['icon' => 'film',     'title' => '3. Hasil curated',    'desc' => 'Ranked by relevance + popularity.'],
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
