<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lupa Password - FLiK</title>
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
            <div class="rounded-2xl p-8 md:p-10" style="background:rgba(0,0,0,0.75);backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,0.08)">

                <h1 class="font-heading text-3xl font-bold text-white">Lupa Password?</h1>
                <p class="text-gray-500 text-sm mt-2">
                    Masukkan email akun kamu. Kami akan kirim tautan untuk reset password.
                </p>

                @if(session('status'))
                    <div class="mt-6 p-3 rounded-lg text-sm" style="background:rgba(34,197,94,0.12);border:1px solid rgba(34,197,94,0.3);color:#86efac">
                        {{ session('status') }}
                    </div>
                @endif

                @if($errors->any())
                    <div class="mt-6 p-3 rounded-lg text-sm" style="background:rgba(220,38,38,0.15);border:1px solid rgba(220,38,38,0.3);color:#f87171">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('password.email') }}" class="mt-8 space-y-5">
                    @csrf
                    <x-honeypot />

                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-400 mb-1.5">Email</label>
                        <input class="w-full px-4 py-3 rounded-xl text-sm text-white placeholder-gray-600 transition-all focus:outline-none focus:ring-2"
                            style="background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.1)"
                            type="email" name="email" id="email" value="{{ old('email') }}"
                            placeholder="nama@email.com" required autofocus>
                    </div>

                    {{-- Cloudflare Turnstile CAPTCHA (no-op when env keys absent). --}}
                    <x-captcha-turnstile action="password-reset" theme="dark" />

                    <button type="submit"
                        class="w-full py-3.5 rounded-xl text-sm font-bold text-black transition-all hover:scale-[1.02] hover:shadow-lg active:scale-[0.98]"
                        style="background:linear-gradient(135deg,#C5A55A,#E8D5A3)">
                        Kirim Tautan Reset
                    </button>
                </form>

                <p class="text-center text-sm text-gray-500 mt-6">
                    Ingat password kamu?
                    <a href="{{ route('login') }}" class="font-semibold hover:underline" style="color:#C5A55A">Masuk</a>
                </p>
            </div>
        </div>
    </main>
</body>
</html>
