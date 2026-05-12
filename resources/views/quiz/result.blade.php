<x-layout :title="'Hasil Quiz · ' . $movie->title . ' — FLiK'">
    <main class="min-h-screen bg-[#0a0a0a] text-white pt-20 pb-16">
        <div class="mx-auto w-full max-w-3xl px-4 sm:px-6">

            {{-- Score hero --}}
            <section class="relative overflow-hidden rounded-3xl border border-[#C5A55A]/20 p-6 sm:p-10 text-center"
                     style="background: linear-gradient(135deg, rgba(197,165,90,0.10) 0%, rgba(10,10,10,0.95) 60%);">

                <span class="inline-block rounded-full border border-[#C5A55A]/30 bg-[#C5A55A]/10 px-3 py-1 text-[11px] font-semibold uppercase tracking-widest text-[#C5A55A]">
                    Hasil Quiz
                </span>

                <h1 class="font-heading mt-3 text-2xl sm:text-3xl font-bold">
                    {{ $movie->title }}
                </h1>

                @if ($isPerfect)
                    <p class="mt-1 text-sm text-[#C5A55A] font-semibold uppercase tracking-wider">
                        Perfect Score! Bonus +{{ 50 }} XP / +{{ 25 }} koin
                    </p>
                @elseif ($score >= 70)
                    <p class="mt-1 text-sm text-emerald-400 font-medium">
                        Mantap! Hampir sempurna.
                    </p>
                @elseif ($score >= 40)
                    <p class="mt-1 text-sm text-yellow-400 font-medium">
                        Lumayan — coba lagi untuk yang lebih tinggi.
                    </p>
                @else
                    <p class="mt-1 text-sm text-red-400 font-medium">
                        Tonton ulang filmnya, lalu balas lagi.
                    </p>
                @endif

                <div class="mt-6 flex items-baseline justify-center gap-2">
                    <span class="font-heading text-6xl sm:text-7xl font-bold bg-gradient-to-r from-[#C5A55A] to-[#E8D5A3] bg-clip-text text-transparent">
                        {{ $score }}
                    </span>
                    <span class="text-2xl text-gray-500 font-light">/100</span>
                </div>

                <p class="mt-2 text-sm text-gray-400">
                    <span class="text-white font-semibold">{{ $correct }}</span> dari
                    <span class="text-white font-semibold">{{ $total }}</span> jawaban benar
                    @if ($attempt->time_seconds > 0)
                        · {{ floor($attempt->time_seconds / 60) }}m {{ $attempt->time_seconds % 60 }}s
                    @endif
                </p>

                {{-- Rewards strip --}}
                <div class="mt-6 grid grid-cols-3 gap-2 sm:gap-3">
                    <div class="rounded-xl border border-white/5 bg-white/[0.03] p-3">
                        <div class="text-[10px] uppercase tracking-wider text-gray-500">XP</div>
                        <div class="font-heading mt-1 text-xl sm:text-2xl font-bold text-[#C5A55A]">
                            +{{ $xpGained }}
                        </div>
                    </div>
                    <div class="rounded-xl border border-white/5 bg-white/[0.03] p-3">
                        <div class="text-[10px] uppercase tracking-wider text-gray-500">Koin</div>
                        <div class="font-heading mt-1 text-xl sm:text-2xl font-bold text-[#C5A55A]">
                            +{{ $coinsGained }}
                        </div>
                    </div>
                    <div class="rounded-xl border border-white/5 bg-white/[0.03] p-3">
                        <div class="text-[10px] uppercase tracking-wider text-gray-500">Rank</div>
                        <div class="font-heading mt-1 text-xl sm:text-2xl font-bold text-white">
                            {{ $rank !== null ? '#' . $rank : '—' }}
                        </div>
                    </div>
                </div>

                <div class="mt-6 flex flex-wrap items-center justify-center gap-2">
                    <a href="{{ route('quiz.start', $movie) }}"
                       class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-[#C5A55A] to-[#a8893f] px-5 py-2.5 text-sm font-bold uppercase tracking-wide text-black shadow-lg shadow-[#C5A55A]/20 transition hover:from-[#d4b46a] hover:to-[#b89945]">
                        Coba Lagi
                    </a>
                    <a href="{{ route('quiz.leaderboard', $movie) }}"
                       class="inline-flex items-center gap-1.5 rounded-xl border border-white/10 bg-white/[0.03] px-5 py-2.5 text-sm font-semibold text-gray-200 transition hover:border-[#C5A55A]/40 hover:text-[#C5A55A]">
                        Leaderboard Penuh
                    </a>
                    <a href="{{ route('movies.show', $movie) }}"
                       class="inline-flex items-center gap-1.5 rounded-xl border border-white/10 bg-white/[0.03] px-5 py-2.5 text-sm font-semibold text-gray-200 transition hover:border-white/30">
                        Kembali ke Film
                    </a>
                </div>
            </section>

            {{-- Per-question breakdown --}}
            <section class="mt-10">
                <h2 class="font-heading text-lg sm:text-xl font-semibold mb-4">
                    <span class="text-[#C5A55A]">Review</span> Jawabanmu
                </h2>

                <div class="space-y-3">
                    @foreach ($results as $i => $r)
                        <article class="rounded-2xl border p-4 sm:p-5
                                {{ $r['is_correct'] ? 'border-emerald-500/30 bg-emerald-500/5' : 'border-red-500/30 bg-red-500/5' }}">
                            <div class="flex items-start gap-3">
                                <span class="flex-shrink-0 inline-flex items-center justify-center w-7 h-7 rounded-md text-xs font-bold
                                        {{ $r['is_correct'] ? 'bg-emerald-500/20 text-emerald-300' : 'bg-red-500/20 text-red-300' }}">
                                    {{ $r['is_correct'] ? '✓' : '✗' }}
                                </span>
                                <div class="flex-1 min-w-0">
                                    <p class="text-[11px] uppercase tracking-wider text-gray-500">
                                        Pertanyaan {{ $i + 1 }}
                                    </p>
                                    <p class="font-semibold leading-snug mt-0.5">{{ $r['question'] }}</p>

                                    <div class="mt-3 space-y-1.5">
                                        @foreach ($r['options'] as $letter => $text)
                                            @php
                                                $isCorrect = $letter === $r['correct_option'];
                                                $isGiven = $letter === $r['given'];
                                            @endphp
                                            <div class="flex items-start gap-2 text-sm px-3 py-2 rounded-lg
                                                {{ $isCorrect ? 'bg-emerald-500/10 text-emerald-200' : ($isGiven ? 'bg-red-500/10 text-red-200' : 'text-gray-400') }}">
                                                <span class="flex-shrink-0 font-bold uppercase text-xs mt-0.5">{{ $letter }}.</span>
                                                <span class="flex-1">{{ $text }}</span>
                                                @if ($isCorrect)
                                                    <span class="text-[10px] uppercase tracking-wider text-emerald-400 font-semibold">Benar</span>
                                                @elseif ($isGiven)
                                                    <span class="text-[10px] uppercase tracking-wider text-red-400 font-semibold">Jawabanmu</span>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>

                                    @if (!empty($r['explanation']))
                                        <div class="mt-3 rounded-lg border border-[#C5A55A]/20 bg-[#C5A55A]/5 px-3 py-2 text-xs text-gray-300">
                                            <span class="text-[#C5A55A] font-semibold">Catatan:</span> {{ $r['explanation'] }}
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>

            {{-- Top 10 leaderboard preview --}}
            <section class="mt-10">
                <h2 class="font-heading text-lg sm:text-xl font-semibold mb-4 flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-[#C5A55A]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/>
                    </svg>
                    Top 10 Pemain
                </h2>

                @if ($leaderboard->isEmpty())
                    <p class="text-sm text-gray-500">Belum ada pemain lain. Kamu yang pertama!</p>
                @else
                    <div class="overflow-hidden rounded-2xl border border-white/5 bg-white/[0.02]">
                        <table class="w-full text-sm">
                            <thead class="bg-white/[0.02] text-[10px] uppercase tracking-widest text-gray-500">
                                <tr>
                                    <th class="px-4 py-2.5 text-left">#</th>
                                    <th class="px-4 py-2.5 text-left">Pemain</th>
                                    <th class="px-4 py-2.5 text-right">Skor</th>
                                    <th class="px-4 py-2.5 text-right">Waktu</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($leaderboard as $i => $row)
                                    <tr class="border-t border-white/5 {{ $row->user_id === auth()->id() ? 'bg-[#C5A55A]/10' : '' }}">
                                        <td class="px-4 py-2.5 font-bold text-gray-400">
                                            @if ($i === 0)
                                                <span class="text-yellow-400">1</span>
                                            @elseif ($i === 1)
                                                <span class="text-gray-300">2</span>
                                            @elseif ($i === 2)
                                                <span class="text-amber-700">3</span>
                                            @else
                                                {{ $i + 1 }}
                                            @endif
                                        </td>
                                        <td class="px-4 py-2.5 truncate">
                                            {{ $row->name }}
                                            @if ($row->user_id === auth()->id())
                                                <span class="ml-1 text-[10px] uppercase tracking-wider text-[#C5A55A]">(kamu)</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-2.5 text-right font-mono font-semibold text-[#C5A55A]">
                                            {{ $row->best_score }}
                                        </td>
                                        <td class="px-4 py-2.5 text-right text-gray-500 text-xs">
                                            @if ($row->best_time > 0)
                                                {{ floor($row->best_time / 60) }}m {{ $row->best_time % 60 }}s
                                            @else
                                                —
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        </div>
    </main>
</x-layout>
