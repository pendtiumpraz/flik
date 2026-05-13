<x-layout title="Privacy & Data — FLiK">
    <div class="min-h-screen bg-black pt-20 pb-16">
        <div class="container mx-auto px-4 md:px-16 max-w-3xl">

            {{-- Page header --}}
            <div class="mb-8">
                <div class="flex items-center gap-3 mb-3">
                    <a href="{{ route('profile.show') }}" class="text-gray-500 hover:text-[#C5A55A] transition-colors text-sm flex items-center gap-1">
                        <x-icon name="chevron-left" :size="14" /> Profile
                    </a>
                </div>
                <h1 class="font-heading text-3xl md:text-4xl font-bold text-white">Privacy &amp; Data</h1>
                <p class="text-gray-400 text-sm mt-2 max-w-2xl">
                    Hak Anda atas data pribadi: lihat semua yang FLiK simpan tentang Anda, atau hapus seluruh akun secara permanen.
                </p>
            </div>

            <x-flash />

            {{-- Export card --}}
            <div class="rounded-xl p-6 mb-4" style="background:#1a1a1a;border:1px solid rgba(197,165,90,0.25)">
                <div class="flex items-start gap-4">
                    <div class="shrink-0 w-12 h-12 rounded-full flex items-center justify-center" style="background:rgba(197,165,90,0.12)">
                        <x-icon name="download" :size="22" class="text-[#C5A55A]" />
                    </div>
                    <div class="flex-1">
                        <h2 class="font-heading text-lg font-semibold text-white">Ekspor data saya</h2>
                        <p class="text-gray-400 text-sm mt-1">
                            Unduh seluruh riwayat tonton, watchlist, rating, komentar, langganan, dan riwayat aktivitas Anda dalam format JSON.
                            Sesuai dengan GDPR Pasal 15 (hak akses) &amp; Pasal 20 (portabilitas data).
                        </p>
                        <a href="{{ route('privacy.export.request') }}"
                           class="inline-flex items-center gap-2 mt-4 px-4 py-2 text-sm font-semibold text-black rounded-lg transition-opacity hover:opacity-90"
                           style="background:linear-gradient(135deg,#C5A55A,#E8D5A3)">
                            <x-icon name="download" :size="16" /> Minta ekspor data
                        </a>
                    </div>
                </div>
            </div>

            {{-- Delete card (scary red) --}}
            <div class="rounded-xl p-6" style="background:#1a0e0e;border:1px solid rgba(220,38,38,0.45)">
                <div class="flex items-start gap-4">
                    <div class="shrink-0 w-12 h-12 rounded-full flex items-center justify-center" style="background:rgba(220,38,38,0.15)">
                        <x-icon name="x" :size="22" class="text-red-400" />
                    </div>
                    <div class="flex-1">
                        <h2 class="font-heading text-lg font-semibold text-red-300">Hapus akun saya</h2>
                        <p class="text-gray-400 text-sm mt-1">
                            Menghapus akun bersifat <strong class="text-red-300">permanen</strong>: watchlist, rating, riwayat tonton, dan kuis dihapus selamanya.
                            Komentar Anda dianonimkan untuk menjaga keutuhan diskusi. Catatan pembayaran disimpan dalam bentuk anonim sesuai kewajiban audit pajak.
                        </p>
                        <a href="{{ route('privacy.delete.confirm') }}"
                           class="inline-flex items-center gap-2 mt-4 px-4 py-2 text-sm font-semibold text-white rounded-lg transition-colors"
                           style="background:rgba(220,38,38,0.85);border:1px solid rgba(220,38,38,1)">
                            Lanjut ke konfirmasi penghapusan
                        </a>
                    </div>
                </div>
            </div>

        </div>
    </div>
</x-layout>
