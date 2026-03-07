<div class="w-full max-w-3xl mx-auto">
    <div x-data="{ active: null, items: [
        { id: 1, title: 'Apa itu FLiK?', answer: 'FLiK adalah platform streaming film premium Indonesia. Dengan koleksi terlengkap mulai dari film klasik legendaris Indonesia hingga karya terbaru, FLiK menjadi rumah sinema Indonesia untuk semua pecinta film.' },
        { id: 2, title: 'Berapa biaya langganan FLiK?', answer: 'FLiK menawarkan paket mulai dari GRATIS hingga Rp 99.000/bulan. Paket Free memberikan akses terbatas, sementara paket Premium dan Ultra memberikan akses penuh ke semua konten dengan kualitas hingga 4K HDR.' },
        { id: 3, title: 'Di mana saya bisa menonton?', answer: 'Tonton di mana saja — smartphone, tablet, laptop, atau Smart TV. Progress menonton otomatis tersinkron di semua perangkat. FLiK juga tersedia sebagai PWA, bisa di-install langsung dari browser.' },
        { id: 4, title: 'Bagaimana cara membatalkan langganan?', answer: 'FLiK fleksibel. Tidak ada kontrak dan komitmen. Kamu bisa membatalkan langganan kapan saja melalui halaman akun. Tidak ada biaya pembatalan.' },
        { id: 5, title: 'Apa yang bisa ditonton di FLiK?', answer: 'FLiK memiliki koleksi film Indonesia terlengkap — dari film horor klasik, drama keluarga, action, hingga dokumenter. Ditambah fitur gamifikasi: kumpulkan coins, raih achievement, dan naik level sambil menonton.' },
        { id: 6, title: 'Apakah FLiK aman untuk anak-anak?', answer: 'Kami menyediakan kontrol orangtua untuk membatasi konten berdasarkan rating usia. Kamu bisa mengatur PIN untuk mengunci profil anak agar hanya bisa menonton konten yang sesuai.' },
        ]}" class="space-y-3">

        <template x-for="{ id, title, answer } in items" :key="id">
            <div x-data="{
                get expanded() { return this.active === this.id },
                set expanded(value) { this.active = value ? this.id : null },
            }" role="region" class="rounded-xl overflow-hidden transition-all"
                 :style="expanded ? 'background:rgba(197,165,90,0.08);border:1px solid rgba(197,165,90,0.2)' : 'background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.06)'">
                <h3>
                    <button
                        @click="expanded = !expanded"
                        :aria-expanded="expanded"
                        class="flex w-full items-center justify-between px-6 py-5 text-left font-semibold transition-colors hover:text-white"
                        :class="expanded ? 'text-white' : 'text-gray-300'"
                        >
                        <span x-text="title" class="text-base md:text-lg"></span>
                        <span class="ml-4 flex-shrink-0 w-6 h-6 flex items-center justify-center rounded-full transition-all"
                              :style="expanded ? 'background:rgba(197,165,90,0.2);color:#C5A55A' : 'background:rgba(255,255,255,0.05);color:rgba(255,255,255,0.4)'"
                              :class="expanded ? 'rotate-45' : ''">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        </span>
                    </button>
                </h3>

                <div x-show="expanded" x-collapse.duration.300ms>
                    <div x-text="answer" class="px-6 pb-5 text-sm md:text-base text-gray-400 leading-relaxed"></div>
                </div>
            </div>
        </template>
    </div>
</div>
