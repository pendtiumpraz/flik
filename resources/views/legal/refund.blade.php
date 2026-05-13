{{--
    Refund Policy — bilingual (BI default, EN toggle).
    Aligned with the Subscription model + Midtrans payment flow. Update
    LegalController::LAST_UPDATED whenever any percentage, window, or
    excluded scenario below changes.
--}}
<x-legal-page title="Kebijakan Pengembalian Dana" :updatedAt="$updatedAt">

    {{-- ════════════════════════ BAHASA INDONESIA ════════════════════════ --}}
    <div x-show="lang === 'id'" x-cloak>
        <p>
            Kebijakan ini menjelaskan ketentuan pengembalian dana (refund) untuk
            langganan berbayar di FLiK. Kebijakan ini bagian tak terpisahkan dari
            <a href="{{ route('legal.terms') }}">Syarat & Ketentuan</a>.
        </p>

        <h2>1. Prinsip Umum</h2>
        <ul>
            <li>Refund hanya berlaku untuk <strong>langganan berbayar</strong> yang dibayar melalui FLiK (via Midtrans).</li>
            <li>Pembayaran satu kali (mis. sewa film tunggal, jika tersedia) bersifat <strong>final dan tidak dapat dikembalikan</strong> setelah Anda mulai menonton.</li>
            <li>Refund dilakukan ke metode pembayaran <strong>asli yang digunakan</strong>. Kami tidak melakukan refund tunai atau ke metode lain.</li>
        </ul>

        <h2>2. Garansi 7 Hari (Cooling-Off)</h2>
        <p>Anda berhak meminta <strong>refund penuh</strong> dalam <strong>7 hari kalender</strong> sejak transaksi pertama Anda jika:</p>
        <ul>
            <li>Anda <strong>belum menonton lebih dari 1 (satu) film penuh</strong> dalam periode tersebut, dan</li>
            <li>Anda mengirim permintaan resmi melalui <a href="mailto:billing@flik.id">billing@flik.id</a> dengan menyebut <em>Order ID</em> dan alasan singkat.</li>
        </ul>
        <p>Setelah ambang batas tonton terlampaui, atau setelah hari ke-7, refund mengikuti ketentuan pro-rata di Bagian 3.</p>

        <h2>3. Refund Pro-Rata (Setelah Hari ke-7)</h2>
        <p>Untuk pembatalan setelah masa cooling-off, kami menghitung refund pro-rata berdasarkan sisa hari belum terpakai dalam siklus berjalan, dipotong:</p>
        <ul>
            <li>Biaya administrasi & gateway: <strong>5%</strong> dari nilai langganan.</li>
            <li>PPN dan pungutan pemerintah lain yang sudah disetor — <strong>tidak dapat dikembalikan</strong>.</li>
            <li>Diskon, kupon, dan benefit promosi yang sudah dipakai akan dipotong dari nilai refund.</li>
        </ul>
        <p>Contoh: paket Rp 60.000/bulan, dibatalkan pada hari ke-15 (sisa 15 hari) → refund kasar = Rp 30.000, dipotong 5% admin → refund bersih ± Rp 28.500.</p>

        <h2>4. Refund Tahunan</h2>
        <ul>
            <li>Paket tahunan dapat di-refund pro-rata berdasarkan jumlah bulan penuh yang belum digunakan.</li>
            <li>Bulan parsial dihitung sebagai bulan penuh untuk tujuan refund.</li>
        </ul>

        <h2>5. Auto-Renewal</h2>
        <ul>
            <li>Langganan diperpanjang otomatis. Anda dapat membatalkan kapan saja melalui halaman <strong>Profile → Langganan</strong> sebelum tanggal perpanjangan.</li>
            <li>Jika auto-renewal terjadi <strong>tanpa Anda menonton sama sekali</strong> dalam 30 hari sebelum perpanjangan, Anda berhak refund penuh untuk periode baru tersebut bila diminta dalam <strong>3 hari</strong> setelah perpanjangan.</li>
        </ul>

        <h2>6. Situasi yang TIDAK Berhak Refund</h2>
        <ul>
            <li>Penangguhan akun karena pelanggaran <a href="{{ route('legal.terms') }}">Syarat & Ketentuan</a> (sharing kredensial, fraud, abuse promo, pembajakan).</li>
            <li>Chargeback yang dilakukan tanpa upaya menghubungi kami terlebih dulu — akun akan ditangguhkan otomatis.</li>
            <li>Konten tertentu tidak tersedia di wilayah Anda karena pembatasan lisensi geografis (kami akan mencantumkannya di halaman paket sebelum pembelian).</li>
            <li>Gangguan layanan singkat (&lt; 24 jam) yang sudah dipulihkan.</li>
            <li>Ketidakcocokan perangkat ketika persyaratan minimum sudah dijelaskan di halaman <a href="{{ route('plans.index') }}">Paket</a>.</li>
            <li>Permintaan setelah <strong>90 hari</strong> sejak transaksi.</li>
        </ul>

        <h2>7. Gangguan Layanan Material</h2>
        <p>Bila Layanan mengalami gangguan material yang membuat Anda tidak dapat menonton selama lebih dari <strong>72 jam berturut-turut</strong>, Anda berhak atas:</p>
        <ul>
            <li>Perpanjangan masa langganan setara durasi gangguan, atau</li>
            <li>Refund pro-rata atas durasi gangguan, sesuai pilihan Anda.</li>
        </ul>

        <h2>8. Cara Mengajukan Refund</h2>
        <ol>
            <li>Kirim email ke <a href="mailto:billing@flik.id">billing@flik.id</a> dari alamat email yang terdaftar di akun.</li>
            <li>Sertakan: <strong>Order ID</strong> (lihat halaman Riwayat Pembayaran), tanggal transaksi, alasan permintaan.</li>
            <li>Kami akan menanggapi dalam <strong>3 hari kerja</strong>.</li>
            <li>Jika disetujui, dana akan kembali ke metode pembayaran asli dalam <strong>7–14 hari kerja</strong> tergantung penyedia pembayaran.</li>
        </ol>

        <h2>9. Sengketa</h2>
        <p>Bila Anda tidak puas dengan keputusan refund, Anda dapat mengajukan keberatan tertulis kepada <a href="mailto:legal@flik.id">legal@flik.id</a>. Penyelesaian sengketa lebih lanjut mengikuti Bagian 13 <a href="{{ route('legal.terms') }}">Syarat & Ketentuan</a>.</p>

        <h2>10. Perubahan</h2>
        <p>Kami dapat memperbarui kebijakan ini. Perubahan material akan diberitahukan minimal <strong>14 hari</strong> sebelum berlaku.</p>
    </div>

    {{-- ════════════════════════ ENGLISH ════════════════════════ --}}
    <div x-show="lang === 'en'" x-cloak>
        <p>
            This policy explains refund rules for paid subscriptions on FLiK. It is an
            integral part of our <a href="{{ route('legal.terms') }}">Terms of Service</a>.
        </p>

        <h2>1. General Principles</h2>
        <ul>
            <li>Refunds apply only to <strong>paid subscriptions</strong> billed through FLiK (via Midtrans).</li>
            <li>One-off purchases (e.g. single-film rental, when available) are <strong>final and non-refundable</strong> once you start watching.</li>
            <li>Refunds are issued to the <strong>original payment method</strong>. We do not refund in cash or to alternative methods.</li>
        </ul>

        <h2>2. 7-Day Cooling-Off Guarantee</h2>
        <p>You may request a <strong>full refund</strong> within <strong>7 calendar days</strong> of your first transaction if:</p>
        <ul>
            <li>You have <strong>not watched more than 1 (one) full film</strong> during that period, and</li>
            <li>You submit the request via <a href="mailto:billing@flik.id">billing@flik.id</a> citing your <em>Order ID</em> and a brief reason.</li>
        </ul>
        <p>After the watch threshold is exceeded, or after day 7, refunds follow the pro-rata rules in Section 3.</p>

        <h2>3. Pro-Rata Refunds (After Day 7)</h2>
        <p>For cancellations after the cooling-off window, we calculate a pro-rata refund based on unused days in the current cycle, less:</p>
        <ul>
            <li>Administrative & gateway fee: <strong>5%</strong> of the subscription value.</li>
            <li>VAT and other government levies already remitted are <strong>non-refundable</strong>.</li>
            <li>Discounts, coupons, and promotional benefits already consumed are deducted from the refund.</li>
        </ul>
        <p>Example: IDR 60,000/month plan, cancelled on day 15 (15 days remaining) → gross refund = IDR 30,000, less 5% admin → net refund ≈ IDR 28,500.</p>

        <h2>4. Annual Plans</h2>
        <ul>
            <li>Annual plans are refundable pro-rata based on full unused months.</li>
            <li>Partial months count as full months for refund purposes.</li>
        </ul>

        <h2>5. Auto-Renewal</h2>
        <ul>
            <li>Subscriptions auto-renew. You can cancel at any time via <strong>Profile → Subscription</strong> before the renewal date.</li>
            <li>If auto-renewal occurs and you have <strong>not watched anything</strong> in the 30 days preceding renewal, you are entitled to a full refund of the new period if requested within <strong>3 days</strong> of renewal.</li>
        </ul>

        <h2>6. Situations NOT Eligible for Refund</h2>
        <ul>
            <li>Account suspension due to breach of the <a href="{{ route('legal.terms') }}">Terms</a> (credential sharing, fraud, promo abuse, piracy).</li>
            <li>Chargebacks filed without first contacting us — the account will be suspended automatically.</li>
            <li>Specific titles unavailable in your territory due to licensing restrictions (we disclose this on the plan page before purchase).</li>
            <li>Brief service disruptions (&lt; 24 hours) that have been resolved.</li>
            <li>Device incompatibility when minimum requirements are disclosed on the <a href="{{ route('plans.index') }}">Plans</a> page.</li>
            <li>Requests filed more than <strong>90 days</strong> after the transaction.</li>
        </ul>

        <h2>7. Material Service Outage</h2>
        <p>If the Service suffers a material outage preventing playback for more than <strong>72 consecutive hours</strong>, you are entitled to:</p>
        <ul>
            <li>An extension of your subscription equal to the outage duration, or</li>
            <li>A pro-rata refund of the outage duration, at your choice.</li>
        </ul>

        <h2>8. How to Request a Refund</h2>
        <ol>
            <li>Email <a href="mailto:billing@flik.id">billing@flik.id</a> from the address registered on your account.</li>
            <li>Include: <strong>Order ID</strong> (see Payment History page), transaction date, and reason.</li>
            <li>We respond within <strong>3 business days</strong>.</li>
            <li>If approved, funds return to the original payment method within <strong>7–14 business days</strong>, depending on the payment provider.</li>
        </ol>

        <h2>9. Disputes</h2>
        <p>If you disagree with a refund decision, you may submit a written appeal to <a href="mailto:legal@flik.id">legal@flik.id</a>. Further dispute resolution follows Section 13 of the <a href="{{ route('legal.terms') }}">Terms of Service</a>.</p>

        <h2>10. Changes</h2>
        <p>We may update this policy. Material changes will be communicated at least <strong>14 days</strong> before they take effect.</p>
    </div>

</x-legal-page>
