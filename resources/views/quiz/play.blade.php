<x-layout :title="'Quiz · ' . $movie->title . ' — FLiK'">
    <main class="min-h-screen bg-[#0a0a0a] text-white pt-20 pb-16">
        <div class="mx-auto w-full max-w-3xl px-4 sm:px-6">

            {{-- Header --}}
            <header class="mb-6 sm:mb-8">
                <a href="{{ route('movies.show', $movie) }}"
                   class="inline-flex items-center gap-1.5 text-xs text-gray-400 hover:text-[#C5A55A] transition">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                    </svg>
                    Kembali ke {{ $movie->title }}
                </a>
                <div class="mt-3 flex items-baseline justify-between gap-4">
                    <h1 class="font-heading text-2xl sm:text-3xl font-bold leading-tight">
                        Trivia <span class="text-[#C5A55A]">Quiz</span>
                    </h1>
                    <span class="rounded-full border border-[#C5A55A]/30 bg-[#C5A55A]/10 px-3 py-1 text-[11px] font-semibold uppercase tracking-widest text-[#C5A55A]">
                        {{ $movie->title }}
                    </span>
                </div>
                <p class="mt-2 text-sm text-gray-400">
                    Jawab {{ $total }} pertanyaan. +{{ 10 }} XP &amp; +{{ 5 }} koin per jawaban benar.
                </p>
            </header>

            <div x-data="quizGame({{ $questions->toJson() }})" x-cloak>

                {{-- Progress bar + timer --}}
                <div class="mb-5 flex items-center justify-between gap-4">
                    <div class="flex-1">
                        <div class="mb-1.5 flex items-center justify-between text-[11px] uppercase tracking-wider text-gray-500">
                            <span>Pertanyaan <span class="text-white font-semibold" x-text="current + 1"></span> / <span x-text="questions.length"></span></span>
                            <span>
                                Benar:
                                <span class="text-[#C5A55A] font-semibold" x-text="correctCount"></span>
                            </span>
                        </div>
                        <div class="h-1.5 w-full bg-white/5 rounded-full overflow-hidden">
                            <div class="h-full rounded-full transition-all duration-300"
                                 :style="`width: ${((current) / questions.length) * 100}%; background: linear-gradient(90deg, #C5A55A, #E8D5A3)`"></div>
                        </div>
                    </div>
                    <div class="flex items-center gap-1.5 rounded-lg border border-white/10 bg-white/[0.03] px-3 py-1.5 text-sm font-mono">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-[#C5A55A]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span x-text="formatTime(elapsed)"></span>
                    </div>
                </div>

                {{-- Question card --}}
                <template x-if="questions.length > 0">
                    <article class="rounded-2xl border border-white/5 bg-white/[0.02] p-5 sm:p-7"
                             x-transition:enter="transition ease-out duration-200"
                             x-transition:enter-start="opacity-0 translate-y-2"
                             x-transition:enter-end="opacity-100 translate-y-0">

                        <span class="inline-block text-[10px] uppercase tracking-widest text-gray-500 mb-2"
                              x-text="`Difficulty · ${questions[current].difficulty}`"></span>

                        <h2 class="font-heading text-lg sm:text-xl font-semibold leading-snug"
                            x-text="questions[current].question"></h2>

                        <div class="mt-5 grid gap-2.5 sm:gap-3">
                            <template x-for="letter in ['a','b','c','d']" :key="letter">
                                <button type="button"
                                        @click="selectAnswer(letter)"
                                        :disabled="answers[questions[current].id]"
                                        class="group w-full text-left rounded-xl border px-4 py-3.5 sm:px-5 sm:py-4 transition-all duration-200 flex items-start gap-3"
                                        :class="answers[questions[current].id] === letter
                                            ? 'border-[#C5A55A] bg-[#C5A55A]/15'
                                            : 'border-white/10 bg-white/[0.03] hover:border-[#C5A55A]/40 hover:bg-white/[0.05]'">
                                    <span class="flex-shrink-0 inline-flex items-center justify-center w-7 h-7 rounded-md font-bold text-xs uppercase"
                                          :class="answers[questions[current].id] === letter
                                              ? 'bg-[#C5A55A] text-black'
                                              : 'bg-white/5 text-gray-400 group-hover:bg-[#C5A55A]/20 group-hover:text-[#C5A55A]'"
                                          x-text="letter"></span>
                                    <span class="flex-1 text-sm sm:text-base"
                                          :class="answers[questions[current].id] === letter ? 'text-white' : 'text-gray-200'"
                                          x-text="questions[current].options[letter]"></span>
                                </button>
                            </template>
                        </div>
                    </article>
                </template>

                {{-- Navigation buttons --}}
                <div class="mt-6 flex items-center justify-between gap-3">
                    <button type="button"
                            @click="prev()"
                            :disabled="current === 0"
                            class="inline-flex items-center gap-1.5 rounded-xl border border-white/10 bg-white/[0.03] px-4 py-2.5 text-sm font-medium text-gray-300 transition hover:border-white/30 hover:bg-white/5 disabled:opacity-40 disabled:cursor-not-allowed">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                        </svg>
                        Sebelumnya
                    </button>

                    <span class="text-xs text-gray-500" x-text="answeredCount + ' dijawab'"></span>

                    <template x-if="current < questions.length - 1">
                        <button type="button"
                                @click="next()"
                                :disabled="!answers[questions[current].id]"
                                class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-[#C5A55A] to-[#a8893f] px-5 py-2.5 text-sm font-bold uppercase tracking-wide text-black shadow-lg shadow-[#C5A55A]/20 transition hover:from-[#d4b46a] hover:to-[#b89945] disabled:opacity-40 disabled:cursor-not-allowed">
                            Berikutnya
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                            </svg>
                        </button>
                    </template>

                    <template x-if="current === questions.length - 1">
                        <button type="button"
                                @click="submit()"
                                :disabled="answeredCount < questions.length || submitting"
                                class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-[#C5A55A] to-[#a8893f] px-5 py-2.5 text-sm font-bold uppercase tracking-wide text-black shadow-lg shadow-[#C5A55A]/20 transition hover:from-[#d4b46a] hover:to-[#b89945] disabled:opacity-40 disabled:cursor-not-allowed">
                            <span x-show="!submitting">Selesai &amp; Lihat Hasil</span>
                            <span x-show="submitting">Memproses...</span>
                            <svg x-show="!submitting" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                            </svg>
                        </button>
                    </template>
                </div>

                {{-- Hidden submit form --}}
                <form x-ref="submitForm" method="POST" action="{{ route('quiz.submit', $movie) }}" class="hidden">
                    @csrf
                    <template x-for="(letter, qid) in answers" :key="qid">
                        <input type="hidden" :name="`answers[${qid}]`" :value="letter">
                    </template>
                    <input type="hidden" name="time_seconds" :value="elapsed">
                </form>
            </div>
        </div>
    </main>

    @push('scripts')
    <script>
        function quizGame(questions) {
            return {
                questions: questions || [],
                current: 0,
                answers: {},   // { [questionId]: 'a'|'b'|'c'|'d' }
                elapsed: 0,
                timerId: null,
                submitting: false,

                init() {
                    this.timerId = setInterval(() => { this.elapsed++; }, 1000);
                },

                get answeredCount() {
                    return Object.keys(this.answers).length;
                },

                get correctCount() {
                    // Note: we don't expose correct answers client-side — this stays at 0
                    // until the server result page. Keeping the binding for forward
                    // compatibility if we ever do live feedback.
                    return 0;
                },

                selectAnswer(letter) {
                    const q = this.questions[this.current];
                    if (!q) return;
                    // First answer wins — prevents click-through changes accidentally.
                    // To allow changes, just always set. We allow it.
                    this.answers[q.id] = letter;
                },

                next() {
                    if (this.current < this.questions.length - 1) {
                        this.current++;
                    }
                },

                prev() {
                    if (this.current > 0) {
                        this.current--;
                    }
                },

                submit() {
                    if (this.submitting) return;
                    if (this.answeredCount < this.questions.length) return;
                    this.submitting = true;
                    if (this.timerId) clearInterval(this.timerId);
                    // Defer one tick so x-for hidden inputs are rendered before submit.
                    this.$nextTick(() => this.$refs.submitForm.submit());
                },

                formatTime(s) {
                    const m = Math.floor(s / 60);
                    const r = s % 60;
                    return `${String(m).padStart(2, '0')}:${String(r).padStart(2, '0')}`;
                },
            };
        }
    </script>
    @endpush
</x-layout>
