@props(['movie'])

{{--
    AI Plot Explainer modal.
    Self-contained Alpine.js island. Drop <x-movies.plot-explain-modal :movie="$movieModel" />
    anywhere on the movie detail page — it renders both the floating launcher button
    AND the modal. Spoiler text starts collapsed; user clicks to reveal.

    Endpoint: POST /api/movies/{slug}/plot-explain  body: { question? }
--}}

@auth
<div
    x-data="plotExplainer({
        endpoint: '{{ route('movies.plot-explain', ['movie' => $movie->slug]) }}',
        movieTitle: @js($movie->title),
    })"
    x-cloak
>
    {{-- ━━━ Floating Launcher Button ━━━ --}}
    <button
        type="button"
        @click="open = true"
        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-md text-sm font-bold text-black hover:opacity-95 transition-opacity"
        style="background: linear-gradient(135deg, #C5A55A, #E8D5A3)"
        title="AI Plot Explainer — bisa mengandung spoiler"
    >
        <span aria-hidden="true">🔍</span>
        <span>Jelaskan Ending</span>
    </button>

    {{-- ━━━ Modal ━━━ --}}
    <div
        x-show="open"
        x-transition.opacity
        @keydown.escape.window="open = false"
        class="fixed inset-0 z-[60] flex items-center justify-center px-4 py-6"
        style="background: rgba(0,0,0,0.85); backdrop-filter: blur(6px);"
        role="dialog"
        aria-modal="true"
        aria-labelledby="plot-explain-title"
    >
        {{-- Click-outside backdrop --}}
        <div class="absolute inset-0" @click="open = false"></div>

        {{-- Dialog --}}
        <div
            x-show="open"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            class="relative w-full max-w-2xl max-h-[90vh] overflow-hidden rounded-2xl shadow-2xl flex flex-col"
            style="background: linear-gradient(180deg, #14110d 0%, #0a0908 100%); border: 1px solid rgba(197,165,90,0.35)"
            @click.stop
        >
            {{-- Header --}}
            <div class="flex items-center justify-between px-5 md:px-6 py-4" style="border-bottom: 1px solid rgba(197,165,90,0.18)">
                <div class="flex items-center gap-2.5 min-w-0">
                    <span aria-hidden="true" class="text-lg">🔍</span>
                    <div class="min-w-0">
                        <h3 id="plot-explain-title" class="font-heading font-semibold text-white text-base md:text-lg truncate">
                            Jelaskan Ending
                        </h3>
                        <p class="text-[11px] text-gray-500 truncate">{{ $movie->title }}</p>
                    </div>
                </div>
                <button
                    type="button"
                    @click="open = false"
                    class="ml-3 w-8 h-8 flex items-center justify-center rounded-full text-gray-400 hover:text-white hover:bg-white/5 transition-colors"
                    aria-label="Tutup"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>

            {{-- Body --}}
            <div class="flex-1 overflow-y-auto px-5 md:px-6 py-5 space-y-4">

                {{-- Description --}}
                <p class="text-sm text-gray-400 leading-relaxed">
                    AI akan menganalisis ending, plot twist, motif tema, dan motivasi karakter film ini.
                    Kamu bisa biarkan kosong untuk penjelasan umum, atau tanyakan hal spesifik.
                </p>

                {{-- Optional question input --}}
                <div>
                    <label for="plot-explain-question" class="block text-[11px] uppercase tracking-wider text-[#C5A55A] font-semibold mb-2">
                        Pertanyaan (opsional)
                    </label>
                    <textarea
                        id="plot-explain-question"
                        x-model="question"
                        rows="2"
                        maxlength="500"
                        :disabled="loading"
                        placeholder="Misal: kenapa karakter X melakukan itu? Apa arti adegan terakhir?"
                        class="w-full p-3 rounded-lg text-sm text-white placeholder-gray-600 resize-none focus:outline-none focus:border-[#C5A55A] transition-colors disabled:opacity-50"
                        style="background: rgba(20,18,16,0.7); border: 1px solid rgba(197,165,90,0.18)"
                    ></textarea>
                    <div class="mt-1 flex justify-end">
                        <span class="text-[10px] text-gray-600" x-text="`${question.length}/500`"></span>
                    </div>
                </div>

                {{-- Submit button --}}
                <button
                    type="button"
                    @click="submit()"
                    :disabled="loading"
                    class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-md text-sm font-bold text-black hover:opacity-95 transition-opacity disabled:opacity-60 disabled:cursor-wait"
                    style="background: linear-gradient(135deg, #C5A55A, #E8D5A3)"
                >
                    <template x-if="!loading">
                        <span class="inline-flex items-center gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            <span>Tampilkan Penjelasan</span>
                        </span>
                    </template>
                    <template x-if="loading">
                        <span class="inline-flex items-center gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" class="animate-spin"><path d="M21 12a9 9 0 1 1-6.22-8.56"/></svg>
                            <span>AI sedang menganalisis...</span>
                        </span>
                    </template>
                </button>

                {{-- Error --}}
                <div
                    x-show="error"
                    x-cloak
                    class="px-3 py-2.5 rounded-lg text-sm"
                    style="background: rgba(239,68,68,0.08); border: 1px solid rgba(239,68,68,0.3); color: #fca5a5"
                    x-text="error"
                ></div>

                {{-- Result block --}}
                <div x-show="result" x-cloak x-transition>
                    {{-- Spoiler warning gate (collapsed by default) --}}
                    <div
                        x-show="!revealed"
                        class="p-4 rounded-xl text-center"
                        style="background: rgba(239,68,68,0.06); border: 1px dashed rgba(239,68,68,0.4)"
                    >
                        <div class="inline-flex items-center gap-2 mb-2">
                            <span class="text-[10px] px-2 py-0.5 rounded uppercase tracking-wider font-semibold"
                                  style="background: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid rgba(239,68,68,0.3)">
                                Peringatan Spoiler
                            </span>
                        </div>
                        <p class="text-sm text-gray-400 mb-3">
                            Penjelasan berikut mengandung detail ending dan plot twist film.
                        </p>
                        <button
                            type="button"
                            @click="revealed = true"
                            class="inline-flex items-center gap-2 px-4 py-2 rounded-md text-xs font-bold text-black hover:opacity-95 transition-opacity"
                            style="background: linear-gradient(135deg, #C5A55A, #E8D5A3)"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            <span>Tampilkan Spoiler</span>
                        </button>
                    </div>

                    {{-- Revealed explanation --}}
                    <div x-show="revealed" x-cloak x-transition class="space-y-3">
                        <div class="flex items-center gap-2">
                            <span class="text-[10px] px-2 py-0.5 rounded uppercase tracking-wider font-semibold"
                                  style="background: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid rgba(239,68,68,0.3)">
                                Mengandung Spoiler
                            </span>
                            <span class="text-[10px] uppercase tracking-wider text-[#C5A55A] font-semibold">AI Analyst</span>
                            <button
                                type="button"
                                @click="revealed = false"
                                class="ml-auto text-[10px] text-gray-500 hover:text-gray-300 underline-offset-2 hover:underline"
                            >
                                Sembunyikan
                            </button>
                        </div>
                        <div
                            class="p-4 rounded-xl text-sm md:text-[15px] text-gray-200 leading-relaxed whitespace-pre-line"
                            style="background: rgba(20,18,16,0.7); border: 1px solid rgba(197,165,90,0.2)"
                            x-text="result"
                        ></div>
                    </div>
                </div>
            </div>

            {{-- Footer --}}
            <div class="px-5 md:px-6 py-3 text-[10px] text-gray-600 flex items-center justify-between"
                 style="border-top: 1px solid rgba(197,165,90,0.12); background: rgba(0,0,0,0.3)">
                <span>Analisis AI &mdash; mungkin tidak 100% akurat.</span>
                <span>Esc untuk tutup</span>
            </div>
        </div>
    </div>
