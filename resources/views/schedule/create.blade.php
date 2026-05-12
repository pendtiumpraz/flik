<x-layout title="Jadwalkan Nonton — FLiK">
    <main class="min-h-screen bg-black pt-24 pb-20">
        <div class="container mx-auto px-4 md:px-8 lg:px-16 max-w-3xl">

            {{-- ── Breadcrumb ─────────────────────────────────────── --}}
            <nav class="mb-6 text-xs text-gray-500">
                <a href="{{ route('schedule.index') }}" class="hover:text-gray-300">← Kembali ke Jadwal Nonton</a>
            </nav>

            {{-- ── Header ─────────────────────────────────────────── --}}
            <div class="text-center mb-8 md:mb-10">
                <div class="inline-flex items-center justify-center gap-2 mb-4">
                    <span class="text-[11px] md:text-xs font-bold uppercase tracking-[0.25em]" style="color: #C5A55A">
                        Save for Friday Night
                    </span>
                </div>

                <h1 class="font-heading text-2xl md:text-4xl font-bold text-white leading-tight">
                    Jadwalkan
                    <span style="background: linear-gradient(135deg, #C5A55A, #E8D5A3); -webkit-background-clip: text; background-clip: text; color: transparent;">{{ $movie->title }}</span>
                </h1>
                <p class="text-gray-400 mt-3 text-sm md:text-base max-w-xl mx-auto">
                    Pilih tanggal & jam — atau tinggal klik salah satu saran dari AI di bawah.
                    Kamu dapat pengingat 1 jam sebelumnya, plus bisa export ke kalender pribadi.
                </p>
            </div>

            {{-- ── Movie summary card ─────────────────────────────── --}}
            <div class="rounded-xl p-4 md:p-5 mb-8 flex gap-4 items-center"
                 style="background: rgba(20,18,16,0.6); border: 1px solid rgba(197,165,90,0.15)">
                <div class="w-16 sm:w-20 flex-shrink-0 rounded-lg overflow-hidden"
                     style="aspect-ratio: 2/3; background: #0a0a0a">
                    <img src="{{ $movie->effective_poster_url }}"
                         alt="{{ $movie->title }}"
                         class="w-full h-full object-cover">
                </div>
                <div class="min-w-0 flex-1">
                    <h2 class="font-heading font-semibold text-white text-base md:text-lg truncate">{{ $movie->title }}</h2>
                    @if($movie->genres->count() > 0)
                        <p class="text-xs text-gray-400 mt-1 truncate">{{ $movie->genres->pluck('name')->join(' · ') }}</p>
                    @endif
                    @if($movie->duration_seconds)
                        <p class="text-xs text-gray-500 mt-1">
                            ⏱️ {{ (int) round($movie->duration_seconds / 60) }} menit
                        </p>
                    @endif
                </div>
            </div>

            {{-- ── Validation errors ──────────────────────────────── --}}
            @if($errors->any())
                <div class="mb-6 p-4 rounded-xl"
                     style="background: rgba(220,38,38,0.08); border: 1px solid rgba(220,38,38,0.4); color: #FCA5A5">
                    <ul class="text-sm space-y-1 list-disc list-inside">
                        @foreach($errors->all() as $err)
                            <li>{{ $err }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- ── AI Suggestions panel ───────────────────────────── --}}
            @if(!empty($suggestions))
                <div class="mb-8">
                    <div class="flex items-center gap-2 mb-3">
                        <span class="text-[11px] md:text-xs font-bold uppercase tracking-[0.25em]" style="color: #C5A55A">
                            ✨ Saran Waktu dari AI
                        </span>
                    </div>
                    <p class="text-xs text-gray-500 mb-4">
                        Berdasarkan kebiasaan nontonmu + durasi & genre film ini. Klik untuk pakai.
                    </p>

                    <div x-data="{ selected: null }" class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        @foreach($suggestions as $i => $slot)
                            @php
                                try {
                                    $dt = \Illuminate\Support\Carbon::parse($slot['datetime']);
                                    $valid = true;
                                } catch (\Throwable) {
                                    $valid = false;
                                }
                            @endphp
                            @if($valid)
                                <button type="button"
                                        @click="selected = {{ $i }}; document.getElementById('scheduled_for').value = '{{ $dt->format('Y-m-d\TH:i') }}';"
                                        :class="selected === {{ $i }} ? 'ring-2' : ''"
                                        class="text-left p-4 rounded-xl transition-all hover:scale-[1.01]"
                                        style="background: rgba(197,165,90,0.06); border: 1px solid rgba(197,165,90,0.25); --tw-ring-color: #C5A55A">
                                    <div class="flex items-center gap-2 mb-2">
                                        <span class="text-[10px] uppercase tracking-wider font-bold" style="color: #C5A55A">
                                            Slot {{ $i + 1 }}
                                        </span>
                                    </div>
                                    <p class="font-heading text-white font-semibold text-sm">
                                        {{ $dt->translatedFormat('l') }}
                                    </p>
                                    <p class="text-xs text-gray-400 mt-0.5">
                                        {{ $dt->translatedFormat('d M Y') }} · {{ $dt->format('H:i') }}
                                    </p>
                                    <p class="text-xs text-gray-300 mt-2.5 leading-relaxed">
                                        {{ $slot['reason'] }}
                                    </p>
                                </button>
                            @endif
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- ── Form ───────────────────────────────────────────── --}}
            <form method="POST" action="{{ route('schedule.store', $movie) }}"
                  class="rounded-xl p-5 md:p-6"
                  style="background: rgba(20,18,16,0.6); border: 1px solid rgba(197,165,90,0.15)">
                @csrf

                <div class="mb-5">
                    <label for="scheduled_for" class="block text-xs font-bold uppercase tracking-wider mb-2" style="color: #C5A55A">
                        🗓️ Tanggal & Jam
                    </label>
                    <input type="datetime-local"
                           id="scheduled_for"
                           name="scheduled_for"
                           value="{{ old('scheduled_for', $defaultDateTime) }}"
                           min="{{ $minDateTime }}"
                           max="{{ $maxDateTime }}"
                           required
                           class="w-full px-4 py-3 rounded-lg text-white text-base focus:outline-none focus:ring-2"
                           style="background: #0a0a0a; border: 1px solid rgba(197,165,90,0.3); --tw-ring-color: #C5A55A">
                    <p class="text-[11px] text-gray-500 mt-2">
                        Maksimal 60 hari ke depan. Zona waktu: kalender perangkat kamu.
                    </p>
                </div>

                <div class="mb-6">
                    <label for="notes" class="block text-xs font-bold uppercase tracking-wider mb-2" style="color: #C5A55A">
                        📝 Catatan (opsional)
                    </label>
                    <textarea id="notes"
                              name="notes"
                              rows="3"
                              maxlength="1000"
                              placeholder="Contoh: Date night bareng Sarah, jangan lupa popcorn caramel."
                              class="w-full px-4 py-3 rounded-lg text-white text-sm focus:outline-none focus:ring-2 resize-none"
                              style="background: #0a0a0a; border: 1px solid rgba(197,165,90,0.3); --tw-ring-color: #C5A55A">{{ old('notes') }}</textarea>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <button type="submit"
                            class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg font-semibold text-sm transition-transform hover:scale-[1.02]"
                            style="background: #C5A55A; color: #0a0a0a">
                        ✓ Simpan Jadwal
                    </button>
                    <a href="{{ route('schedule.index') }}"
                       class="text-xs text-gray-400 hover:text-gray-200">
                        Batal
                    </a>
                </div>

                <p class="text-[11px] text-gray-500 mt-5 leading-relaxed">
                    📅 Setelah disimpan, kamu bisa export jadwal ini sebagai file <code class="text-gray-300">.ics</code> untuk
                    diimport ke Google Calendar, Apple Calendar, atau Outlook dari halaman jadwal nonton.
                </p>
            </form>
        </div>
    </main>
</x-layout>
