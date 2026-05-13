<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aktifkan 2FA — FLiK</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    @vite('resources/css/app.css')
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        h1,h2,h3,.font-heading { font-family: 'Outfit', sans-serif; }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="bg-[#0a0a0a] text-white min-h-screen">

    <header class="px-8 py-6">
        <a href="/profile" class="inline-flex items-center gap-2 text-sm text-gray-400 hover:text-white transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            Kembali ke Profile
        </a>
    </header>

    <main class="max-w-2xl mx-auto px-4 py-8" x-data="{ step: 1, copied: false, copy(text) { navigator.clipboard.writeText(text); this.copied = true; setTimeout(() => this.copied = false, 1500); } }">
        <div class="mb-8">
            <h1 class="font-heading text-3xl font-bold">Aktifkan Two-Factor Authentication</h1>
            <p class="text-gray-500 text-sm mt-2">Lapisan keamanan ekstra untuk akun FLiK kamu.</p>
        </div>

        @if(session('error'))
            <div class="mb-4 p-3 rounded-lg text-sm" style="background:rgba(220,38,38,0.15);border:1px solid rgba(220,38,38,0.3);color:#f87171">
                {{ session('error') }}
            </div>
        @endif

        <div class="rounded-2xl p-6 md:p-8 mb-6" style="background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08)">
            <h2 class="font-heading text-xl font-bold mb-4">Langkah 1 — Scan QR di Authenticator</h2>
            <p class="text-sm text-gray-400 mb-4">Pakai Google Authenticator, Authy, 1Password, atau aplikasi TOTP apa saja.</p>

            <div class="flex flex-col md:flex-row gap-6 items-start">
                <div class="flex-shrink-0 p-4 bg-white rounded-xl">
                    {{-- Public QR encoder — privacy note: secret leaves the page only embedded in the otpauth URI to render the QR.
                         For higher-assurance deployments swap this for a self-hosted QR endpoint. --}}
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=220x220&data={{ urlencode($qrUri) }}"
                         alt="QR Code 2FA"
                         class="w-[220px] h-[220px] block">
                </div>

                <div class="flex-1 space-y-3 w-full">
                    <p class="text-xs text-gray-500 uppercase tracking-wide">Atau ketik manual</p>
                    <div class="flex gap-2">
                        <code class="flex-1 px-3 py-2 rounded-lg text-sm font-mono break-all"
                              style="background:rgba(0,0,0,0.5);border:1px solid rgba(197,165,90,0.3);color:#C5A55A">{{ $secret }}</code>
                        <button type="button"
                                @click="copy('{{ $secret }}')"
                                class="px-3 py-2 rounded-lg text-xs font-semibold text-black transition"
                                style="background:#C5A55A">
                            <span x-show="!copied">Salin</span>
                            <span x-show="copied" x-cloak>Tersalin</span>
                        </button>
                    </div>
                    <p class="text-xs text-gray-500">Algoritma: SHA1 · 6 digit · 30 detik</p>
                </div>
            </div>
        </div>

        <div class="rounded-2xl p-6 md:p-8 mb-6" style="background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08)">
            <h2 class="font-heading text-xl font-bold mb-2">Langkah 2 — Simpan Kode Pemulihan</h2>
            <p class="text-sm text-gray-400 mb-4">
                Catat 8 kode di bawah dan simpan di tempat aman (password manager / printout).
                Setiap kode hanya bisa dipakai <strong>satu kali</strong> kalau HP kamu hilang.
            </p>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-2 mb-4">
                @foreach($recoveryCodes as $code)
                    <code class="px-3 py-2 rounded-lg text-center text-sm font-mono"
                          style="background:rgba(0,0,0,0.5);border:1px solid rgba(255,255,255,0.1);color:#E8D5A3">{{ $code }}</code>
                @endforeach
            </div>

            <button type="button"
                    @click="copy('{{ implode("\n", $recoveryCodes) }}')"
                    class="text-xs px-3 py-1.5 rounded-lg transition"
                    style="background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.1);color:#C5A55A">
                <span x-show="!copied">Salin semua</span>
                <span x-show="copied" x-cloak>Tersalin</span>
            </button>
        </div>

        <div class="rounded-2xl p-6 md:p-8" style="background:rgba(255,255,255,0.04);border:1px solid rgba(197,165,90,0.2)">
            <h2 class="font-heading text-xl font-bold mb-4">Langkah 3 — Konfirmasi kode dari aplikasi</h2>

            <form method="POST" action="{{ route('2fa.confirm') }}" class="space-y-4">
                @csrf
                <div>
                    <label for="code" class="block text-sm font-medium text-gray-400 mb-1.5">Kode 6-digit</label>
                    <input type="text"
                           name="code"
                           id="code"
                           inputmode="numeric"
                           autocomplete="one-time-code"
                           pattern="[0-9]{6}"
                           maxlength="6"
                           required
                           autofocus
                           class="w-full px-4 py-3 rounded-xl text-2xl font-mono tracking-[0.5em] text-center text-white transition focus:outline-none focus:ring-2"
                           style="background:rgba(0,0,0,0.5);border:1px solid rgba(197,165,90,0.3)"
                           placeholder="000000">
                    @error('code')
                        <p class="mt-1.5 text-xs" style="color:#f87171">{{ $message }}</p>
                    @enderror
                </div>

                <button type="submit"
                        class="w-full py-3.5 rounded-xl text-sm font-bold text-black transition hover:scale-[1.02] active:scale-[0.98]"
                        style="background:linear-gradient(135deg,#C5A55A,#E8D5A3)">
                    Aktifkan 2FA
                </button>
            </form>
        </div>
    </main>
</body>
</html>
