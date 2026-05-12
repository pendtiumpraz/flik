<x-layout title="Director / Actor Search — FLiK">
    <main class="min-h-screen bg-black pt-24 pb-20">
        <div class="container mx-auto px-4 md:px-8 lg:px-16 max-w-7xl">

            {{-- ── Header ─────────────────────────────────────────── --}}
            <div class="text-center mb-10 md:mb-12">
                <div class="inline-flex items-center justify-center gap-2 mb-4">
                    <x-icon name="sparkles" :size="18" class="text-[#C5A55A]" />
                    <span class="text-[11px] md:text-xs font-bold uppercase tracking-[0.25em]" style="color: #C5A55A">
                        Director & Actor Search
                    </span>
                    <x-icon name="sparkles" :size="18" class="text-[#C5A55A]" />
                </div>

                <h1 class="font-heading text-3xl md:text-5xl font-bold text-white leading-tight">
                    Cari film by <span style="background: linear-gradient(135deg, #C5A55A, #E8D5A3); -webkit-background-clip: text; background-clip: text; color: transparent;">orang</span>
                </h1>
                <p class="text-gray-400 mt-3 text-sm md:text-base max-w-xl mx-auto">
                    Ketik nama sutradara atau aktor. Contoh:
                    <em class="text-gray-300">"Christopher Nolan"</em>,
                    <em class="text-gray-300">"Reza Rahadian"</em>,
                    <em class="text-gray-300">"Joko Anwar"</em>.
                </p>
            </div>

            {{-- ── Form ───────────────────────────────────────────── --}}
            <form method="POST"
                  action="{{ url('/search/person') }}"
                  class="max-w-2xl mx-auto mb-12 md:mb-16">
                @csrf

                <div class="rounded-xl overflow-hidden"
                     style="background: rgba(20,18,16,0.7); border: 1px solid rgba(197,165,90,0.3); box-shadow: 0 8px 32px -8px rgba(197,165,90,0.15)">
                    <div class="flex items-stretch">
                        <div class="flex items-center pl-4 pr-2 text-[#C5A55A]">
                            <x-icon name="user" :size="20" />
                        </div>
                        <input type="text"
                               name="name"
                               value="{{ $personInput ?? '' }}"
                               placeholder="Nama sutradara atau aktor…"
                               required
                               minlength="2"
                               maxlength="100"
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

                {{-- Type selector --}}
                <div class="mt-4 flex flex-wrap items-center justify-center gap-2">
                    @php $currentType = $typeInput ?? 'auto'; @endphp
                    @foreach ([
                        'auto'     => 'Auto-detect',
                        'actor'    => 'Aktor',
                        'director' => 'Sutradara',
                    ] as $value => $label)
                        <label class="cursor-pointer">
                            <input type="radio" name="type" value="{{ $value }}"
                                   @if($currentType === $value) checked @endif
                                   class="sr-only peer">
                            <span class="text-[11px] md:text-xs px-3.5 py-1.5 rounded-full transition-all inline-block
                                         peer-checked:bg-[rgba(197,165,90,0.2)] peer-checked:text-white peer-checked:border-[#C5A55A]"
                                  style="background: rgba(197,165,90,0.05); border: 1px solid rgba(197,165,90,0.2); color: #E8D5A3">
                                {{ $label }}
                            </span>
                        </label>
                    @endforeach
                </div>

                @error('name')
                    <p class="mt-2 text-xs text-red-400 text-center">{{ $message }}</p>
                @enderror

                @if(!empty($error))
                    <p class="mt-2 text-xs text-red-400 text-center">{{ $error }}</p>
                @endif

                {{-- Suggestion chips --}}
                <div class="mt-5 flex flex-wrap items-center justify-center gap-2">
                    @foreach (['Christopher Nolan', 'Joko Anwar', 'Reza Rahadian', 'Quentin Tarantino', 'Hayao Miyazaki', 'Dian Sastrowardoyo'] as $suggestion)
                        <button type="submit"
                                name="name"
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
                    @php
                        $matchTypes = $movies->pluck('_match_type')->filter()->unique()->values();
                        $matchLabel = $matchTypes->count() === 1
                            ? ($matchTypes->first() === 'director' ? 'sebagai sutradara' : 'sebagai aktor')
                            : 'sebagai aktor & sutradara';
                    @endphp
                    <div class="mb-6 flex items-center gap-3">
                        <div class="flex-1 h-px" style="background: linear-gradient(90deg, transparent, rgba(197,165,90,0.4), transparent)"></div>
                        <div class="text-center px-4">
                            <p class="text-[11px] md:text-xs uppercase tracking-[0.25em] font-bold" style="color: #C5A55A">
                                {{ $movies->count() }} film
                            </p>
                            @if(!empty($personInput))
                                <p class="text-xs md:text-sm text-gray-400 mt-1">
                                    <span class="italic">"{{ $personInput }}"</span>
                                    <span class="text-gray-600">·</span>
                                    <span>{{ $matchLabel }}</span>
                                </p>
                            @endif
                        </div>
                        <div class="flex-1 h-px" style="background: linear-gradient(90deg, transparent, rgba(197,165,90,0.4), transparent)"></div>
                    </div>

                    <x-movies :movies="$movies" :genres="$genres" density="large">
                        <x-slot:category>
                            <x-icon name="user" :size="16" class="text-[#C5A55A]" />
                            <span>Filmografi</span>
                        </x-slot:category>
                    </x-movies>
                @else
                    <div class="text-center py-16 rounded-xl max-w-xl mx-auto"
                         style="background: rgba(20,18,16,0.5); border: 1px solid rgba(197,165,90,0.15)">
                        <x-icon name="user" :size="40" class="mx-auto text-gray-700 mb-3" />
                        <p class="text-gray-300 font-semibold">Tidak ada film yang ditemukan</p>
                        <p class="text-gray-500 text-xs mt-1.5 max-w-sm mx-auto">
                            Coba periksa ejaan nama, atau ganti tipe pencarian (Auto / Aktor / Sutradara).
                        </p>
                    </div>
                @endif
            @else
                {{-- Pre-search hint --}}
                <div class="max-w-3xl mx-auto grid grid-cols-1 md:grid-cols-3 gap-4 mt-8">
                    @foreach ([
                        ['icon' => 'user',     'title' => '1. Ketik nama',        'desc' => 'Sutradara atau aktor — bebas.'],
                        ['icon' => 'sparkles', 'title' => '2. AI bantu cari',     'desc' => 'Untuk sutradara, AI list filmografi.'],
                        ['icon' => 'film',     'title' => '3. Match ke katalog',  'desc' => 'Cocokkan ke koleksi FLiK.'],
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