</div>

<script>
    // Alpine component factory. Registered globally so x-data="plotExplainer({...})" works.
    if (typeof window.plotExplainer === 'undefined') {
        window.plotExplainer = function (cfg) {
            return {
                open: false,
                loading: false,
                question: '',
                result: '',
                error: '',
                revealed: false,
                endpoint: cfg.endpoint,
                movieTitle: cfg.movieTitle,

                async submit() {
                    if (this.loading) return;
                    this.loading = true;
                    this.error = '';
                    this.result = '';
                    this.revealed = false;

                    try {
                        const csrfMeta = document.querySelector('meta[name="csrf-token"]');
                        const csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

                        const res = await fetch(this.endpoint, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                                'X-CSRF-TOKEN': csrfToken,
                            },
                            body: JSON.stringify({ question: this.question || '' }),
                        });

                        let data = null;
                        try { data = await res.json(); } catch (_) { data = null; }

                        if (!res.ok) {
                            this.error = (data && (data.error || data.message))
                                || `Gagal memuat penjelasan (HTTP ${res.status}).`;
                            return;
                        }

                        if (!data || !data.explanation) {
                            this.error = 'AI tidak mengembalikan penjelasan. Coba lagi sebentar.';
                            return;
                        }

                        this.result = data.explanation;
                    } catch (e) {
                        this.error = 'Koneksi gagal. Periksa internet kamu lalu coba lagi.';
                    } finally {
                        this.loading = false;
                    }
                },
            };
        };
    }
</script>
@endauth
