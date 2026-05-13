<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifikasi 2FA — FLiK</title>
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
<body class="bg-[#0a0a0a] text-white min-h-screen relative overflow-hidden">

    <div class="fixed inset-0 z-0">
        <img src="{{ asset('img/login-bg.png') }}" alt="" class="w-full h-full object-cover" style="filter:brightness(0.3) saturate(0.7)">
        <div class="absolute inset-0" style="background:linear-gradient(180deg,rgba(0,0,0,0.5) 0%,rgba(0,0,0,0.85) 100%)"></div>
    </div>

    <div class="fixed top-0 left-1/2 -translate-x-1/2 w-[800px] h-[400px] opacity-10 z-0"
         style="background:radial-gradient(ellipse,rgba(197,165,90,0.5),transparent 70%)"></div>

    <header class="relative z-10 px-8 py-6">
        <a href="/">
            <img src="{{ asset('img/flik-logo.png') }}" alt="FLiK" class="h-10 md:h-12">
        </a>
    </header>

    <main class="relative z-10 flex items-center justify-center px-4" style="min-height:calc(100vh - 100px)">
        <div class="w-full max-w-md"
             x-data="{ mode: 'totp' }">
            <div class="rounded-2xl p-8 md:p-10" style="background:rgba(0,0,0,0.75);backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,0.08)">

                <div class="flex items-center gap-3 mb-2">
                    <div class="w-10 h-10 rounded-full flex items-center justify-center" style="background:rgba(197,165,90,0.15);border:1px solid rgba(197,165,90,0.3)">
                        <svg class="w-5 h-5" style="color:#C5A55A" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                        </svg>
                    </div>
                    <h1 class="font-heading text-2xl font-bold">Verifikasi 2FA</h1>
                </div>

                <p class="text-gray-500 text-sm mt-2" x-show="mode === 'totp'">
                    Masukkan kode 6-digit dari aplikasi authenticator.
                </p>
                <p class="text-gray-500 text-sm mt-2" x-show="mode === 'recovery'" x-cloak>
                    Masukkan salah satu kode pemulihan yang tersimpan.
                </p>

                @if($errors->any())
                    <div class="mt-4 p-3 rounded-lg text-sm" style="background:rgba(220,38,38,0.15);border:1px solid rgba(220,38,38,0.3);color:#f87171">
                        {{ $errors->first() }}
                    </div>
                @endif
                @if(session('error'))
                    <div class="mt-4 p-3 rounded-lg text-sm" style="background:rgba(220,38,38,0.15);border:1px solid rgba(220,38,38,0.3);color:#f87171">
                        {{ session('error') }}
                    </div>
                @endif

                <form method="POST" action="{{ route('2fa.verify') }}" class="mt-6 space-y-5">
                    @csrf

                    {{-- TOTP input --}}
                    <div x-show="mode === 'totp'">
                        <label class="block text-sm font-medium text-gray-400 mb-1.5">Kode 6-digit</label>
                        <input type="text"
                               name="code"
                               x-bind:disabled="mode !== 'totp'"
                               inputmode="numeric"
                               autocomplete="one-time-code"
                               pattern="[0-9]{6}"
                               maxlength="6"
                               x-bind:autofocus="mode === 'totp'"
                               class="w-full px-4 py-3 rounded-xl text-2xl font-mono tracking-[0.5em] text-center text-white focus:outline-none focus:ring-2"
                               style="background:rgba(0,0,0,0.5);border:1px solid rgba(197,165,90,0.3)"
                               placeholder="000000">
                    </div>

                    {{-- Recovery input --}}
                    <div x-show="mode === 'recovery'" x-cloak>
                        <label class="block text-sm font-medium text-gray-400 mb-1.5">Kode Pemulihan</label>
                        <input type="text"
                               name="code"
                               x-bind:disabled="mode !== 'recovery'"
                               autocomplete="off"
                               maxlength="32"
                               x-bind:autofocus="mode === 'recovery'"
                               class="w-full px-4 py-3 rounded-xl text-base font-mono tracking-widest text-center uppercase text-white focus:outline-none focus:ring-2"
                               style="background:rgba(0,0,0,0.5);border:1px solid rgba(197,165,90,0.3)"
                               placeholder="ABCDE12345">
                        <p class="mt-1.5 text-xs text-gray-500">Kode pemulihan hanya bisa dipakai sekali.</p>
                    </div>

                    <button type="submit"
                            class="w-full py-3.5 rounded-xl text-sm font-bold text-black transition hover:scale-[1.02] active:scale-[0.98]"
                            style="background:linear-gradient(135deg,#C5A55A,#E8D5A3)">
                        Verifikasi
                    </button>
                </form>

                <div class="mt-6 text-center text-sm">
                    <button type="button"
                            x-show="mode === 'totp'"
                            @click="mode = 'recovery'"
                            class="text-gray-500 hover:text-white transition">
                        HP hilang? <span style="color:#C5A55A" class="font-medium">Pakai kode pemulihan</span>
                    </button>
                    <button type="button"
                            x-show="mode === 'recovery'" x-cloak
                            @click="mode = 'totp'"
                            class="text-gray-500 hover:text-white transition">
                        ← Kembali ke kode authenticator
                    </button>
                </div>
            </div>

            <p class="text-center text-xs text-gray-600 mt-6">
                Bukan kamu? <a href="/" class="hover:underline" style="color:#C5A55A">Batal & keluar</a>
            </p>
        </div>
    </main>
</body>
</html>
