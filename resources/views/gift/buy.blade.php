<x-layout>
    {{-- Gift purchase form
         ───────────────────
         Public-facing buyer page. Auth-optional — anonymous purchasers
         still need to supply purchaser_email so Midtrans can attach the
         receipt. The POST submits to /gift/purchase/{plan} which mints
         a Snap token and renders gift/checkout.blade.php.

         Validation mirrors GiftSubscriptionController::purchase():
           - purchaser_email  required|email
           - recipient_name   nullable|string|max:120
           - recipient_email  nullable|email
           - personal_message nullable|string|max:2000  (we cap UI at 300
             chars for B2C taste; the controller will accept up to 2000)
    --}}
    <div class="min-h-screen bg-black py-20 px-4">
        <div class="mx-auto max-w-2xl">

            {{-- Hero --}}
            <div class="text-center mb-10">
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-full mb-4"
                     style="background:linear-gradient(135deg,rgba(197,165,90,0.2),rgba(197,165,90,0.05));border:1px solid rgba(197,165,90,0.3)">
                    <x-icon name="gift" :size="32" class="text-[#C5A55A]" />
                </div>
                <h1 class="font-heading text-3xl md:text-4xl font-bold text-white">
                    Hadiahkan FLiK
                </h1>
                <p class="text-gray-400 text-sm md:text-base mt-2 max-w-md mx-auto">
                    Beri akses Cinema Indonesia premium kepada keluarga, teman, atau tim kerjamu.
                </p>
            </div>

            {{-- Plan summary --}}
            <div class="rounded-2xl overflow-hidden mb-6"
                 style="background:#1a1a1a;border:1px solid #2a2a2a">
                <div class="p-5 flex items-center justify-between"
                     style="background:linear-gradient(135deg,rgba(197,165,90,0.15),rgba(197,165,90,0.05));border-bottom:1px solid #2a2a2a">
                    <div>
                        <p class="text-[10px] uppercase tracking-wider text-[#C5A55A]/80 font-semibold">Paket Hadiah</p>
                        <h2 class="text-lg font-semibold text-white mt-0.5">FLiK {{ $plan->name }}</h2>
                        <p class="text-xs text-gray-400 mt-0.5">{{ (int) ($plan->duration_days ?? 30) }} hari akses penuh</p>
                    </div>
                    <div class="text-right">
                        <p class="text-[10px] uppercase tracking-wider text-gray-500">Harga</p>
                        <p class="text-2xl font-bold text-[#C5A55A]" style="font-family:'Outfit',sans-serif">
                            Rp {{ number_format((int) $plan->price, 0, ',', '.') }}
                        </p>
                    </div>
                </div>
            </div>

            {{-- Flash messages --}}
            @if(session('error'))
                <div class="mb-4 px-4 py-3 rounded-lg text-sm"
                     style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:#fca5a5">
                    {{ session('error') }}
                </div>
            @endif

            {{-- Buy form --}}
            <form action="{{ route('gift.purchase', $plan) }}" method="POST"
                  class="rounded-2xl p-6 md:p-8 space-y-5"
                  style="background:#1a1a1a;border:1px solid #2a2a2a"
                  x-data="{ message: '{{ old('personal_message', '') }}' }">
                @csrf

                {{-- Purchaser email (prefilled when logged in) --}}
                <div>
                    <label for="purchaser_email" class="block text-xs font-semibold uppercase tracking-wider text-gray-400 mb-2">
                        Email Pembeli <span style="color:#ef4444">*</span>
                    </label>
                    <input type="email" id="purchaser_email" name="purchaser_email" required
                           value="{{ old('purchaser_email', $user?->email ?? '') }}"
                           placeholder="kamu@email.com"
                           class="w-full px-4 py-3 rounded-lg text-sm text-white"
                           style="background:#0f0f0f;border:1px solid #2a2a2a;font-family:'Inter',sans-serif">
                    <p class="text-xs text-gray-500 mt-1.5">Tanda terima pembayaran akan dikirim ke alamat ini.</p>
                    @error('purchaser_email')
                        <p class="text-xs mt-1.5" style="color:#fca5a5">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Divider --}}
                <div class="flex items-center gap-3 pt-2">
                    <hr class="flex-1" style="border-color:#2a2a2a">
                    <span class="text-[10px] uppercase tracking-wider text-gray-500 font-semibold">Penerima Hadiah</span>
                    <hr class="flex-1" style="border-color:#2a2a2a">
                </div>

                {{-- Recipient name --}}
                <div>
                    <label for="recipient_name" class="block text-xs font-semibold uppercase tracking-wider text-gray-400 mb-2">
                        Nama Penerima
                    </label>
                    <input type="text" id="recipient_name" name="recipient_name"
                           value="{{ old('recipient_name') }}"
                           maxlength="120"
                           placeholder="Nama lengkap (opsional)"
                           class="w-full px-4 py-3 rounded-lg text-sm text-white"
                           style="background:#0f0f0f;border:1px solid #2a2a2a">
                    @error('recipient_name')
                        <p class="text-xs mt-1.5" style="color:#fca5a5">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Recipient email --}}
                <div>
                    <label for="recipient_email" class="block text-xs font-semibold uppercase tracking-wider text-gray-400 mb-2">
                        Email Penerima
                    </label>
                    <input type="email" id="recipient_email" name="recipient_email"
                           value="{{ old('recipient_email') }}"
                           placeholder="penerima@email.com (opsional)"
                           class="w-full px-4 py-3 rounded-lg text-sm text-white"
                           style="background:#0f0f0f;border:1px solid #2a2a2a">
                    <p class="text-xs text-gray-500 mt-1.5">Kosongkan jika ingin memberikan kode hadiah secara langsung.</p>
                    @error('recipient_email')
                        <p class="text-xs mt-1.5" style="color:#fca5a5">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Personal message --}}
                <div>
                    <label for="personal_message" class="block text-xs font-semibold uppercase tracking-wider text-gray-400 mb-2">
                        Pesan Pribadi
                    </label>
                    <textarea id="personal_message" name="personal_message" rows="3"
                              x-model="message"
                              maxlength="300"
                              placeholder="Selamat menikmati pilihan terbaik sinema Indonesia!"
                              class="w-full px-4 py-3 rounded-lg text-sm text-white resize-none"
                              style="background:#0f0f0f;border:1px solid #2a2a2a;font-family:'Inter',sans-serif">{{ old('personal_message') }}</textarea>
                    <div class="flex items-center justify-between mt-1.5">
                        <p class="text-xs text-gray-500">Pesan akan tampil di email hadiah (maks 300 karakter).</p>
                        <p class="text-xs" :class="message.length > 280 ? 'text-amber-400' : 'text-gray-500'">
                            <span x-text="message.length"></span>/300
                        </p>
                    </div>
                    @error('personal_message')
                        <p class="text-xs mt-1.5" style="color:#fca5a5">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Submit --}}
                <div class="pt-2">
                    <button type="submit"
                            class="w-full py-3.5 rounded-xl text-sm font-bold text-black transition-all hover:scale-[1.02] active:scale-[0.99]"
                            style="background:linear-gradient(90deg,#C5A55A,#E8D5A3)">
                        Lanjut ke Pembayaran
                    </button>
                    <a href="{{ route('plans.index') }}"
                       class="block text-center text-sm text-gray-500 hover:text-white transition-colors mt-3">
                        &larr; Kembali ke Plans
                    </a>
                </div>
            </form>

            {{-- Trust line --}}
            <p class="text-center text-xs text-gray-500 mt-6">
                <x-icon name="shield" :size="12" class="inline-block align-middle text-[#C5A55A]/70" />
                Pembayaran aman lewat Midtrans · Kode hadiah berlaku 90 hari
            </p>
        </div>
    </div>
</x-layout>
