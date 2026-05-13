<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifikasi Email - FLiK</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    @vite('resources/css/app.css')
    <style>
        body { font-family: 'Inter', sans-serif; }
        h1,h2,h3,.font-heading { font-family: 'Outfit', sans-serif; }
    </style>
</head>
<body class="bg-[#0a0a0a] text-white min-h-screen relative overflow-hidden">

    <!-- Background -->
    <div class="fixed inset-0 z-0">
        <img src="{{ asset('img/login-bg.png') }}" alt="" class="w-full h-full object-cover" style="filter:brightness(0.25) saturate(0.6)">
        <div class="absolute inset-0" style="background:linear-gradient(180deg,rgba(0,0,0,0.6) 0%,rgba(0,0,0,0.85) 100%)"></div>
    </div>

    <div class="fixed top-0 left-1/2 -translate-x-1/2 w-[800px] h-[400px] opacity-10 z-0"
         style="background:radial-gradient(ellipse,rgba(197,165,90,0.5),transparent 70%)"></div>

    <header class="relative z-10 px-8 py-6">
        <a href="/">
            <img src="{{ asset('img/flik-logo.png') }}" alt="FLiK" class="h-10 md:h-12">
        </a>
    </header>

    <main class="relative z-10 flex items-center justify-center px-4" style="min-height:calc(100vh - 100px)">
        <div class="w-full max-w-md">
            <div class="rounded-2xl p-8 md:p-10 text-center" style="background:rgba(0,0,0,0.75);backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,0.08)">

                {{-- Mail icon --}}
                <div class="mx-auto mb-6 w-16 h-16 rounded-full flex items-center justify-center"
                     style="background:linear-gradient(135deg,rgba(197,165,90,0.2),rgba(232,213,163,0.1));border:1px solid rgba(197,165,90,0.4)">
                    <svg class="w-8 h-8" fill="none" stroke="#C5A55A" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                    </svg>
                </div>

                <h1 class="font-heading text-2xl font-bold text-white">Verifikasi Email Kamu</h1>
                <p class="text-gray-400 text-sm mt-3 leading-relaxed">
                    Kami sudah mengirim tautan verifikasi ke
                    @auth
                        <span style="color:#C5A55A;font-weight:600">{{ auth()->user()->email }}</span>
                    @else
                        email kamu
                    @endauth
                    .<br>Klik tautan di email untuk mengaktifkan akun.
                </p>

                @if(session('success'))
                    <div class="mt-6 p-3 rounded-lg text-sm" style="background:rgba(34,197,94,0.12);border:1px solid rgba(34,197,94,0.3);color:#86efac">
                        {{ session('success') }}
                    </div>
                @endif

                @if(session('error'))
                    <div class="mt-6 p-3 rounded-lg text-sm" style="background:rgba(220,38,38,0.15);border:1px solid rgba(220,38,38,0.3);color:#f87171">
                        {{ session('error') }}
                    </div>
                @endif

                {{-- Resend form --}}
                <form action="{{ route('verification.send') }}" method="POST" class="mt-6">
                    @csrf
                    <button type="submit"
                        class="w-full py-3.5 rounded-xl text-sm font-bold text-black transition-all hover:scale-[1.02] hover:shadow-lg active:scale-[0.98]"
                        style="background:linear-gradient(135deg,#C5A55A,#E8D5A3)">
                        Kirim Ulang Tautan Verifikasi
                    </button>
                </form>

                <p class="text-xs text-gray-500 mt-5 leading-relaxed">
                    Tidak menerima email? Cek folder spam, atau klik tombol di atas untuk kirim ulang.
                    <br>Tautan kedaluwarsa setelah {{ (int) config('auth.verification.expire', 60) }} menit.
                </p>

                <div class="mt-6 pt-6 border-t" style="border-color:rgba(255,255,255,0.06)">
                    <form action="{{ route('logout') }}" method="POST" class="inline">
                        @csrf
                        <button type="submit" class="text-sm text-gray-500 hover:text-gray-300 transition-colors">
                            Keluar
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </main>
</body>
</html>
