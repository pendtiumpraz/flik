<x-layout title="Kebijakan Keamanan — FLiK"
          description="Kebijakan disclosure keamanan FLiK. Pelajari ruang lingkup, SLA, dan cara melaporkan kerentanan secara bertanggung jawab.">
    <div class="min-h-screen bg-black pt-24 pb-20">
        <div class="container mx-auto px-4 md:px-8 lg:px-16 max-w-3xl">

            <!-- Heading -->
            <div class="text-center mb-12">
                <p class="text-xs uppercase tracking-[0.3em] mb-3" style="color:#C5A55A">Security</p>
                <h1 class="font-heading text-3xl md:text-5xl font-bold text-white">Kebijakan Disclosure Keamanan</h1>
                <p class="text-gray-400 mt-3 text-base">Versi 1.0 — terakhir diperbarui {{ \Carbon\Carbon::parse('2026-05-12')->translatedFormat('d F Y') }}</p>
            </div>

            <!-- Content card -->
            <article class="rounded-2xl p-8 md:p-10 space-y-8"
                     style="background: rgba(255,255,255,0.03); border: 1px solid rgba(197,165,90,0.18)">

                <section>
                    <h2 class="font-heading text-xl md:text-2xl font-semibold mb-3" style="color:#E8D5A3">Versi yang Didukung</h2>
                    <p class="text-gray-300 leading-relaxed">
                        Hanya deployment <code class="px-1.5 py-0.5 rounded text-xs" style="background:rgba(197,165,90,0.12);color:#E8D5A3">main</code> terkini yang menerima patch keamanan. Branch lama tidak didukung.
                    </p>
                </section>

                <section>
                    <h2 class="font-heading text-xl md:text-2xl font-semibold mb-3" style="color:#E8D5A3">Cara Melapor</h2>
                    <ul class="space-y-2 text-gray-300 leading-relaxed list-disc list-inside">
                        <li>Email <a href="mailto:security@flik.example.com" class="underline hover:text-white" style="color:#C5A55A">security@flik.example.com</a> (PGP tersedia di <a href="/.well-known/pgp-key.txt" class="underline hover:text-white" style="color:#C5A55A">/.well-known/pgp-key.txt</a>).</li>
                        <li>Atau buka draft GitHub Security Advisory.</li>
                        <li>Atau gunakan <a href="{{ route('security.report.form') }}" class="underline hover:text-white" style="color:#C5A55A">formulir laporan</a> di situs ini.</li>
                    </ul>
                </section>

                <section>
                    <h2 class="font-heading text-xl md:text-2xl font-semibold mb-3" style="color:#E8D5A3">SLA Respons</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="rounded-lg p-4" style="background:rgba(197,165,90,0.06);border:1px solid rgba(197,165,90,0.15)">
                            <p class="text-xs uppercase tracking-wider text-gray-400 mb-1">Triage awal</p>
                            <p class="text-2xl font-bold" style="color:#C5A55A">48 jam</p>
                        </div>
                        <div class="rounded-lg p-4" style="background:rgba(197,165,90,0.06);border:1px solid rgba(197,165,90,0.15)">
                            <p class="text-xs uppercase tracking-wider text-gray-400 mb-1">Patch (critical)</p>
                            <p class="text-2xl font-bold" style="color:#C5A55A">14 hari</p>
                        </div>
                    </div>
                </section>

                <section>
                    <h2 class="font-heading text-xl md:text-2xl font-semibold mb-3" style="color:#E8D5A3">Ruang Lingkup (In-Scope)</h2>
                    <ul class="space-y-1.5 text-gray-300 leading-relaxed list-disc list-inside">
                        <li>Authentication bypass &amp; privilege escalation</li>
                        <li>SQL injection, XSS, CSRF, SSRF, RCE, IDOR</li>
                        <li>DRM bypass / paywall bypass</li>
                        <li>PII leakage</li>
                    </ul>
                </section>

                <section>
                    <h2 class="font-heading text-xl md:text-2xl font-semibold mb-3" style="color:#E8D5A3">Di Luar Lingkup (Out-of-Scope)</h2>
                    <ul class="space-y-1.5 text-gray-300 leading-relaxed list-disc list-inside">
                        <li>DoS / serangan volumetrik</li>
                        <li>Serangan fisik</li>
                        <li>Social engineering</li>
                        <li>Laporan yang membutuhkan rangkaian interaksi user tanpa dampak nyata</li>
                    </ul>
                </section>

                <section>
                    <h2 class="font-heading text-xl md:text-2xl font-semibold mb-3" style="color:#E8D5A3">Bug Bounty</h2>
                    <p class="text-gray-300 leading-relaxed">
                        Saat ini belum ada program bounty tunai. Reporter yang valid akan kami cantumkan di
                        <span class="px-1.5 py-0.5 rounded text-xs" style="background:rgba(197,165,90,0.12);color:#E8D5A3">/security/hall-of-fame</span>.
                    </p>
                </section>

                <section>
                    <h2 class="font-heading text-xl md:text-2xl font-semibold mb-3" style="color:#E8D5A3">Safe Harbor</h2>
                    <p class="text-gray-300 leading-relaxed">
                        Kami tidak akan menempuh jalur hukum terhadap riset yang dilakukan dengan iktikad baik selama mengikuti kebijakan ini, tidak mengakses data user lain, tidak melakukan exfiltrasi, dan memberi waktu 90 hari sebelum publikasi.
                    </p>
                </section>
            </article>

            <!-- CTA -->
            <div class="text-center mt-10">
                <a href="{{ route('security.report.form') }}"
                   class="inline-flex items-center gap-2 px-6 py-3 rounded-lg font-semibold text-black transition-transform hover:scale-[1.02]"
                   style="background:linear-gradient(135deg,#C5A55A,#E8D5A3)">
                    Laporkan Kerentanan
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3" />
                    </svg>
                </a>
            </div>
        </div>
    </div>
</x-layout>
