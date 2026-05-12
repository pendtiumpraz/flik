<x-layout title="Jadwal Nonton — FLiK">
    <main class="min-h-screen bg-black pt-24 pb-20">
        <div class="container mx-auto px-4 md:px-8 lg:px-16 max-w-4xl">

            {{-- ── Header ─────────────────────────────────────────── --}}
            <div class="text-center mb-10 md:mb-12">
                <div class="inline-flex items-center justify-center gap-2 mb-4">
                    <span class="text-[11px] md:text-xs font-bold uppercase tracking-[0.25em]" style="color: #C5A55A">
                        Save for Friday Night
                    </span>
                </div>

                <h1 class="font-heading text-3xl md:text-5xl font-bold text-white leading-tight">
                    Jadwal
                    <span style="background: linear-gradient(135deg, #C5A55A, #E8D5A3); -webkit-background-clip: text; background-clip: text; color: transparent;">Nonton</span>
                    Kamu
                </h1>
                <p class="text-gray-400 mt-3 text-sm md:text-base max-w-xl mx-auto">
                    Pinaakan film ke tanggal & jam tertentu. Kamu dapat pengingat 1 jam sebelum jadwal, plus bisa export ke kalender pribadi.
                </p>
            </div>

            {{-- ── Flash ──────────────────────────────────────────── --}}
            @if(session('success'))
                <div class="max-w-2xl mx-auto mb-6 p-4 rounded-xl text-center"
                     style="background: rgba(34,197,94,0.08); border: 1px solid rgba(34,197,94,0.4); color: #86EFAC">
                    <p class="text-sm">{{ session('success') }}</p>
                </div>
            @endif

            {{-- ── Upcoming list ──────────────────────────────────── --}}
            <div class="mb-6 flex items-center gap-3">
                <div class="flex-1 h-px" style="background: linear-gradient(90deg, transparent, rgba(197,165,90,0.4), transparent)"></div>
                <p class="text-[11px] md:text-xs uppercase tracking-[0.25em] font-bold" style="color: #C5A55A">
                    Akan Datang ({{ $schedules->total() }})
                </p>
                <div class="flex-1 h-px" style="background: linear-gradient(90deg, transparent, rgba(197,165,90,0.4), transparent)"></div>
            </div>

            @if($schedules->count())
                <div class="space-y-3">
                    @foreach($schedules as $schedule)
                        @php
                            $movie = $schedule->movie;
                            $poster = $movie?->effective_poster_url ?? '/images/no-poster.png';
                        @endphp
                        <div class="rounded-xl p-4 md:p-5 flex flex-col sm:flex-row gap-4"
                             style="background: rgba(20,18,16,0.6); border: 1px solid rgba(197,165,90,0.15)">

                            {{-- Poster --}}
                            <a href="{{ $movie ? route('movies.show', $movie->slug) : '#' }}"
                               class="block w-24 sm:w-20 flex-shrink-0 rounded-lg overflow-hidden"
                               style="aspect-ratio: 2/3; background: #0a0a0a">
                                <img src="{{ $poster }}"
                                     alt="{{ $movie?->title }}"
                                     class="w-full h-full object-cover"
                                     loading="lazy">
                            </a>

                            {{-- Body --}}
                            <div class="flex-1 min-w-0">
                                <div class="flex items-start justify-between gap-3 flex-wrap">
                                    <div class="min-w-0">
                                        <h2 class="font-heading text-base md:text-lg font-semibold text-white truncate">
                                            {{ $movie?->title ?? 'Film dihapus' }}
                                        </h2>
                                        <p class="text-xs text-gray-400 mt-1">
                                            🗓️ {{ $schedule->scheduled_for->translatedFormat('l, d M Y') }}
                                            <span class="mx-1">·</span>
                                            🕐 {{ $schedule->scheduled_for->format('H:i') }}
                                            <span class="mx-1">·</span>
                                            <span style="color:#E8D5A3">{{ $schedule->scheduled_for->diffForHumans() }}</span>
                                        </p>
                                        @if($schedule->notes)
                                            <p class="text-sm text-gray-300 mt-2 italic">"{{ $schedule->notes }}"</p>
                                        @endif
                                        @if($schedule->reminder_sent_at)
                                            <span class="inline-block mt-2 text-[10px] px-2 py-0.5 rounded-full"
                                                  style="background: rgba(34,197,94,0.1); color: #86EFAC; border: 1px solid rgba(34,197,94,0.3)">
                                                ✓ Pengingat sudah dikirim
                                            </span>
                                        @endif
                                    </div>
                                </div>

                                {{-- Actions --}}
                                <div class="mt-4 flex flex-wrap items-center gap-2">
                                    <a href="{{ route('schedule.ics', $schedule) }}"
                                       class="inline-flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-lg transition-colors"
                                       style="background: rgba(197,165,90,0.1); border: 1px solid rgba(197,165,90,0.4); color: #E8D5A3"
                                       title="Export ke kalender (.ics)">
                                        📅 Export Kalender
                                    </a>

                                    @if($movie)
                                        <a href="{{ route('movies.show', $movie->slug) }}"
                                           class="inline-flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-lg transition-colors hover:bg-gray-800"
                                           style="background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.1); color: #ddd">
                                            Lihat Film
                                        </a>
                                    @endif

                                    <form method="POST" action="{{ route('schedule.destroy', $schedule) }}"
                                          onsubmit="return confirm('Batalkan jadwal nonton ini?');"
                                          class="inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit"
                                                class="inline-flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-lg transition-colors hover:bg-red-900/40"
                                                style="background: rgba(220,38,38,0.08); border: 1px solid rgba(220,38,38,0.3); color: #FCA5A5">
                                            ✕ Batalkan
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="mt-6">
                    {{ $schedules->links() }}
                </div>
            @else
                <div class="text-center py-16 rounded-xl"
                     style="background: rgba(20,18,16,0.5); border: 1px solid rgba(197,165,90,0.15)">
                    <div class="text-5xl mb-3">🗓️</div>
                    <p class="text-gray-300 font-semibold">Belum ada jadwal nonton</p>
                    <p class="text-gray-500 text-xs mt-1.5 max-w-sm mx-auto">
                        Buka detail film mana saja lalu klik <em>Save for Friday Night</em> untuk pinaakan ke kalender.
                    </p>
                    <a href="{{ route('velflix.index') }}"
                       class="inline-block mt-5 text-xs font-semibold px-4 py-2 rounded-lg"
                       style="background: #C5A55A; color: #0a0a0a">
                        Jelajahi Film
                    </a>
                </div>
            @endif

            {{-- ── Past sessions ──────────────────────────────────── --}}
            @if($past->count() > 0)
                <div class="mt-12">
                    <div class="mb-4 flex items-center gap-3">
                        <div class="flex-1 h-px" style="background: linear-gradient(90deg, transparent, rgba(120,120,120,0.4), transparent)"></div>
                        <p class="text-[11px] uppercase tracking-[0.25em] font-bold text-gray-500">
                            Riwayat (30 hari terakhir)
                        </p>
                        <div class="flex-1 h-px" style="background: linear-gradient(90deg, transparent, rgba(120,120,120,0.4), transparent)"></div>
                    </div>

                    <ul class="space-y-2 opacity-70">
                        @foreach($past as $old)
                            <li class="text-sm text-gray-400 flex items-center justify-between gap-2 px-3 py-2 rounded-lg"
                                style="background: rgba(20,18,16,0.4); border: 1px solid rgba(255,255,255,0.04)">
                                <span class="truncate">
                                    @if($old->watched_at) ✓ @else ⏰ @endif
                                    {{ $old->movie?->title ?? '(film dihapus)' }}
                                    <span class="text-gray-600 ml-1">·
                                        {{ $old->scheduled_for->translatedFormat('d M Y, H:i') }}
                                    </span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </main>
</x-layout>
