<x-layout title="Hapus Akun — FLiK">
    <div class="min-h-screen bg-black pt-20 pb-16">
        <div class="container mx-auto px-4 md:px-16 max-w-2xl">

            <div class="mb-8">
                <div class="flex items-center gap-3 mb-3">
                    <a href="{{ route('privacy.index') }}" class="text-gray-500 hover:text-red-400 transition-colors text-sm flex items-center gap-1">
                        <x-icon name="chevron-left" :size="14" /> Privacy &amp; Data
                    </a>
                </div>
                <h1 class="font-heading text-3xl md:text-4xl font-bold text-red-300">Hapus akun saya</h1>
                <p class="text-gray-400 text-sm mt-2">
                    Tindakan ini bersifat <strong class="text-red-300">permanen dan tidak dapat dibatalkan</strong>.
                </p>
            </div>

            <x-flash />

            {{-- Scary warning box --}}
            <div class="rounded-xl p-5 mb-5" style="background:#1a0e0e;border:2px solid rgba(220,38,38,0.55)">
                <div class="flex items-start gap-3">
                    <x-icon name="x" :size="22" class="text-red-400 shrink-0 mt-0.5" />
                    <div class="text-sm text-red-100/90 space-y-2">
                        <p class="font-semibold text-red-300">Setelah dihapus, hal berikut akan terjadi:</p>
                        <ul class="list-disc list-inside space-y-1 text-red-100/80">
                            <li>Watchlist, riwayat tonton, rating, jadwal, dan kuis Anda <strong>dihapus permanen</strong>.</li>
                            <li>Komentar Anda <strong>dianonimkan</strong> (isi tetap, nama berubah jadi "[Deleted User]") agar diskusi tidak rusak.</li>
                            <li>Catatan langganan &amp; pembayaran <strong>dianonimkan</strong> namun disimpan untuk kewajiban audit pajak.</li>
                            <li>Riwayat login dan perangkat tepercaya <strong>dihapus</strong>.</li>
                            <li>Anda akan <strong>otomatis logout</strong> dan tidak dapat login kembali dengan email ini.</li>
                            <li>Anda dapat <a href="{{ route('privacy.export.request') }}" class="underline text-[#C5A55A]">mengekspor data lebih dulu</a> sebelum menghapus.</li>
                        </ul>
                    </div>
                </div>
            </div>

            {{-- Confirmation form --}}
            <form method="POST" action="{{ route('privacy.delete.execute') }}" class="rounded-xl p-6 space-y-5"
                  style="background:#1a1a1a;border:1px solid #2a2a2a">
                @csrf
                @method('DELETE')

                {{-- Email shown read-only as a "this is the account you are about to wipe" anchor --}}
                <div>
                    <label class="block text-xs uppercase tracking-wider text-gray-500 mb-1.5">Akun yang akan dihapus</label>
                    <div class="px-3 py-2 rounded-lg text-sm text-gray-300" style="background:#0e0e0e;border:1px solid #2a2a2a">
                        {{ $user->email }}
                    </div>
                </div>

                {{-- Reason (optional, but useful for product feedback) --}}
                <div>
                    <label for="reason" class="block text-xs uppercase tracking-wider text-gray-500 mb-1.5">Alasan (opsional)</label>
                    <textarea name="reason" id="reason" rows="2" maxlength="500"
                              class="w-full rounded-lg px-3 py-2 text-sm text-white placeholder-gray-600 focus:outline-none focus:border-[#C5A55A]/60 transition-colors"
                              style="background:#0e0e0e;border:1px solid #2a2a2a"
                              placeholder="Bantu kami memahami kenapa Anda pergi…">{{ old('reason') }}</textarea>
                    @error('reason')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                </div>

                {{-- Password confirmation --}}
                <div>
                    <label for="password" class="block text-xs uppercase tracking-wider text-gray-500 mb-1.5">Password Anda <span class="text-red-400">*</span></label>
                    <input type="password" name="password" id="password" required autocomplete="current-password"
                           class="w-full rounded-lg px-3 py-2 text-sm text-white placeholder-gray-600 focus:outline-none focus:border-[#C5A55A]/60 transition-colors"
                           style="background:#0e0e0e;border:1px solid #2a2a2a"
                           placeholder="Masukkan password untuk memverifikasi identitas Anda">
                    @error('password')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                </div>

                @if($twoFactorEnabled)
                    {{-- TOTP --}}
                    <div>
                        <label for="otp" class="block text-xs uppercase tracking-wider text-gray-500 mb-1.5">Kode 2FA (6 digit) <span class="text-red-400">*</span></label>
                        <input type="text" name="otp" id="otp" required inputmode="numeric" autocomplete="one-time-code"
                               minlength="6" maxlength="6" pattern="\d{6}"
                               class="w-full rounded-lg px-3 py-2 text-sm tracking-widest text-white placeholder-gray-600 focus:outline-none focus:border-[#C5A55A]/60 transition-colors"
                               style="background:#0e0e0e;border:1px solid #2a2a2a"
                               placeholder="123456">
                        @error('otp')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                    </div>
                @endif

                {{-- Confirm phrase --}}
                <div>
                    <label for="confirmation" class="block text-xs uppercase tracking-wider text-gray-500 mb-1.5">
                        Ketik <code class="px-1.5 py-0.5 rounded text-red-300" style="background:rgba(220,38,38,0.10)">HAPUS AKUN SAYA</code> untuk mengonfirmasi <span class="text-red-400">*</span>
                    </label>
                    <input type="text" name="confirmation" id="confirmation" required autocomplete="off"
                           class="w-full rounded-lg px-3 py-2 text-sm text-white placeholder-gray-600 focus:outline-none focus:border-red-500/60 transition-colors"
                           style="background:#0e0e0e;border:1px solid #2a2a2a"
                           placeholder="HAPUS AKUN SAYA">
                    @error('confirmation')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                </div>

                <div class="pt-2 flex flex-col-reverse sm:flex-row gap-3 sm:justify-end">
                    <a href="{{ route('privacy.index') }}"
                       class="inline-flex items-center justify-center px-4 py-2.5 text-sm text-gray-300 rounded-lg transition-colors hover:text-white"
                       style="background:#0e0e0e;border:1px solid #2a2a2a">
                        Batal
                    </a>
                    <button type="submit"
                            class="inline-flex items-center justify-center gap-2 px-5 py-2.5 text-sm font-semibold text-white rounded-lg transition-colors"
                            style="background:rgba(220,38,38,0.85);border:1px solid rgba(220,38,38,1)">
                        <x-icon name="x" :size="16" /> Hapus akun saya secara permanen
                    </button>
                </div>
            </form>

        </div>
    </div>
</x-layout>
