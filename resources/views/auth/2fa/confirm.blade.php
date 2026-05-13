<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Konfirmasi 2FA — FLiK</title>
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
{{--
    Standalone confirm view — usually unused because the confirm form is
    embedded in /2fa/setup. Kept here so callers can redirect to a
    dedicated screen if they prefer (e.g. from an account-security audit
    flow that asks the user to re-prove they still have their phone).
--}}
<body class="bg-[#0a0a0a] text-white min-h-screen flex items-center justify-center px-4">
    <div class="w-full max-w-md">
        <div class="rounded-2xl p-8" style="background:rgba(0,0,0,0.75);backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,0.08)">
            <h1 class="font-heading text-2xl font-bold">Konfirmasi 2FA</h1>
            <p class="text-gray-500 text-sm mt-2">Masukkan kode 6 digit dari aplikasi authenticator kamu.</p>

            @if($errors->any())
                <div class="mt-4 p-3 rounded-lg text-sm" style="background:rgba(220,38,38,0.15);border:1px solid rgba(220,38,38,0.3);color:#f87171">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('2fa.confirm') }}" class="mt-6 space-y-4">
                @csrf
                <input type="text"
                       name="code"
                       inputmode="numeric"
                       autocomplete="one-time-code"
                       pattern="[0-9]{6}"
                       maxlength="6"
                       required
                       autofocus
                       class="w-full px-4 py-3 rounded-xl text-2xl font-mono tracking-[0.5em] text-center text-white focus:outline-none focus:ring-2"
                       style="background:rgba(0,0,0,0.5);border:1px solid rgba(197,165,90,0.3)"
                       placeholder="000000">

                <button type="submit"
                        class="w-full py-3.5 rounded-xl text-sm font-bold text-black transition hover:scale-[1.02] active:scale-[0.98]"
                        style="background:linear-gradient(135deg,#C5A55A,#E8D5A3)">
                    Konfirmasi
                </button>
            </form>
        </div>
    </div>
</body>
</html>
