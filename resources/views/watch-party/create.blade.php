@php
    /** @var \App\Models\Movie $movie */
    $poster = $movie->poster_url ?? null;
    $movieKey = $movie->slug ?? $movie->id;
@endphp

<x-layout :title="'Mulai Watch Party — ' . $movie->title">
    <div class="min-h-screen bg-black text-white pt-20 pb-16">
        <div class="container mx-auto px-4 md:px-8 max-w-3xl">

            {{-- ── Header ──────────────────────────────────────── --}}
            <div class="mb-8">
                <div class="text-xs uppercase tracking-widest text-[#C5A55A] mb-1">Watch Party</div>
                <h1 class="font-heading text-3xl md:text-4xl font-bold">Mulai sesi nonton bareng</h1>
                <p class="text-gray-400 mt-2 max-w-xl">Buat room privat untuk menonton film ini bersama teman — playback host akan otomatis menyinkron ke semua peserta.</p>
            </div>

            @unless($pusherEnabled ?? false)
                <div class="mb-6 rounded-xl border border-yellow-500/40 bg-yellow-500/10 text-yellow-200 px-5 py-4 flex items-start gap-3">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 mt-0.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 9v4M12 17h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                    </svg>
                    <div class="text-sm">
                        Real-time sync membutuhkan setup Pusher. Kamu masih bisa membuat room, tapi peserta lain belum akan menerima sinkronisasi playback otomatis.
                    </div>
                </div>
            @endunless

            {{-- ── Movie summary card ─────────────────────────── --}}
            <div class="rounded-2xl overflow-hidden mb-8"
                 style="background: linear-gradient(135deg, rgba(20,18,16,0.85), rgba(10,10,10,0.95)); border: 1px solid rgba(197,165,90,0.2)">
                <div class="flex gap-4 p-4 md:p-5">
                    <div class="flex-shrink-0 w-20 md:w-28 aspect-[2/3] rounded-lg overflow-hidden bg-[#1a1a1a]">
                        @if($poster)
                            <img src="{{ $poster }}" alt="{{ $movie->title }}" class="w-full h-full object-cover" loading="lazy">
                        @else
                            <div class="w-full h-full flex items-center justify-center text-[#C5A55A]/40 text-xs font-bold">FLiK</div>
                        @endif
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="text-[10px] uppercase tracking-widest text-[#C5A55A]/80 mb-1">Film yang akan ditonton</div>
                        <h2 class="font-heading text-lg md:text-2xl font-bold text-white leading-tight">{{ $movie->title }}</h2>
                        @if($movie->release_date)
                            <div class="text-xs text-gray-500 mt-1">{{ \Carbon\Carbon::parse($movie->release_date)->year }}</div>
                        @endif
                        @if($movie->relationLoaded('genres') && $movie->genres->isNotEmpty())
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                @foreach($movie->genres->take(4) as $genre)
                                    <span class="inline-block px-2 py-0.5 text-[10px] rounded font-semibold"
                                          style="background: rgba(197,165,90,0.12); color: #C5A55A; border: 1px solid rgba(197,165,90,0.3)">
                                        {{ $genre->name }}
                                    </span>
                                @endforeach
                            </div>
                        @endif
                        @if($movie->overview)
                            <p class="mt-3 text-xs text-gray-400 line-clamp-2">{{ $movie->overview }}</p>
                        @endif
                    </div>
                </div>
            </div>

            {{-- ── Errors ────────────────────────────────────── --}}
            @if ($errors->any())
                <div class="mb-5 p-4 rounded-lg bg-red-500/10 border border-red-500/30 text-sm text-red-300">
                    <ul class="list-disc list-inside space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- ── Form ──────────────────────────────────────── --}}
            <form method="POST" action="{{ route('watch-party.create') }}"
                  class="space-y-5 rounded-2xl p-6 md:p-7"
                  style="background: rgba(15,15,15,0.7); border: 1px solid rgba(197,165,90,0.18)"
                  x-data="{ isPublic: {{ old('is_public') ? 'true' : 'false' }} }">
                @csrf
                <input type="hidden" name="movie_id" value="{{ $movie->id }}">

                {{-- Party name (optional) --}}
                <div>
                    <label for="party_name" class="block text-xs uppercase tracking-widest text-[#C5A55A] font-semibold mb-2">Nama room <span class="text-gray-500 normal-case tracking-normal text-[11px]">(opsional)</span></label>
                    <input type="text" name="party_name" id="party_name"
                           value="{{ old('party_name') }}"
                           maxlength="60"
                           placeholder="Misal: Geng Nobar Sabtu Malam"
                           class="w-full px-4 py-2.5 rounded-lg bg-black/50 border border-white/10 text-sm text-white placeholder-gray-600 focus:outline-none focus:border-[#C5A55A] transition-colors">
                </div>

                {{-- Max members --}}
                <div>
                    <label for="max_members" class="block text-xs uppercase tracking-widest text-[#C5A55A] font-semibold mb-2">Maks peserta</label>
                    <div class="flex items-center gap-3">
                        <input type="number" name="max_members" id="max_members"
                               value="{{ old('max_members', 5) }}"
                               min="2" max="20" step="1"
                               class="w-24 px-3 py-2.5 rounded-lg bg-black/50 border border-white/10 text-sm text-white font-mono focus:outline-none focus:border-[#C5A55A] transition-colors">
                        <span class="text-xs text-gray-500">Antara 2 hingga 20 orang (termasuk kamu sebagai host)</span>
                    </div>
                </div>

                {{-- Public toggle --}}
                <div class="flex items-start gap-3">
                    <button type="button" role="switch"
                            @click="isPublic = !isPublic"
                            :aria-checked="isPublic.toString()"
                            :class="isPublic ? 'bg-[#C5A55A]' : 'bg-gray-700'"
                            class="relative inline-flex h-6 w-11 shrink-0 mt-0.5 rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-[#C5A55A]/60">
                        <span :class="isPublic ? 'translate-x-5' : 'translate-x-0.5'"
                              class="inline-block h-5 w-5 transform rounded-full bg-white shadow transition-transform self-center"></span>
                    </button>
                    <input type="hidden" name="is_public" :value="isPublic ? '1' : '0'">
                    <div>
                        <div class="text-sm font-semibold text-white">Room publik</div>
                        <div class="text-xs text-gray-500 mt-0.5">Siapa pun dengan kode 8 karakter bisa bergabung. Matikan untuk hanya menerima undangan langsung.</div>
                    </div>
                </div>

                {{-- Scheduled time --}}
                <div>
                    <label for="scheduled_for" class="block text-xs uppercase tracking-widest text-[#C5A55A] font-semibold mb-2">Jadwalkan <span class="text-gray-500 normal-case tracking-normal text-[11px]">(opsional)</span></label>
                    <input type="datetime-local" name="scheduled_for" id="scheduled_for"
                           value="{{ old('scheduled_for') }}"
                           class="w-full px-4 py-2.5 rounded-lg bg-black/50 border border-white/10 text-sm text-white focus:outline-none focus:border-[#C5A55A] transition-colors">
                    <p class="text-[11px] text-gray-500 mt-1">Kosongkan untuk langsung dimulai sekarang.</p>
                </div>

                {{-- Invite message --}}
                <div>
                    <label for="invite_message" class="block text-xs uppercase tracking-widest text-[#C5A55A] font-semibold mb-2">Pesan undangan <span class="text-gray-500 normal-case tracking-normal text-[11px]">(opsional)</span></label>
                    <textarea name="invite_message" id="invite_message" rows="3"
                              maxlength="500"
                              placeholder="Halo! Ayo nonton bareng film ini, kode room akan dibagikan setelah dibuat."
                              class="w-full px-4 py-2.5 rounded-lg bg-black/50 border border-white/10 text-sm text-white placeholder-gray-600 resize-none focus:outline-none focus:border-[#C5A55A] transition-colors">{{ old('invite_message') }}</textarea>
                </div>

                {{-- CTA row --}}
                <div class="flex flex-wrap items-center justify-end gap-3 pt-3 border-t border-white/5">
                    <a href="{{ route('movies.show', $movieKey) }}"
                       class="px-4 py-2.5 rounded-lg text-sm font-semibold text-gray-300 hover:text-white transition-colors">
                        Batalkan
                    </a>
                    <button type="submit"
                            class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg text-sm font-bold text-black hover:opacity-95 transition-opacity"
                            style="background: linear-gradient(135deg, #C5A55A, #E8D5A3)">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M6.3 2.841A1.5 1.5 0 004 4.11V15.89a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z"/></svg>
                        Start Party
                    </button>
                </div>
            </form>

            <div class="mt-6 text-center text-xs text-gray-500">
                Sudah punya kode room? <a href="{{ route('watch-party.join.form') }}" class="text-[#C5A55A] hover:text-[#E8D5A3]">Bergabung ke party</a>.
            </div>
        </div>
    </div>
</x-layout>
