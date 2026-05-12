<x-layout title="Bandingkan Film — FLiK">
    <main class="min-h-screen bg-black pt-24 pb-20">
        <div class="container mx-auto px-4 md:px-8 lg:px-16 max-w-5xl">

            {{-- ── Header ─────────────────────────────────────────── --}}
            <div class="text-center mb-10 md:mb-12">
                <div class="inline-flex items-center justify-center gap-2 mb-4">
                    <x-icon name="sparkles" :size="18" class="text-[#C5A55A]" />
                    <span class="text-[11px] md:text-xs font-bold uppercase tracking-[0.25em]" style="color: #C5A55A">
                        AI Movie Comparison
                    </span>
                    <x-icon name="sparkles" :size="18" class="text-[#C5A55A]" />
                </div>

                <h1 class="font-heading text-3xl md:text-5xl font-bold text-white leading-tight">
                    Bandingkan Dua
                    <span style="background: linear-gradient(135deg, #C5A55A, #E8D5A3); -webkit-background-clip: text; background-clip: text; color: transparent;">Film</span>
                </h1>
                <p class="text-gray-400 mt-3 text-sm md:text-base max-w-xl mx-auto">
                    Pilih dua judul, FLiK akan menyusun analisis side-by-side: plot, tema,
                    gaya visual, akting, sampai vonis film mana yang lebih unggul.
                </p>
            </div>

            {{-- ── Selection Form ─────────────────────────────────── --}}
            <form method="POST"
                  action="{{ url('/compare') }}"
                  x-data="movieCompareForm({{ $movies->toJson() }})"
                  class="rounded-2xl p-5 md:p-8"
                  style="background: rgba(20,18,16,0.7); border: 1px solid rgba(197,165,90,0.3); box-shadow: 0 12px 40px -12px rgba(197,165,90,0.18)">
                @csrf

                <div class="grid grid-cols-1 md:grid-cols-[1fr_auto_1fr] gap-5 md:gap-6 items-stretch">

                    {{-- Movie A --}}
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-[0.18em] mb-2" style="color: #C5A55A">
                            Film A
                        </label>
                        <div class="relative">
                            <input type="text"
                                   x-model="queryA"
                                   @input.debounce.150ms="onInputA()"
                                   @focus="openA = true"
                                   @click.outside="openA = false"
                                   list="compare-movies-a"
                                   placeholder="Ketik judul film…"
                                   autocomplete="off"
                                   required
                                   class="w-full bg-black/60 text-white placeholder-gray-500 rounded-lg px-4 py-3 text-sm md:text-base focus:outline-none transition-all"
                                   style="border: 1px solid rgba(197,165,90,0.35)"
                                   @focus.once="$el.style.borderColor='#C5A55A'">
                            <datalist id="compare-movies-a">
                                <template x-for="m in filteredA" :key="m.id">
                                    <option :value="m.label"></option>
                                </template>
                            </datalist>
                            <input type="hidden" name="movie_a" :value="selectedA ? selectedA.id : ''">
                        </div>
                        <p x-show="!selectedA && queryA.length >= 2" class="mt-1.5 text-[11px] text-gray-500" x-cloak>
                            Pilih dari saran untuk melanjutkan.
                        </p>
                        @error('movie_a')
                            <p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- VS divider --}}
                    <div class="flex md:flex-col items-center justify-center gap-2">
                        <span class="hidden md:block w-px h-10" style="background: rgba(197,165,90,0.3)"></span>
                        <span class="font-heading text-2xl md:text-3xl font-black uppercase tracking-widest"
                              style="background: linear-gradient(135deg, #C5A55A, #E8D5A3); -webkit-background-clip: text; background-clip: text; color: transparent;">VS</span>
                        <span class="hidden md:block w-px h-10" style="background: rgba(197,165,90,0.3)"></span>
                    </div>

                    {{-- Movie B --}}
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-[0.18em] mb-2" style="color: #C5A55A">
                            Film B
                        </label>
                        <div class="relative">
                            <input type="text"
                                   x-model="queryB"
                                   @input.debounce.150ms="onInputB()"
                                   @focus="openB = true"
                                   @click.outside="openB = false"
                                   list="compare-movies-b"
                                   placeholder="Ketik judul film…"
                                   autocomplete="off"
                                   required
                                   class="w-full bg-black/60 text-white placeholder-gray-500 rounded-lg px-4 py-3 text-sm md:text-base focus:outline-none transition-all"
                                   style="border: 1px solid rgba(197,165,90,0.35)"
                                   @focus.once="$el.style.borderColor='#C5A55A'">
                            <datalist id="compare-movies-b">
                                <template x-for="m in filteredB" :key="m.id">
                                    <option :value="m.label"></option>
                                </template>
                            </datalist>
                            <input type="hidden" name="movie_b" :value="selectedB ? selectedB.id : ''">
                        </div>
                        <p x-show="!selectedB && queryB.length >= 2" class="mt-1.5 text-[11px] text-gray-500" x-cloak>
                            Pilih dari saran untuk melanjutkan.
                        </p>
                        @error('movie_b')
                            <p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                {{-- Submit --}}
                <div class="mt-7 flex flex-col items-center gap-3">
                    <button type="submit"
                            :disabled="!canSubmit"
                            :class="canSubmit ? 'opacity-100 hover:scale-[1.02] cursor-pointer' : 'opacity-40 cursor-not-allowed'"
                            class="px-8 py-3 rounded-xl font-bold text-black text-sm md:text-base inline-flex items-center gap-2 transition-all"
                            style="background: linear-gradient(135deg, #C5A55A, #E8D5A3); box-shadow: 0 8px 24px -8px rgba(197,165,90,0.4)">
                        <x-icon name="sparkles" :size="16" />
                        <span>Bandingkan</span>
                        <x-icon name="chevron-right" :size="16" />
                    </button>

                    <p class="text-[11px] text-gray-500 text-center">
                        Analisis dihasilkan oleh AI — hasil mungkin bervariasi setiap kali.
                    </p>

                    @if(!empty($error))
                        <p class="text-xs text-red-400 text-center">{{ $error }}</p>
                    @endif
                </div>
            </form>

        </div>
    </main>

    @push('scripts')
        <script>
            function movieCompareForm(movies) {
                return {
                    movies: movies || [],
                    queryA: @json(old('movie_a_label', '')),
                    queryB: @json(old('movie_b_label', '')),
                    selectedA: null,
                    selectedB: null,
                    openA: false,
                    openB: false,

                    get filteredA() {
                        return this.filter(this.queryA, this.selectedB?.id);
                    },
                    get filteredB() {
                        return this.filter(this.queryB, this.selectedA?.id);
                    },
                    get canSubmit() {
                        return this.selectedA && this.selectedB && this.selectedA.id !== this.selectedB.id;
                    },

                    filter(q, excludeId) {
                        const needle = (q || '').trim().toLowerCase();
                        let pool = this.movies;
                        if (excludeId) pool = pool.filter(m => m.id !== excludeId);
                        if (needle.length < 1) return pool.slice(0, 50);
                        return pool.filter(m => m.label.toLowerCase().includes(needle)).slice(0, 50);
                    },

                    onInputA() {
                        this.selectedA = this.movies.find(m => m.label === this.queryA) || null;
                    },
                    onInputB() {
                        this.selectedB = this.movies.find(m => m.label === this.queryB) || null;
                    },
                };
            }
        </script>
    @endpush
</x-layout>
