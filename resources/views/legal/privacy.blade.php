{{--
    Privacy Policy — bilingual (Bahasa Indonesia default, English toggle).
    Copy authored against UU PDP No. 27/2022 (Indonesia) and aligned with
    GDPR Art. 12-22 vocabulary so the same doc covers EU-resident users.

    Update flow: when adding/removing a third-party processor or processing
    purpose, bump LegalController::LAST_UPDATED *and* CONSENT_VERSION in
    resources/js/cookie-consent.js so users get re-prompted for fresh
    consent.
--}}
<x-legal-page title="Kebijakan Privasi" :updatedAt="$updatedAt">

    {{-- ════════════════════════════════════════════════════════════ --}}
    {{-- BAHASA INDONESIA                                              --}}
    {{-- ════════════════════════════════════════════════════════════ --}}
    <div x-show="lang === 'id'" x-cloak>
        <p>
            FLiK ("kami", "FLiK", "Layanan") adalah platform streaming film yang dioperasikan
            di Indonesia. Kebijakan Privasi ini menjelaskan bagaimana kami mengumpulkan,
            menggunakan, membagikan, dan melindungi data pribadi Anda sebagai pengguna Layanan,
            sesuai <strong>Undang-Undang Nomor 27 Tahun 2022 tentang Pelindungan Data Pribadi (UU PDP)</strong>
            dan, untuk pengguna di Wilayah Ekonomi Eropa, prinsip <strong>GDPR (EU 2016/679)</strong>.
        </p>

        <h2>1. Data yang Kami Kumpulkan</h2>
        <h3>a. Data yang Anda berikan langsung</h3>
        <ul>
            <li><strong>Akun</strong>: nama, alamat email, kata sandi (di-hash), foto profil opsional.</li>
            <li><strong>Profil & preferensi</strong>: jawaban kuis onboarding, mood, daftar tonton, rating, komentar.</li>
            <li><strong>Pembayaran</strong>: paket berlangganan yang dipilih dan riwayat transaksi. <em>Data kartu/dompet digital tidak pernah disimpan oleh FLiK</em> — diproses langsung oleh Midtrans.</li>
            <li><strong>Komunikasi</strong>: pesan dukungan pelanggan dan opsional langganan newsletter.</li>
        </ul>

        <h3>b. Data yang dikumpulkan otomatis</h3>
        <ul>
            <li><strong>Aktivitas tonton</strong>: film yang ditonton, durasi, posisi terakhir, perangkat.</li>
            <li><strong>Teknis</strong>: alamat IP, jenis perangkat & browser, sistem operasi, versi aplikasi.</li>
            <li><strong>Lokasi perkiraan</strong>: negara/kota dari alamat IP melalui MaxMind GeoLite2 (untuk pemenuhan lisensi geografis).</li>
            <li><strong>Cookie & penyimpanan lokal</strong>: lihat bagian <em>Cookie</em> di bawah.</li>
        </ul>

        <h2>2. Tujuan Penggunaan</h2>
        <ul>
            <li><strong>Menyediakan Layanan</strong>: streaming, daftar tonton, riwayat resume, rekomendasi.</li>
            <li><strong>Personalisasi AI</strong>: rekomendasi, ringkasan, pencarian semantik, terjemahan subtitle.</li>
            <li><strong>Keamanan & anti-fraud</strong>: deteksi login mencurigakan, perlindungan brute-force, audit log.</li>
            <li><strong>Komunikasi transaksional</strong>: kwitansi pembayaran, peringatan keamanan, perubahan kebijakan.</li>
            <li><strong>Komunikasi pemasaran</strong> (opsional, dengan persetujuan terpisah): newsletter, kampanye, rekomendasi.</li>
            <li><strong>Kepatuhan hukum</strong>: pemenuhan kewajiban perpajakan, perlindungan hak cipta, penegakan hukum.</li>
        </ul>

        <h2>3. Dasar Hukum Pemrosesan</h2>
        <ul>
            <li><strong>Pelaksanaan kontrak</strong>: untuk operasi inti Layanan (Pasal 20 ayat (2) huruf b UU PDP).</li>
            <li><strong>Persetujuan</strong>: untuk fitur opsional seperti analitik, pemasaran, dan personalisasi AI.</li>
            <li><strong>Kewajiban hukum</strong>: untuk pajak, sengketa, atau permintaan resmi otoritas berwenang.</li>
            <li><strong>Kepentingan sah</strong>: untuk keamanan platform dan pencegahan penipuan.</li>
        </ul>

        <h2>4. Pihak Ketiga (Pemroses Data)</h2>
        <p>Kami menggunakan vendor berikut sebagai pemroses data atas nama kami:</p>
        <ul>
            <li><strong>Midtrans</strong> (PT Midtrans) — pemrosesan pembayaran (kartu, e-wallet, transfer bank). Data: nama, email, jumlah transaksi. <a href="https://midtrans.com/privacy-policy" target="_blank" rel="noopener">Kebijakan Midtrans</a>.</li>
            <li><strong>Google LLC</strong> — autentikasi <em>Sign in with Google</em> (OAuth). Data: nama, email, foto profil publik. <a href="https://policies.google.com/privacy" target="_blank" rel="noopener">Kebijakan Google</a>.</li>
            <li><strong>Penyedia AI</strong> (saat fitur AI aktif) — pemrosesan teks/audio/gambar untuk fitur AI: rekomendasi, ringkasan, penjelasan plot, transkripsi subtitle, terjemahan, klasifikasi sentimen, chatbot. Provider yang dapat digunakan tergantung konfigurasi admin pada satu waktu, di antaranya:
                <ul>
                    <li>OpenAI (gpt-4o, whisper) — <a href="https://openai.com/policies/privacy-policy" target="_blank" rel="noopener">privasi</a></li>
                    <li>Anthropic (Claude) — <a href="https://www.anthropic.com/legal/privacy" target="_blank" rel="noopener">privasi</a></li>
                    <li>Google AI (Gemini) — <a href="https://policies.google.com/privacy" target="_blank" rel="noopener">privasi</a></li>
                    <li>DeepSeek, Mistral, Groq, atau penyedia OpenAI-compatible lainnya yang dipilih admin.</li>
                </ul>
                Kami mengirim hanya konten yang relevan untuk tugas tertentu (mis. teks ulasan untuk klasifikasi sentimen, teks terjemahan subtitle). Identitas pengguna tidak dilampirkan ke prompt; ID pengguna disamarkan sebagai hash di sisi kami.
            </li>
            <li><strong>MaxMind</strong> — basis data GeoLite2 untuk mendeteksi negara dari alamat IP (lokal, tanpa transmisi data ke MaxMind).</li>
            <li><strong>Mailchimp</strong> (jika diaktifkan) — pengiriman newsletter berdasarkan persetujuan terpisah Anda.</li>
            <li><strong>Bunny.net / penyedia CDN</strong> — pengiriman konten video dan aset gambar (alamat IP dapat dilihat penyedia untuk tujuan operasional CDN).</li>
            <li><strong>Pusher</strong> (jika diaktifkan) — komunikasi real-time untuk fitur Watch Party.</li>
            <li><strong>Wikipedia & DuckDuckGo</strong> — sumber pengetahuan terbuka untuk fitur chatbot AI (data yang dikirim hanya kueri pencarian, tanpa identitas pengguna).</li>
        </ul>

        <h2>5. Cookie & Penyimpanan Lokal</h2>
        <p>Kami menggunakan tiga kategori:</p>
        <ul>
            <li><strong>Wajib</strong>: sesi login, token CSRF, preferensi tema. Tidak dapat dimatikan karena diperlukan agar Layanan berfungsi.</li>
            <li><strong>Analitik</strong>: statistik penggunaan agregat untuk perbaikan produk. Memerlukan persetujuan.</li>
            <li><strong>Pemasaran</strong>: rekomendasi yang dipersonalisasi dan kampanye. Memerlukan persetujuan.</li>
        </ul>
        <p>Anda dapat mengubah pilihan kapan saja melalui <strong>Cookie Settings</strong> di footer.</p>

        <h2>6. Penyimpanan & Retensi</h2>
        <ul>
            <li><strong>Akun aktif</strong>: data disimpan selama akun aktif.</li>
            <li><strong>Riwayat tonton</strong>: maksimum 24 bulan setelah akun terakhir aktif.</li>
            <li><strong>Log keamanan & audit</strong>: 12 bulan untuk kepatuhan dan investigasi insiden.</li>
            <li><strong>Data pembayaran (di pihak Midtrans)</strong>: sesuai retensi PCI-DSS Midtrans.</li>
            <li><strong>Setelah penghapusan akun</strong>: data dianonimisasi dalam 30 hari, kecuali yang wajib disimpan untuk kewajiban hukum (mis. faktur pajak — 10 tahun).</li>
        </ul>

        <h2>7. Hak Anda Sebagai Subjek Data</h2>
        <p>Sesuai Pasal 5–14 UU PDP, Anda berhak:</p>
        <ul>
            <li><strong>Akses</strong>: meminta salinan data pribadi yang kami simpan tentang Anda.</li>
            <li><strong>Koreksi</strong>: memperbaiki data yang tidak akurat melalui halaman Profile.</li>
            <li><strong>Penghapusan</strong>: meminta penghapusan akun dan data pribadi terkait.</li>
            <li><strong>Portabilitas</strong>: menerima data Anda dalam format yang umum dapat dibaca mesin.</li>
            <li><strong>Pembatasan</strong>: meminta pembatasan pemrosesan tertentu.</li>
            <li><strong>Keberatan</strong>: menolak pemrosesan yang berbasis kepentingan sah atau pemasaran.</li>
            <li><strong>Penarikan persetujuan</strong>: mencabut persetujuan kapan saja, tanpa memengaruhi pemrosesan sebelumnya yang sah.</li>
        </ul>
        <p>
            Pengguna terdaftar dapat menjalankan sebagian besar hak ini secara mandiri melalui
            <a href="{{ url('/privacy') }}">halaman Privasi & Data Saya</a>:
        </p>
        <ul>
            <li><strong>Ekspor data</strong> (portabilitas) — unduh seluruh data Anda dalam format JSON.</li>
            <li><strong>Hapus akun</strong> (penghapusan) — penghapusan permanen akun dan data terkait.</li>
            <li><strong>Koreksi</strong> — edit profil melalui halaman <a href="{{ route('profile.show') }}">Profile</a>.</li>
        </ul>
        <p>
            Untuk hak lain (akses penuh, pembatasan, keberatan, penarikan persetujuan
            spesifik), kirim permintaan ke <a href="mailto:privacy@flik.id">privacy@flik.id</a>.
            Kami akan merespons paling lambat <strong>30 hari kerja</strong>.
            Anda juga berhak mengadu ke <strong>Kementerian Komunikasi dan Digital Republik Indonesia</strong>.
        </p>

        <h2>8. Keamanan</h2>
        <ul>
            <li>Kata sandi di-hash dengan bcrypt; data sensitif kunci API dienkripsi at-rest.</li>
            <li>Transport HTTPS (TLS 1.2+) di seluruh endpoint publik.</li>
            <li>2FA tersedia untuk semua akun.</li>
            <li>Audit log untuk seluruh tindakan administratif.</li>
            <li>DRM AES-128 + token JWT untuk mencegah pembajakan stream.</li>
        </ul>
        <p>Tidak ada sistem yang 100% aman. Jika terjadi insiden yang material, kami akan memberi tahu Anda dan otoritas terkait dalam <strong>3 × 24 jam</strong> sesuai UU PDP.</p>

        <h2>9. Anak di Bawah Umur</h2>
        <p>Layanan tidak ditujukan untuk anak di bawah <strong>13 tahun</strong>. Pengguna 13–18 tahun memerlukan persetujuan orang tua/wali. Konten dewasa dilindungi <strong>kontrol orang tua</strong> berbasis PIN.</p>

        <h2>10. Transfer Data Lintas Batas</h2>
        <p>Beberapa pemroses (mis. penyedia AI, Pusher, Mailchimp) dapat memproses data di luar Indonesia. Kami memastikan transfer didasarkan pada perlindungan setara dengan UU PDP melalui Klausa Kontrak Standar atau persetujuan eksplisit Anda.</p>

        <h2>11. Perubahan Kebijakan</h2>
        <p>Kami dapat memperbarui kebijakan ini. Perubahan material akan diberitahukan melalui email dan/atau pemberitahuan dalam aplikasi minimal <strong>14 hari</strong> sebelum berlaku. Tanggal "Terakhir diperbarui" di atas mencerminkan revisi terbaru.</p>

        <h2>12. Kontak</h2>
        <p>
            <strong>Pengendali Data</strong>: FLiK — Rumah Sinema Indonesia<br>
            <strong>Email DPO / Privasi</strong>: <a href="mailto:privacy@flik.id">privacy@flik.id</a><br>
            <strong>Email Umum</strong>: <a href="mailto:hello@flik.id">hello@flik.id</a>
        </p>
    </div>

    {{-- ════════════════════════════════════════════════════════════ --}}
    {{-- ENGLISH                                                       --}}
    {{-- ════════════════════════════════════════════════════════════ --}}
    <div x-show="lang === 'en'" x-cloak>
        <p>
            FLiK ("we", "FLiK", "the Service") is a film streaming platform operated from
            Indonesia. This Privacy Policy explains how we collect, use, share, and protect
            your personal data, in compliance with <strong>Indonesian Personal Data Protection
            Law No. 27/2022 (UU PDP)</strong> and, for users in the European Economic Area,
            the principles of the <strong>GDPR (EU 2016/679)</strong>.
        </p>

        <h2>1. Data We Collect</h2>
        <h3>a. Data you provide directly</h3>
        <ul>
            <li><strong>Account</strong>: name, email, password (hashed), optional profile photo.</li>
            <li><strong>Profile & preferences</strong>: onboarding-quiz answers, mood, watchlist, ratings, comments.</li>
            <li><strong>Payment</strong>: chosen subscription plan and transaction history. <em>Card / e-wallet data is never stored by FLiK</em> — it is processed directly by Midtrans.</li>
            <li><strong>Communications</strong>: customer-support messages and optional newsletter signup.</li>
        </ul>

        <h3>b. Data collected automatically</h3>
        <ul>
            <li><strong>Viewing activity</strong>: titles watched, duration, last position, device.</li>
            <li><strong>Technical</strong>: IP address, device + browser type, OS, app version.</li>
            <li><strong>Approximate location</strong>: country/city derived from IP via MaxMind GeoLite2 (for territorial-licence enforcement).</li>
            <li><strong>Cookies & local storage</strong>: see Cookies section below.</li>
        </ul>

        <h2>2. Purposes of Use</h2>
        <ul>
            <li><strong>Service delivery</strong>: streaming, watchlist, resume history, recommendations.</li>
            <li><strong>AI personalisation</strong>: recommendations, summaries, semantic search, subtitle translation.</li>
            <li><strong>Security & anti-fraud</strong>: suspicious-login detection, brute-force protection, audit logs.</li>
            <li><strong>Transactional comms</strong>: payment receipts, security alerts, policy changes.</li>
            <li><strong>Marketing comms</strong> (optional, separate consent): newsletter, campaigns, recommendations.</li>
            <li><strong>Legal compliance</strong>: tax obligations, copyright protection, lawful requests.</li>
        </ul>

        <h2>3. Legal Basis</h2>
        <ul>
            <li><strong>Contract performance</strong>: for the core Service (UU PDP Art. 20(2)(b) / GDPR Art. 6(1)(b)).</li>
            <li><strong>Consent</strong>: for optional features such as analytics, marketing, and AI personalisation.</li>
            <li><strong>Legal obligation</strong>: for tax, disputes, or lawful authority requests.</li>
            <li><strong>Legitimate interest</strong>: for platform security and fraud prevention.</li>
        </ul>

        <h2>4. Third Parties (Data Processors)</h2>
        <p>We rely on the following processors acting on our behalf:</p>
        <ul>
            <li><strong>Midtrans</strong> (PT Midtrans) — payment processing (cards, e-wallets, bank transfer). Data: name, email, transaction amount. <a href="https://midtrans.com/privacy-policy" target="_blank" rel="noopener">Midtrans policy</a>.</li>
            <li><strong>Google LLC</strong> — <em>Sign in with Google</em> OAuth. Data: name, email, public profile photo. <a href="https://policies.google.com/privacy" target="_blank" rel="noopener">Google policy</a>.</li>
            <li><strong>AI providers</strong> (when AI features are active) — text/audio/image processing for AI features: recommendations, summaries, plot explanations, subtitle transcription, translation, sentiment classification, chatbot. The provider in use depends on admin configuration at any given time, including:
                <ul>
                    <li>OpenAI (gpt-4o, whisper) — <a href="https://openai.com/policies/privacy-policy" target="_blank" rel="noopener">privacy</a></li>
                    <li>Anthropic (Claude) — <a href="https://www.anthropic.com/legal/privacy" target="_blank" rel="noopener">privacy</a></li>
                    <li>Google AI (Gemini) — <a href="https://policies.google.com/privacy" target="_blank" rel="noopener">privacy</a></li>
                    <li>DeepSeek, Mistral, Groq, or any other OpenAI-compatible provider selected by the admin.</li>
                </ul>
                We send only the content relevant to a given task (e.g. review text for sentiment classification, subtitle text for translation). User identities are not attached to prompts; user IDs are hashed on our side.
            </li>
            <li><strong>MaxMind</strong> — GeoLite2 database for country detection from IP (local lookup, no data sent to MaxMind).</li>
            <li><strong>Mailchimp</strong> (if enabled) — newsletter delivery based on your separate consent.</li>
            <li><strong>Bunny.net / CDN provider</strong> — video and image asset delivery (the provider may see your IP for operational purposes).</li>
            <li><strong>Pusher</strong> (if enabled) — real-time messaging for the Watch Party feature.</li>
            <li><strong>Wikipedia & DuckDuckGo</strong> — open knowledge sources for the AI chatbot (only the search query is sent, no user identity).</li>
        </ul>

        <h2>5. Cookies & Local Storage</h2>
        <p>Three categories:</p>
        <ul>
            <li><strong>Necessary</strong>: login session, CSRF token, theme preference. Cannot be disabled.</li>
            <li><strong>Analytics</strong>: aggregate usage stats for product improvement. Requires consent.</li>
            <li><strong>Marketing</strong>: personalised recommendations and campaigns. Requires consent.</li>
        </ul>
        <p>You can change your choice at any time via <strong>Cookie Settings</strong> in the footer.</p>

        <h2>6. Storage & Retention</h2>
        <ul>
            <li><strong>Active account</strong>: kept while the account is active.</li>
            <li><strong>Watch history</strong>: up to 24 months after the account's last activity.</li>
            <li><strong>Security & audit logs</strong>: 12 months for compliance and incident response.</li>
            <li><strong>Payment data (held by Midtrans)</strong>: per Midtrans PCI-DSS retention.</li>
            <li><strong>After account deletion</strong>: data anonymised within 30 days, except records mandated by law (e.g. tax invoices — 10 years).</li>
        </ul>

        <h2>7. Your Rights as a Data Subject</h2>
        <p>Under UU PDP Articles 5–14 (and GDPR Art. 15–22 where applicable), you may exercise:</p>
        <ul>
            <li><strong>Access</strong>: request a copy of the personal data we hold about you.</li>
            <li><strong>Rectification</strong>: correct inaccurate data via your Profile page.</li>
            <li><strong>Erasure</strong>: request deletion of your account and associated personal data.</li>
            <li><strong>Portability</strong>: receive your data in a commonly machine-readable format.</li>
            <li><strong>Restriction</strong>: limit specific processing activities.</li>
            <li><strong>Objection</strong>: oppose processing based on legitimate interest or marketing.</li>
            <li><strong>Withdraw consent</strong>: at any time, without affecting prior lawful processing.</li>
        </ul>
        <p>
            Registered users can exercise most of these rights themselves via the
            <a href="{{ url('/privacy') }}">Privacy & My Data</a> page:
        </p>
        <ul>
            <li><strong>Data export</strong> (portability) — download all your data as JSON.</li>
            <li><strong>Delete account</strong> (erasure) — permanent deletion of your account and associated data.</li>
            <li><strong>Rectification</strong> — edit your details on the <a href="{{ route('profile.show') }}">Profile</a> page.</li>
        </ul>
        <p>
            For other rights (full access, restriction, objection, granular consent
            withdrawal) write to <a href="mailto:privacy@flik.id">privacy@flik.id</a>.
            We respond within <strong>30 working days</strong>.
            You may also lodge a complaint with the <strong>Indonesian Ministry of Communication and Digital Affairs</strong>.
        </p>

        <h2>8. Security</h2>
        <ul>
            <li>Passwords are bcrypt-hashed; sensitive secrets such as API keys are encrypted at rest.</li>
            <li>HTTPS (TLS 1.2+) on all public endpoints.</li>
            <li>2FA available for every account.</li>
            <li>Audit logs for every administrative action.</li>
            <li>AES-128 DRM + JWT tokens to prevent stream piracy.</li>
        </ul>
        <p>No system is 100% secure. In the event of a material breach we will notify affected users and the relevant authority within <strong>72 hours</strong> per UU PDP.</p>

        <h2>9. Minors</h2>
        <p>The Service is not directed at children under <strong>13</strong>. Users between 13 and 18 require parental consent. Adult content is gated by a PIN-based <strong>parental control</strong>.</p>

        <h2>10. Cross-Border Transfers</h2>
        <p>Some processors (AI providers, Pusher, Mailchimp) may process data outside Indonesia. We rely on Standard Contractual Clauses or your explicit consent and only where the destination provides protection equivalent to UU PDP.</p>

        <h2>11. Changes to this Policy</h2>
        <p>We may update this policy. Material changes will be communicated by email and/or in-app notice at least <strong>14 days</strong> before taking effect. The "Last updated" date above reflects the most recent revision.</p>

        <h2>12. Contact</h2>
        <p>
            <strong>Data Controller</strong>: FLiK — Rumah Sinema Indonesia<br>
            <strong>DPO / Privacy email</strong>: <a href="mailto:privacy@flik.id">privacy@flik.id</a><br>
            <strong>General email</strong>: <a href="mailto:hello@flik.id">hello@flik.id</a>
        </p>
    </div>

</x-legal-page>
