<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar — FLiK</title>
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

    <!-- Header -->
    <header class="relative z-10 px-8 py-6">
        <a href="/">
            <img src="{{ asset('img/flik-logo.png') }}" alt="FLiK" class="h-10 md:h-12">
        </a>
    </header>

    <!-- Register Form -->
    <main class="relative z-10 flex items-center justify-center px-4 pb-12" style="min-height:calc(100vh - 100px)">
        <div class="w-full max-w-md">
            <div class="rounded-2xl p-8 md:p-10" style="background:rgba(0,0,0,0.75);backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,0.08)">

                <h1 class="font-heading text-3xl font-bold text-white">Buat Akun</h1>
                <p class="text-gray-500 text-sm mt-2">Gratis 30 hari pertama. Batalkan kapan saja.</p>

                <form action="/register" method="post" class="mt-8 space-y-4">
                    @csrf

                    <!-- Name -->
                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-400 mb-1.5">Nama Lengkap</label>
                        <input class="w-full px-4 py-3 rounded-xl text-sm text-white placeholder-gray-600 transition-all focus:outline-none focus:ring-2"
                            style="background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.1)"
                            type="text" name="name" id="name" value="{{ old('name') }}" placeholder="John Doe" required>
                        @error('name')
                            <p class="mt-1 text-xs" style="color:#f87171">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Username -->
                    <div>
                        <label for="username" class="block text-sm font-medium text-gray-400 mb-1.5">Username</label>
                        <input class="w-full px-4 py-3 rounded-xl text-sm text-white placeholder-gray-600 transition-all focus:outline-none focus:ring-2"
                            style="background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.1)"
                            type="text" name="username" id="username" value="{{ old('username') }}" placeholder="johndoe" required>
                        @error('username')
                            <p class="mt-1 text-xs" style="color:#f87171">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Email -->
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-400 mb-1.5">Email</label>
                        <input class="w-full px-4 py-3 rounded-xl text-sm text-white placeholder-gray-600 transition-all focus:outline-none focus:ring-2"
                            style="background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.1)"
                            type="email" name="email" id="email" value="{{ old('email') }}" placeholder="nama@email.com" required>
                        @error('email')
                            <p class="mt-1 text-xs" style="color:#f87171">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Password -->
                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-400 mb-1.5">Password</label>
                        <input class="w-full px-4 py-3 rounded-xl text-sm text-white placeholder-gray-600 transition-all focus:outline-none focus:ring-2"
                            style="background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.1)"
                            type="password" name="password" id="password" placeholder="Minimal 8 karakter" required>
                    </div>

                    <!-- Submit -->
                    <button type="submit"
                        class="w-full py-3.5 rounded-xl text-sm font-bold text-black transition-all hover:scale-[1.02] hover:shadow-lg active:scale-[0.98]"
                        style="background:linear-gradient(135deg,#C5A55A,#E8D5A3)">
                        Daftar Sekarang
                    </button>
                </form>

                <!-- Divider -->
                <div class="flex items-center gap-4 my-6">
                    <div class="flex-1 h-px" style="background:rgba(255,255,255,0.1)"></div>
                    <span class="text-xs text-gray-600">atau</span>
                    <div class="flex-1 h-px" style="background:rgba(255,255,255,0.1)"></div>
                </div>

                <!-- Google -->
                <a href="/login/google"
                   class="flex items-center justify-center gap-3 w-full py-3 rounded-xl text-sm font-medium text-white transition-all hover:scale-[1.02]"
                   style="background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.1)">
                    <svg class="w-5 h-5" viewBox="0 0 24 24"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/></svg>
                    Daftar dengan Google
                </a>

                <p class="text-center text-sm text-gray-500 mt-6">
                    Sudah punya akun?
                    <a href="{{ route('login') }}" class="font-semibold hover:underline" style="color:#C5A55A">Masuk</a>
                </p>
            </div>
        </div>
    </main>
</body>
</html>
