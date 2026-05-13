{{--
    Terms of Service — bilingual (BI default, EN toggle).
    Authored against Indonesian governing law (UU ITE 19/2016, KUHPerdata
    art. 1320, UU Hak Cipta 28/2014, UU PDP 27/2022). Mirrors GDPR-friendly
    consent + erasure language for EU users.
--}}
<x-legal-page title="Syarat & Ketentuan" :updatedAt="$updatedAt">

    {{-- ════════════════════════ BAHASA INDONESIA ════════════════════════ --}}
    <div x-show="lang === 'id'" x-cloak>
        <p>
            Selamat datang di <strong>FLiK — Rumah Sinema Indonesia</strong>. Dengan
            mengakses, mendaftar, atau menggunakan Layanan FLiK, Anda menyetujui Syarat
            & Ketentuan ini. Jika Anda tidak setuju, mohon berhenti menggunakan Layanan.
        </p>

        <h2>1. Definisi</h2>
        <ul>
            <li><strong>"FLiK"</strong>, <strong>"kami"</strong> — penyelenggara Layanan.</li>
            <li><strong>"Anda"</strong>, <strong>"Pengguna"</strong> — orang yang mengakses atau menggunakan Layanan.</li>
            <li><strong>"Konten"</strong> — film, gambar, subtitle, ulasan, komentar, dan semua materi yang ditampilkan dalam Layanan.</li>
            <li><strong>"Konten Pengguna"</strong> — komentar, ulasan, rating, foto profil, dan materi lain yang Anda unggah.</li>
        </ul>

        <h2>2. Akun</h2>
        <ul>
            <li>Anda harus berusia minimal <strong>13 tahun</strong>. Pengguna 13–18 tahun memerlukan persetujuan orang tua/wali.</li>
            <li>Satu akun per orang. Berbagi kredensial dengan pihak ketiga dilarang.</li>
            <li>Anda bertanggung jawab penuh menjaga kerahasiaan kata sandi dan aktivitas dari akun Anda.</li>
            <li>Beri tahu kami segera di <a href="mailto:security@flik.id">security@flik.id</a> jika Anda mencurigai pelanggaran keamanan.</li>
        </ul>

        <h2>3. Penggunaan yang Diperbolehkan</h2>
        <p>Layanan disediakan untuk konsumsi pribadi non-komersial. Anda dilarang:</p>
        <ul>
            <li>Mengunduh, merekam, menyalin, men-download, mengkopi, atau mendistribusikan ulang Konten dengan cara apa pun yang tidak diizinkan secara eksplisit oleh fitur Layanan.</li>
            <li>Membongkar, menerobos, atau melewati DRM, watermark, atau perlindungan teknis lainnya.</li>
            <li>Melakukan reverse-engineering, decompiling, atau scraping terhadap Layanan atau API kami.</li>
            <li>Menggunakan Layanan untuk tindakan ilegal, melanggar privasi orang lain, atau mengganggu pengguna lain.</li>
            <li>Mengirim spam, kode jahat, virus, atau melakukan serangan DoS/brute-force.</li>
            <li>Membuat akun ganda untuk menyalahgunakan promo, kuis, rewards, atau jaminan refund.</li>
            <li>Menggunakan VPN/proxy untuk mengelabui batasan geografis lisensi.</li>
        </ul>

        <h2>4. Kekayaan Intelektual & Hak Cipta</h2>
        <p>Seluruh Konten dilindungi oleh <strong>Undang-Undang Nomor 28 Tahun 2014 tentang Hak Cipta</strong>. FLiK atau pemberi lisensinya adalah pemilik atau pemegang lisensi atas seluruh Konten.</p>
        <p>Anda menerima lisensi <strong>terbatas, non-eksklusif, tidak dapat dialihkan, dan dapat dicabut</strong> untuk mengakses dan menonton Konten secara streaming pribadi pada perangkat yang didukung.</p>
        <h3>Konten Pengguna</h3>
        <p>Anda tetap memiliki hak atas Konten Pengguna Anda. Dengan mengunggah, Anda memberi FLiK lisensi <strong>non-eksklusif, bebas royalti, di seluruh dunia</strong> untuk menampilkan, menyimpan, dan mendistribusikan Konten Pengguna tersebut dalam Layanan.</p>
        <p>Pengaduan hak cipta (DMCA-style): kirim ke <a href="mailto:legal@flik.id">legal@flik.id</a> dengan informasi: identifikasi karya, lokasi pelanggaran (URL), pernyataan iktikad baik, dan tanda tangan.</p>

        <h2>5. Langganan & Pembayaran</h2>
        <ul>
            <li>Sebagian fitur memerlukan <strong>langganan berbayar</strong>. Detail paket, harga, dan benefit tersedia di halaman <a href="{{ route('plans.index') }}">Paket</a>.</li>
            <li>Pembayaran diproses melalui <strong>Midtrans</strong>. FLiK tidak menyimpan data kartu/dompet digital.</li>
            <li>Langganan diperpanjang otomatis pada periode berikutnya kecuali Anda membatalkan sebelum tanggal perpanjangan.</li>
            <li>Pajak (PPN) berlaku sesuai peraturan pemerintah Indonesia.</li>
            <li>Harga dapat berubah dengan pemberitahuan minimal <strong>14 hari</strong> sebelum berlaku.</li>
        </ul>

        <h2>6. Pengembalian Dana (Refund)</h2>
        <p>Ketentuan lengkap diatur dalam <a href="{{ route('legal.refund') }}">Kebijakan Refund</a>. Singkatnya: refund pro-rata tersedia dalam <strong>7 hari</strong> sejak transaksi pertama jika Anda belum menonton lebih dari 1 film penuh.</p>

        <h2>7. Penangguhan & Penghentian Akun</h2>
        <p>Kami dapat menangguhkan atau menutup akun Anda, dengan atau tanpa pemberitahuan, jika:</p>
        <ul>
            <li>Anda melanggar Syarat & Ketentuan ini atau hukum yang berlaku.</li>
            <li>Pembayaran ditolak, di-chargeback, atau terdeteksi penipuan.</li>
            <li>Aktivitas akun mencurigakan (login dari banyak negara, sharing kredensial, abuse promosi).</li>
            <li>Permintaan resmi dari otoritas yang berwenang.</li>
        </ul>
        <p>Anda dapat menutup akun kapan saja melalui halaman <strong>Profile → Hapus Akun</strong>.</p>

        <h2>8. Konten AI</h2>
        <p>Sebagian fitur menampilkan output dari model kecerdasan buatan (rekomendasi, ringkasan, penjelasan plot, terjemahan subtitle). Output AI dapat tidak akurat. <strong>FLiK tidak menjamin akurasi, kelengkapan, atau kesesuaian output AI</strong>. Gunakan dengan kebijaksanaan.</p>

        <h2>9. Batasan Tanggung Jawab</h2>
        <p>Sejauh diizinkan hukum:</p>
        <ul>
            <li>Layanan disediakan <strong>"sebagaimana adanya"</strong> dan <strong>"sebagaimana tersedia"</strong>, tanpa jaminan apa pun, baik tersurat maupun tersirat.</li>
            <li>FLiK tidak bertanggung jawab atas kerugian tidak langsung, insidental, khusus, konsekuensial, atau punitif (termasuk kehilangan keuntungan, data, atau goodwill).</li>
            <li>Total tanggung jawab agregat FLiK kepada Anda untuk klaim apa pun dibatasi pada jumlah yang Anda bayar kepada FLiK <strong>dalam 12 bulan terakhir</strong> atau <strong>Rp 1.000.000</strong>, mana yang lebih kecil.</li>
            <li>Pembatasan ini tidak menghilangkan tanggung jawab yang menurut hukum tidak dapat dibatasi (mis. cedera fisik karena kelalaian berat).</li>
        </ul>

        <h2>10. Indemnifikasi</h2>
        <p>Anda setuju mengganti rugi dan membebaskan FLiK, afiliasi, direktur, karyawan, dan agen dari setiap klaim, kerugian, dan biaya (termasuk biaya pengacara wajar) yang timbul dari pelanggaran Syarat & Ketentuan ini, pelanggaran hak cipta, atau penyalahgunaan Layanan oleh Anda.</p>

        <h2>11. Force Majeure</h2>
        <p>FLiK tidak bertanggung jawab atas kegagalan atau keterlambatan kinerja akibat keadaan kahar — termasuk bencana alam, pemadaman listrik, gangguan jaringan, perang, pemogokan, regulasi pemerintah, atau pandemi.</p>

        <h2>12. Hukum yang Mengatur</h2>
        <p>Syarat & Ketentuan ini tunduk pada dan ditafsirkan menurut <strong>hukum Negara Republik Indonesia</strong>, tanpa memperhatikan prinsip pertentangan hukum.</p>

        <h2>13. Penyelesaian Sengketa</h2>
        <ol>
            <li><strong>Musyawarah</strong>: para pihak akan terlebih dahulu menyelesaikan setiap perselisihan dengan musyawarah selama maksimum <strong>30 hari kalender</strong>.</li>
            <li><strong>Mediasi</strong>: jika musyawarah gagal, perselisihan diselesaikan melalui mediasi di Jakarta.</li>
            <li><strong>Arbitrase / Pengadilan</strong>: bila mediasi gagal, perselisihan diselesaikan melalui <strong>Badan Arbitrase Nasional Indonesia (BANI)</strong> di Jakarta atau, sesuai pilihan FLiK, di <strong>Pengadilan Negeri Jakarta Pusat</strong>.</li>
        </ol>

        <h2>14. Perubahan Syarat</h2>
        <p>Kami dapat memperbarui Syarat & Ketentuan ini. Perubahan material akan diberitahukan minimal <strong>14 hari</strong> sebelum berlaku melalui email atau notifikasi dalam aplikasi. Penggunaan Layanan secara terus-menerus setelah tanggal efektif berarti Anda menerima perubahan tersebut.</p>

        <h2>15. Lain-lain</h2>
        <ul>
            <li><strong>Pemisahan</strong>: jika satu klausul dinyatakan tidak sah, klausul lainnya tetap berlaku.</li>
            <li><strong>Tidak ada pengabaian</strong>: kelalaian FLiK menegakkan suatu hak bukan berarti pengabaian hak tersebut.</li>
            <li><strong>Penugasan</strong>: Anda tidak dapat mengalihkan Syarat & Ketentuan ini tanpa persetujuan tertulis FLiK. FLiK dapat mengalihkan kepada afiliasi atau penerus bisnis.</li>
            <li><strong>Bahasa</strong>: jika ada perbedaan tafsir antara versi Bahasa Indonesia dan English, versi <strong>Bahasa Indonesia</strong> yang berlaku.</li>
        </ul>

        <h2>16. Kontak</h2>
        <p>Pertanyaan umum: <a href="mailto:hello@flik.id">hello@flik.id</a> · Hukum: <a href="mailto:legal@flik.id">legal@flik.id</a> · Keamanan: <a href="mailto:security@flik.id">security@flik.id</a>.</p>
    </div>

    {{-- ════════════════════════ ENGLISH ════════════════════════ --}}
    <div x-show="lang === 'en'" x-cloak>
        <p>
            Welcome to <strong>FLiK — Rumah Sinema Indonesia</strong>. By accessing,
            registering for, or using the FLiK Service you accept these Terms of Service.
            If you do not agree, please stop using the Service.
        </p>

        <h2>1. Definitions</h2>
        <ul>
            <li><strong>"FLiK"</strong>, <strong>"we"</strong> — the operator of the Service.</li>
            <li><strong>"You"</strong>, <strong>"User"</strong> — anyone accessing or using the Service.</li>
            <li><strong>"Content"</strong> — films, images, subtitles, reviews, comments, and any material displayed in the Service.</li>
            <li><strong>"User Content"</strong> — comments, reviews, ratings, profile photo, and any other material you upload.</li>
        </ul>

        <h2>2. Account</h2>
        <ul>
            <li>You must be at least <strong>13 years old</strong>. Users aged 13–18 require parental/guardian consent.</li>
            <li>One account per person. Sharing credentials with third parties is prohibited.</li>
            <li>You are responsible for keeping your password confidential and for all activity under your account.</li>
            <li>Notify us immediately at <a href="mailto:security@flik.id">security@flik.id</a> if you suspect a security breach.</li>
        </ul>

        <h2>3. Acceptable Use</h2>
        <p>The Service is provided for personal, non-commercial consumption. You must not:</p>
        <ul>
            <li>Download, record, copy, or redistribute Content in any way not explicitly enabled by Service features.</li>
            <li>Tamper with, defeat, or bypass DRM, watermarking, or any technical protection measures.</li>
            <li>Reverse-engineer, decompile, or scrape the Service or our APIs.</li>
            <li>Use the Service for unlawful purposes, to violate the privacy of others, or to harass other users.</li>
            <li>Send spam, malicious code, viruses, or run DoS / brute-force attacks.</li>
            <li>Create multiple accounts to abuse promotions, quizzes, rewards, or refund guarantees.</li>
            <li>Use VPNs/proxies to circumvent territorial-licence restrictions.</li>
        </ul>

        <h2>4. Intellectual Property & Copyright</h2>
        <p>All Content is protected under <strong>Indonesian Copyright Law No. 28 of 2014</strong>. FLiK or its licensors own or license all Content.</p>
        <p>You receive a <strong>limited, non-exclusive, non-transferable, revocable</strong> licence to access and stream the Content for personal viewing on supported devices.</p>
        <h3>User Content</h3>
        <p>You retain ownership of your User Content. By uploading it, you grant FLiK a <strong>non-exclusive, royalty-free, worldwide</strong> licence to display, store, and distribute that User Content within the Service.</p>
        <p>Copyright complaints (DMCA-style): write to <a href="mailto:legal@flik.id">legal@flik.id</a> with: identification of the work, location of the alleged infringement (URL), a good-faith statement, and your signature.</p>

        <h2>5. Subscriptions & Payment</h2>
        <ul>
            <li>Some features require a <strong>paid subscription</strong>. Plan, price, and benefits are listed on the <a href="{{ route('plans.index') }}">Plans</a> page.</li>
            <li>Payments are processed by <strong>Midtrans</strong>. FLiK does not store card or e-wallet data.</li>
            <li>Subscriptions auto-renew at the end of each period unless you cancel before the renewal date.</li>
            <li>Indonesian VAT applies as required by law.</li>
            <li>Prices may change with at least <strong>14 days</strong>' notice before taking effect.</li>
        </ul>

        <h2>6. Refunds</h2>
        <p>Full terms in our <a href="{{ route('legal.refund') }}">Refund Policy</a>. In short: pro-rata refund within <strong>7 days</strong> of the first transaction if you have not watched more than one full film.</p>

        <h2>7. Suspension & Termination</h2>
        <p>We may suspend or terminate your account, with or without notice, if:</p>
        <ul>
            <li>You breach these Terms or applicable law.</li>
            <li>Your payment is declined, charged back, or flagged as fraudulent.</li>
            <li>Account activity is suspicious (logins from many countries, credential sharing, promotion abuse).</li>
            <li>A lawful order from a competent authority requires it.</li>
        </ul>
        <p>You may close your account at any time via <strong>Profile → Delete Account</strong>.</p>

        <h2>8. AI Output</h2>
        <p>Some features display output from artificial-intelligence models (recommendations, summaries, plot explanations, subtitle translation). AI output may be inaccurate. <strong>FLiK does not guarantee the accuracy, completeness, or fitness for purpose of AI output</strong>. Use it at your discretion.</p>

        <h2>9. Limitation of Liability</h2>
        <p>To the maximum extent permitted by law:</p>
        <ul>
            <li>The Service is provided <strong>"as is"</strong> and <strong>"as available"</strong>, without warranties of any kind, express or implied.</li>
            <li>FLiK shall not be liable for indirect, incidental, special, consequential, or punitive damages (including lost profits, data, or goodwill).</li>
            <li>FLiK's aggregate liability for any claim is limited to the amount you paid FLiK in the <strong>preceding 12 months</strong> or <strong>IDR 1,000,000</strong>, whichever is lower.</li>
            <li>These limits do not exclude liability that cannot lawfully be excluded (e.g. personal injury caused by gross negligence).</li>
        </ul>

        <h2>10. Indemnification</h2>
        <p>You agree to indemnify and hold harmless FLiK, its affiliates, directors, employees, and agents from any claims, losses, and costs (including reasonable legal fees) arising from your breach of these Terms, copyright infringement, or misuse of the Service.</p>

        <h2>11. Force Majeure</h2>
        <p>FLiK is not liable for failure or delay in performance caused by force majeure — including natural disasters, power outages, network disruption, war, strikes, governmental regulation, or pandemics.</p>

        <h2>12. Governing Law</h2>
        <p>These Terms are governed by and interpreted under <strong>the laws of the Republic of Indonesia</strong>, without regard to conflict-of-laws principles.</p>

        <h2>13. Dispute Resolution</h2>
        <ol>
            <li><strong>Negotiation</strong>: the parties shall first attempt to settle any dispute by good-faith negotiation for up to <strong>30 calendar days</strong>.</li>
            <li><strong>Mediation</strong>: failing negotiation, the dispute will go to mediation in Jakarta.</li>
            <li><strong>Arbitration / Court</strong>: failing mediation, the dispute shall be resolved through the <strong>Indonesian National Board of Arbitration (BANI)</strong> in Jakarta or, at FLiK's option, the <strong>Central Jakarta District Court</strong>.</li>
        </ol>

        <h2>14. Changes to these Terms</h2>
        <p>We may update these Terms. Material changes will be communicated at least <strong>14 days</strong> before they take effect via email or in-app notice. Continued use of the Service after the effective date constitutes acceptance of the changes.</p>

        <h2>15. Miscellaneous</h2>
        <ul>
            <li><strong>Severability</strong>: if any clause is held invalid, the remaining clauses remain in force.</li>
            <li><strong>No waiver</strong>: failure by FLiK to enforce a right does not constitute a waiver of that right.</li>
            <li><strong>Assignment</strong>: you may not assign these Terms without FLiK's written consent. FLiK may assign to an affiliate or business successor.</li>
            <li><strong>Language</strong>: in case of discrepancy between the Bahasa Indonesia and English versions, the <strong>Bahasa Indonesia</strong> version prevails.</li>
        </ul>

        <h2>16. Contact</h2>
        <p>General: <a href="mailto:hello@flik.id">hello@flik.id</a> · Legal: <a href="mailto:legal@flik.id">legal@flik.id</a> · Security: <a href="mailto:security@flik.id">security@flik.id</a>.</p>
    </div>

</x-legal-page>
