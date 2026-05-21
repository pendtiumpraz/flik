<x-layout>
    {{-- Gift redeem form (auth-required)
         ──────────────────────────────────
         Accepts either the raw 12-char code or the human-friendly
         GIFT-XXXX-XXXX-XXXX form — the service strips dashes/prefix
         before lookup, so any paste shape works.
    --}}
    <div class="min-h-screen bg-black flex items-center justify-center py-20 px-4">
        <div class="w-full max-w-lg">

            {{-- Hero --}}
            <div class="text-center mb-8">
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-full mb-4"
                     style="background:linear-gradient(135deg,rgba(197,165,90,0.2),rgba(197,165,90,0.05));border:1px solid rgba(197,165,90,0.3)">
                    <x-icon name="gift" :size="32" class="text-[#C5A55A]" />
                </div>
                <h1 class="font-heading text-3xl font-bold text-white">Tukarkan Hadiah</h1>
                <p class="text-gray-400 text-sm mt-2">
                    Masukkan kode hadiah FLiK yang kamu terima.
                </p>
            </div>

            {{-- Flash messages --}}
            @if(session('error'))
                <div class="mb-4 px-4 py-3 rounded-lg text-sm"
                     style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:#fca5a5">
                    {{ session('error') }}
                </div>
            @endif
            @if(session('success'))
                <div class="mb-4 px-4 py-3 rounded-lg text-sm"
                     style="background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.3);color:#86efac">
                    {{ session('success') }}
                </div>
            @endif

            {{-- Form --}}
            <form action="{{ route('gift.redeem') }}" method="POST"
                  class="rounded-2xl p-6 md:p-8"
                  style="background:#1a1a1a;border:1px solid #2a2a2a">
                @csrf

                <label for="code" class="block text-xs font-semibold uppercase tracking-wider text-gray-400 mb-3">
                    Kode Hadiah
                </label>

                <input type="text" id="code" name="code" required autofocus
                       value="{{ old('code') }}"
                       maxlength="32"
                       autocomplete="off"
                       autocapitalize="characters"
                       spellcheck="false"
                       placeholder="GIFT-XXXX-XXXX-XXXX"
                       class="w-full px-4 py-4 rounded-xl text-center text-lg md:text-xl font-bold text-white tracking-widest uppercase"
                       style="background:#0f0f0f;border:2px solid #2a2a2a;font-family:'Outfit',monospace;letter-spacing:0.15em"
                       oninput="this.value = this.value.toUpperCase()">

                <p class="text-xs text-gray-500 mt-3 text-center">
                    Kode berisi 12 karakter (huruf besar + angka).
                    Tanda <code style="color:#C5A55A">-</code> dan spasi otomatis diabaikan.
                </p>

                @error('code')
                    <p class="text-xs mt-2 text-center" style="color:#fca5a5">{{ $message }}</p>
                @enderror

                <button type="submit"
                        class="w-full mt-6 py-3.5 rounded-xl text-sm font-bold text-black transition-all hover:scale-[1.02] active:scale-[0.99]"
                        style="background:linear-gradient(90deg,#C5A55A,#E8D5A3)">
                    Tukarkan Sekarang
                </button>
            </form>

            {{-- Helper line --}}
            <div class="mt-6 text-center">
                <p class="text-xs text-gray-500">
                    Tidak punya kode?
                    <a href="{{ route('plans.index') }}" class="text-[#C5A55A] hover:underline">
                        Lihat paket berlangganan
                    </a>
                </p>
            </div>
        </div>
    </div>
</x-layout>
