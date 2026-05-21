@php
    $prefill = $prefillCode ?? '';
@endphp

<x-layout title="Bergabung ke Watch Party — FLiK">
    <div class="min-h-screen bg-black text-white pt-20 pb-16">
        <div class="container mx-auto px-4 md:px-8 max-w-lg">

            {{-- ── Header ──────────────────────────────────────── --}}
            <div class="mb-8 text-center">
                <div class="text-xs uppercase tracking-widest text-[#C5A55A] mb-1">Watch Party</div>
                <h1 class="font-heading text-3xl md:text-4xl font-bold">Bergabung ke party</h1>
                <p class="text-gray-400 mt-2">Masukkan kode 8 karakter yang dibagikan host kamu.</p>
            </div>

            {{-- ── Errors ───────────────────────────────────── --}}
            @if ($errors->any() || session('error'))
                <div class="mb-5 p-4 rounded-lg bg-red-500/10 border border-red-500/30 text-sm text-red-300">
                    @if(session('error'))
                        <div>{{ session('error') }}</div>
                    @endif
                    @if($errors->any())
                        <ul class="list-disc list-inside space-y-1">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif

            @unless($pusherEnabled ?? false)
                <div class="mb-6 rounded-xl border border-yellow-500/40 bg-yellow-500/10 text-yellow-200 px-4 py-3 text-xs">
                    Real-time sync membutuhkan setup Pusher di server. Playback lokal tetap berfungsi.
                </div>
            @endunless

            {{-- ── Form ──────────────────────────────────────── --}}
            <form method="POST" action="{{ route('watch-party.join.action') }}"
                  class="rounded-2xl p-6 md:p-8"
                  style="background: rgba(15,15,15,0.7); border: 1px solid rgba(197,165,90,0.18)"
                  x-data="{ code: '{{ $prefill }}' }">
                @csrf

                <label for="room_code" class="block text-xs uppercase tracking-widest text-[#C5A55A] font-semibold mb-3 text-center">Kode room</label>
                <input type="text"
                       name="room_code"
                       id="room_code"
                       autocomplete="off"
                       autocapitalize="characters"
                       spellcheck="false"
                       inputmode="latin"
                       maxlength="8"
                       minlength="8"
                       required
                       x-model="code"
                       x-init="$watch('code', v => code = (v || '').toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 8))"
                       :value="code"
                       placeholder="ABCD1234"
                       class="w-full px-4 py-5 rounded-xl bg-black/60 border-2 border-white/10 text-center font-mono text-3xl md:text-4xl text-white tracking-[0.4em] focus:outline-none focus:border-[#C5A55A] transition-colors uppercase"
                       style="caret-color: #C5A55A">

                <p class="text-[11px] text-gray-500 mt-3 text-center">Enter the 8-character code your host shared</p>

                <button type="submit"
                        class="mt-6 w-full inline-flex items-center justify-center gap-2 px-5 py-3.5 rounded-lg text-sm font-bold text-black hover:opacity-95 transition-opacity"
                        style="background: linear-gradient(135deg, #C5A55A, #E8D5A3)">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M11 19l-7-7 7-7M4 12h16"/></svg>
                    Join Party
                </button>
            </form>

            {{-- ── Alternate path: host a new party ──────────────── --}}
            <div class="mt-8 text-center">
                <div class="inline-flex items-center gap-3 text-xs text-gray-500">
                    <span class="h-px w-12 bg-white/10"></span>
                    <span class="uppercase tracking-widest">atau</span>
                    <span class="h-px w-12 bg-white/10"></span>
                </div>
                <p class="mt-4 text-sm text-gray-400">Ingin menjadi host?</p>
                <a href="{{ route('velflix.index') }}"
                   class="mt-2 inline-flex items-center gap-2 text-sm font-semibold text-[#C5A55A] hover:text-[#E8D5A3] transition-colors">
                    <span>Pilih film &amp; buat party baru</span>
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </a>
            </div>
        </div>
    </div>
</x-layout>
