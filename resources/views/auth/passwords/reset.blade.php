<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - FLiK</title>
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

    <main class="relative z-10 flex items-center justify-center px-4 pb-12" style="min-height:calc(100vh - 100px)">
        <div class="w-full max-w-md">
            <div class="rounded-2xl p-8 md:p-10" style="background:rgba(0,0,0,0.75);backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,0.08)">

                <h1 class="font-heading text-3xl font-bold text-white">Buat Password Baru</h1>
                <p class="text-gray-500 text-sm mt-2">Pilih password yang kuat dan unik untuk akun FLiK kamu.</p>

                @if($errors->any())
                    <div class="mt-6 p-3 rounded-lg text-sm" style="background:rgba(220,38,38,0.15);border:1px solid rgba(220,38,38,0.3);color:#f87171">
                        <ul class="list-disc list-inside space-y-1">
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('password.update') }}" class="mt-8 space-y-5">
                    @csrf
                    <x-honeypot />
                    <input type="hidden" name="token" value="{{ $token }}">

                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-400 mb-1.5">Email</label>
                        <input class="w-full px-4 py-3 rounded-xl text-sm text-white placeholder-gray-600 transition-all focus:outline-none focus:ring-2"
                            style="background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.1)"
                            type="email" name="email" id="email"
                            value="{{ old('email', $email ?? '') }}"
                            placeholder="nama@email.com" required>
                    </div>

                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-400 mb-1.5">Password Baru</label>
                        <input class="w-full px-4 py-3 rounded-xl text-sm text-white placeholder-gray-600 transition-all focus:outline-none focus:ring-2"
                            style="background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.1)"
                            type="password" name="password" id="password"
                            placeholder="Minimal 10 karakter, huruf besar+kecil+angka+simbol" required>
                        <p class="mt-1.5 text-xs text-gray-500">
                            Minimal 10 karakter dengan huruf besar, huruf kecil, angka, dan simbol. Tidak boleh password yang pernah bocor.
                        </p>
                    </div>

                    <div>
                        <label for="password_confirmation" class="block text-sm font-medium text-gray-400 mb-1.5">Konfirmasi Password</label>
                        <input class="w-full px-4 py-3 rounded-xl text-sm text-white placeholder-gray-600 transition-all focus:outline-none focus:ring-2"
                            style="background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.1)"
                            type="password" name="password_confirmation" id="password_confirmation"
                            placeholder="Ulangi password" required>
                    </div>

                    <button type="submit"
                        class="w-full py-3.5 rounded-xl text-sm font-bold text-black transition-all hover:scale-[1.02] hover:shadow-lg active:scale-[0.98]"
                        style="background:linear-gradient(135deg,#C5A55A,#E8D5A3)">
                        Reset Password
                    </button>
                </form>

                <p class="text-center text-sm text-gray-500 mt-6">
                    <a href="{{ route('login') }}" class="font-semibold hover:underline" style="color:#C5A55A">Kembali ke Masuk</a>
                </p>
            </div>
        </div>
    </main>
</body>
</html>
