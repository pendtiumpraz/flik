<x-layout :title="'Leaderboard Quiz · ' . $movie->title . ' — FLiK'">
    <main class="min-h-screen bg-[#0a0a0a] text-white pt-20 pb-16">
        <div class="mx-auto w-full max-w-3xl px-4 sm:px-6">

            <a href="{{ route('movies.show', $movie) }}"
               class="inline-flex items-center gap-1.5 text-xs text-gray-400 hover:text-[#C5A55A] transition">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                </svg>
                Kembali ke {{ $movie->title }}
            </a>

            <header class="mt-3 mb-6 flex items-baseline justify-between gap-4">
                <h1 class="font-heading text-2xl sm:text-3xl font-bold">
                    <span class="text-[#C5A55A]">Leaderboard</span> Quiz
                </h1>
                @if ($myRank !== null)
                    <span class="rounded-full border border-[#C5A55A]/30 bg-[#C5A55A]/10 px-3 py-1 text-[11px] font-semibold uppercase tracking-widest text-[#C5A55A]">
                        Peringkatmu · #{{ $myRank }}
                    </span>
                @endif
            </header>

            <p class="text-sm text-gray-400 mb-6">{{ $movie->title }} · Top {{ $leaderboard->count() }} pemain berdasarkan skor terbaik.</p>

            <a href="{{ route('quiz.start', $movie) }}"
               class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-[#C5A55A] to-[#a8893f] px-5 py-2.5 text-sm font-bold uppercase tracking-wide text-black shadow-lg shadow-[#C5A55A]/20 transition hover:from-[#d4b46a] hover:to-[#b89945] mb-6">
                Main Quiz Sekarang
            </a>

            @if ($leaderboard->isEmpty())
                <div class="rounded-2xl border border-white/5 bg-white/[0.02] p-10 text-center">
                    <p class="text-sm text-gray-500">Belum ada yang main quiz untuk film ini.</p>
                    <p class="mt-1 text-xs text-gray-600">Jadilah yang pertama di leaderboard!</p>
                </div>
            @else
                <div class="overflow-hidden rounded-2xl border border-white/5 bg-white/[0.02]">
                    <table class="w-full text-sm">
                        <thead class="bg-white/[0.02] text-[10px] uppercase tracking-widest text-gray-500">
                            <tr>
                                <th class="px-4 py-3 text-left w-12">#</th>
                                <th class="px-4 py-3 text-left">Pemain</th>
                                <th class="px-4 py-3 text-right">Skor</th>
                                <th class="px-4 py-3 text-right">Waktu</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($leaderboard as $i => $row)
                                <tr class="border-t border-white/5 {{ $row->user_id === auth()->id() ? 'bg-[#C5A55A]/10' : '' }}">
                                    <td class="px-4 py-3 font-bold">
                                        @if ($i === 0)
                                            <span class="text-yellow-400">1</span>
                                        @elseif ($i === 1)
                                            <span class="text-gray-300">2</span>
                                        @elseif ($i === 2)
                                            <span class="text-amber-700">3</span>
                                        @else
                                            <span class="text-gray-500">{{ $i + 1 }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 truncate">
                                        {{ $row->name }}
                                        @if ($row->user_id === auth()->id())
                                            <span class="ml-1 text-[10px] uppercase tracking-wider text-[#C5A55A]">(kamu)</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right font-mono font-semibold text-[#C5A55A]">
                                        {{ $row->best_score }}
                                    </td>
                                    <td class="px-4 py-3 text-right text-gray-500 text-xs">
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
        </div>
    </main>
</x-layout>
