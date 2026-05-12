<x-layout :title="'Kenali Selera Filmmu — FLiK'">
    <main class="min-h-screen bg-[#0a0a0a] text-white">
        <div class="mx-auto w-full max-w-4xl px-4 py-10 sm:py-16">

            {{-- Header --}}
            <header class="mb-8 text-center sm:mb-12">
                <span class="inline-block rounded-full border border-[#C5A55A]/30 bg-[#C5A55A]/10 px-3 py-1 text-xs font-semibold uppercase tracking-widest text-[#C5A55A]">
                    Onboarding
                </span>
                <h1 class="font-heading mt-4 text-3xl font-bold leading-tight sm:text-4xl md:text-5xl">
                    Kenali <span class="text-[#C5A55A]">Seleramu</span>.
                </h1>
                <p class="mx-auto mt-3 max-w-xl text-sm text-gray-400 sm:text-base">
                    Tiga pertanyaan singkat. Kami akan racik rekomendasi yang pas buatmu.
                </p>
            </header>

            {{-- Validation errors --}}
            @if ($errors->any())
                <div class="mb-6 rounded-xl border border-red-500/40 bg-red-500/10 p-4 text-sm text-red-300">
                    <ul class="list-inside list-disc space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ url('/onboarding') }}" class="space-y-10"
                  x-data="{
                      genres: @js(old('favorite_genres', [])),
                      eras: @js(old('favorite_eras', [])),
                      moods: @js(old('preferred_moods', [])),
                      toggle(list, val) {
                          const i = list.indexOf(val);
                          if (i === -1) list.push(val); else list.splice(i, 1);
                      }
                  }">
                @csrf

                {{-- ─── Q1: Genres ─── --}}
                <section class="rounded-2xl border border-white/5 bg-white/[0.02] p-5 sm:p-7">
                    <div class="mb-5 flex items-baseline justify-between gap-4">
                        <h2 class="font-heading text-lg font-semibold sm:text-xl">
                            <span class="text-[#C5A55A]">01.</span>
                            Pilih 3 genre favorit
                        </h2>
                        <span class="text-xs text-gray-500" x-text="`${genres.length} dipilih`"></span>
                    </div>

                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 sm:gap-3 md:grid-cols-4">
                        @foreach ($genres as $genre)
                            <label class="group relative flex cursor-pointer items-center justify-center rounded-xl border px-3 py-3 text-center text-sm font-medium transition-all"
                                   :class="genres.includes('{{ $genre->slug }}')
                                       ? 'border-[#C5A55A] bg-[#C5A55A]/15 text-[#C5A55A]'
                                       : 'border-white/10 bg-white/[0.03] text-gray-300 hover:border-white/30 hover:bg-white/5'">
                                <input type="checkbox"
                                       name="favorite_genres[]"
                                       value="{{ $genre->slug }}"
                                       class="sr-only"
                                       x-init="if (genres.includes('{{ $genre->slug }}')) $el.checked = true"
                                       @change="toggle(genres, '{{ $genre->slug }}')">
                                {{ $genre->name }}
                            </label>
                        @endforeach
                    </div>
                </section>

                {{-- ─── Q2: Eras ─── --}}
                <section class="rounded-2xl border border-white/5 bg-white/[0.02] p-5 sm:p-7">
                    <div class="mb-5 flex items-baseline justify-between gap-4">
                        <h2 class="font-heading text-lg font-semibold sm:text-xl">
                            <span class="text-[#C5A55A]">02.</span>
                            Era film mana yang kamu suka?
                        </h2>
                        <span class="text-xs text-gray-500" x-text="`${eras.length} dipilih`"></span>
                    </div>

                    <div class="grid grid-cols-3 gap-2 sm:grid-cols-4 sm:gap-3 md:grid-cols-8">
                        @foreach ($eras as $era)
                            <label class="group relative flex cursor-pointer items-center justify-center rounded-xl border px-2 py-3 text-center text-sm font-semibold transition-all"
                                   :class="eras.includes('{{ $era }}')
                                       ? 'border-[#C5A55A] bg-[#C5A55A]/15 text-[#C5A55A]'
                                       : 'border-white/10 bg-white/[0.03] text-gray-300 hover:border-white/30 hover:bg-white/5'">
                                <input type="checkbox"
                                       name="favorite_eras[]"
                                       value="{{ $era }}"
                                       class="sr-only"
                                       x-init="if (eras.includes('{{ $era }}')) $el.checked = true"
                                       @change="toggle(eras, '{{ $era }}')">
                                {{ $era }}
                            </label>
                        @endforeach
                    </div>
                </section>

                {{-- ─── Q3: Moods ─── --}}
                <section class="rounded-2xl border border-white/5 bg-white/[0.02] p-5 sm:p-7">
                    <div class="mb-5 flex items-baseline justify-between gap-4">
                        <h2 class="font-heading text-lg font-semibold sm:text-xl">
                            <span class="text-[#C5A55A]">03.</span>
                            Mood favoritmu
                            <span class="ml-1 text-xs font-normal text-gray-500">(opsional)</span>
                        </h2>
                        <span class="text-xs text-gray-500" x-text="`${moods.length} dipilih`"></span>
                    </div>

                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 sm:gap-3 md:grid-cols-4">
                        @foreach ($moods as $mood)
                            <label class="group relative flex cursor-pointer items-center justify-center rounded-xl border px-3 py-3 text-center text-sm font-medium capitalize transition-all"
                                   :class="moods.includes('{{ $mood }}')
                                       ? 'border-[#C5A55A] bg-[#C5A55A]/15 text-[#C5A55A]'
                                       : 'border-white/10 bg-white/[0.03] text-gray-300 hover:border-white/30 hover:bg-white/5'">
                                <input type="checkbox"
                                       name="preferred_moods[]"
                                       value="{{ $mood }}"
                                       class="sr-only"
                                       x-init="if (moods.includes('{{ $mood }}')) $el.checked = true"
                                       @change="toggle(moods, '{{ $mood }}')">
                                {{ str_replace('-', ' ', $mood) }}
                            </label>
                        @endforeach
                    </div>
                </section>

                {{-- Submit --}}
                <div class="sticky bottom-4 z-10 pt-2">
                    <button type="submit"
                            class="block w-full rounded-xl bg-gradient-to-r from-[#C5A55A] to-[#a8893f] px-6 py-4 text-base font-bold uppercase tracking-wide text-black shadow-lg shadow-[#C5A55A]/20 transition-all hover:from-[#d4b46a] hover:to-[#b89945] disabled:opacity-50"
                            :disabled="genres.length === 0 || eras.length === 0">
                        <span x-show="genres.length > 0 && eras.length > 0">Lihat Rekomendasiku</span>
                        <span x-show="genres.length === 0 || eras.length === 0" x-cloak>
                            Pilih minimal 1 genre & 1 era
                        </span>
                    </button>
                    <p class="mt-3 text-center text-xs text-gray-500">
                        Bisa kamu ubah kapan saja di profil.
                    </p>
                </div>
            </form>

        </div>
    </main>
</x-layout>
