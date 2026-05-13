<x-layout title="Ekspor Data — FLiK">
    <div class="min-h-screen bg-black pt-20 pb-16">
        <div class="container mx-auto px-4 md:px-16 max-w-2xl">

            <div class="mb-8">
                <div class="flex items-center gap-3 mb-3">
                    <a href="{{ route('privacy.index') }}" class="text-gray-500 hover:text-[#C5A55A] transition-colors text-sm flex items-center gap-1">
                        <x-icon name="chevron-left" :size="14" /> Privacy &amp; Data
                    </a>
                </div>
                <h1 class="font-heading text-3xl md:text-4xl font-bold text-white">Ekspor data Anda</h1>
                <p class="text-gray-400 text-sm mt-2">
                    Kami akan menyiapkan satu file JSON berisi semua data yang FLiK simpan tentang akun Anda.
                </p>
            </div>

            <x-flash />

            <div class="rounded-xl p-6" style="background:#1a1a1a;border:1px solid rgba(197,165,90,0.20)">
                <h2 class="font-heading text-lg font-semibold text-white mb-3">Yang termasuk dalam ekspor</h2>
                <ul class="text-sm text-gray-300 space-y-1.5 list-disc list-inside">
                    <li>Profil (nama, email, status verifikasi, status 2FA)</li>
                    <li>Preferensi onboarding &amp; rekomendasi yang ditujukan untuk Anda</li>
                    <li>Watchlist, riwayat tonton, rating, dan komentar</li>
                    <li>Jadwal "Save for Friday Night" dan riwayat kuis trivia</li>
                    <li>Langganan, pembayaran, dan riwayat saldo coin</li>
                    <li>Achievement &amp; level / XP</li>
                    <li>Notifikasi yang pernah Anda terima</li>
                    <li>Year-in-Review yang sudah dibuat</li>
                    <li>Riwayat login &amp; perangkat tepercaya (IP dipotong untuk privasi)</li>
                    <li>Catatan audit yang relevan dengan akun Anda</li>
                </ul>

                <div class="mt-5 p-3 rounded-lg flex items-start gap-3" style="background:rgba(197,165,90,0.06);border:1px solid rgba(197,165,90,0.18)">
                    <x-icon name="info" :size="16" class="text-[#C5A55A] mt-0.5 shrink-0" />
                    <p class="text-xs text-gray-300">
                        Pemrosesan biasanya selesai dalam beberapa menit. Anda akan menerima notifikasi dengan tautan unduhan yang berlaku <strong>24 jam</strong> dan hanya dapat dibuka dari akun ini.
                    </p>
                </div>

                <form method="POST" action="{{ route('privacy.export.submit') }}" class="mt-6">
                    @csrf
                    <button type="submit"
                            class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-5 py-2.5 text-sm font-semibold text-black rounded-lg transition-opacity hover:opacity-90"
                            style="background:linear-gradient(135deg,#C5A55A,#E8D5A3)">
                        <x-icon name="download" :size="16" /> Mulai siapkan ekspor
                    </button>
                </form>
            </div>

        </div>
    </div>
</x-layout>
