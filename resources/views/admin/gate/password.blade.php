<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>Protected — FLiK Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', sans-serif;
            background: radial-gradient(ellipse at top, #1a1410 0%, #0a0a0a 60%);
            color: #e5e5e5;
            min-height: 100vh;
            display: flex; align-items: center; justify-content: center;
            padding: 20px;
        }
        .gate-card {
            width: 100%;
            max-width: 420px;
            background: linear-gradient(180deg, #1a1a1a 0%, #141210 100%);
            border: 1px solid rgba(197,165,90,0.25);
            border-radius: 16px;
            padding: 36px 32px 32px;
            box-shadow: 0 24px 60px -12px rgba(0,0,0,0.7), 0 0 0 1px rgba(197,165,90,0.05);
            position: relative;
            overflow: hidden;
        }
        .gate-card::before {
            content: '';
            position: absolute; top: -1px; left: -1px; right: -1px; height: 4px;
            background: linear-gradient(90deg, #C5A55A, #E8D5A3, #C5A55A);
        }
        .gate-icon {
            width: 64px; height: 64px;
            background: linear-gradient(135deg, #C5A55A, #E8D5A3);
            border-radius: 16px;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 20px;
            box-shadow: 0 8px 24px -6px rgba(197,165,90,0.5);
        }
        .gate-title {
            font-family: 'Outfit', sans-serif;
            font-size: 22px; font-weight: 700;
            color: #fff;
            text-align: center;
            margin-bottom: 6px;
            letter-spacing: -0.3px;
        }
        .gate-sub {
            font-size: 13px;
            color: #888;
            text-align: center;
            margin-bottom: 24px;
            line-height: 1.55;
        }
        .gate-label {
            display: block;
            font-size: 11px;
            color: #C5A55A;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        .gate-input {
            width: 100%;
            padding: 14px 16px;
            background: #0a0a0a;
            border: 1px solid #2a2a2a;
            border-radius: 10px;
            color: #fff;
            font-size: 15px;
            font-family: 'Inter', sans-serif;
            letter-spacing: 0.5px;
            transition: all 0.15s ease;
        }
        .gate-input:focus {
            outline: none;
            border-color: #C5A55A;
            box-shadow: 0 0 0 3px rgba(197,165,90,0.15);
        }
        .gate-btn {
            width: 100%;
            padding: 14px;
            margin-top: 16px;
            background: linear-gradient(135deg, #C5A55A, #E8D5A3);
            color: #0a0a0a;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s ease;
            font-family: 'Inter', sans-serif;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .gate-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 20px -6px rgba(197,165,90,0.5);
        }
        .gate-error {
            background: rgba(239,68,68,0.15);
            border: 1px solid rgba(239,68,68,0.3);
            color: #fca5a5;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 16px;
            text-align: center;
        }
        .gate-back {
            display: block;
            text-align: center;
            color: #666;
            font-size: 12px;
            margin-top: 20px;
            text-decoration: none;
            transition: color 0.15s ease;
        }
        .gate-back:hover { color: #C5A55A; }
        .gate-note {
            margin-top: 18px;
            padding: 12px 14px;
            background: rgba(197,165,90,0.06);
            border-left: 3px solid rgba(197,165,90,0.4);
            border-radius: 0 6px 6px 0;
            font-size: 11px;
            color: #aaa;
            line-height: 1.55;
        }
    </style>
</head>
<body>
    <div class="gate-card">
        <div class="gate-icon">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#0a0a0a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
        </div>
        <h1 class="gate-title">Halaman Terproteksi</h1>
        <p class="gate-sub">
            Konten ini berisi informasi sensitif. Masukkan password untuk melanjutkan.
        </p>

        @if(!empty($error))
            <div class="gate-error">⚠ {{ $error }}</div>
        @endif

        <form method="POST" action="{{ $target }}" autocomplete="off">
            @csrf
            <label class="gate-label" for="pgate">Password Akses</label>
            <input type="password"
                   id="pgate"
                   name="_pgate"
                   class="gate-input"
                   placeholder="••••••••"
                   required
                   autofocus
                   autocomplete="off">
            <button type="submit" class="gate-btn">
                🔓 Unlock
            </button>
        </form>

        <a href="{{ route('admin.dashboard') }}" class="gate-back">← Kembali ke dashboard admin</a>

        <div class="gate-note">
            💡 <strong>Catatan:</strong> Password di-reset tiap reload halaman.
            Admin bisa ganti password ini di <code style="color:#C5A55A">/admin/settings</code> →
            key <code style="color:#C5A55A">pages.protected_password</code>.
        </div>
    </div>
</body>
</html>
