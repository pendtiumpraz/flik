{{--
    Custom maintenance page rendered by App\Http\Middleware\CheckCustomMaintenance
    when the singleton MaintenanceState is enabled and the requester can't bypass.

    Standalone HTML (NOT extending app layout) — the app might be down for
    DB/asset reasons and the layout imports Vite + Alpine + the sidebar
    component, so depending on it would create a circular failure mode.

    Variables in scope:
      $message         — operator-supplied notice, plaintext (escaped by Blade)
      $scheduledUntil  — Carbon|null; drives the live countdown when set
--}}<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Sedang Pemeliharaan — FLiK</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;700;800&family=Inter:wght@400;500&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; }
        body {
            font-family: 'Inter', sans-serif;
            background:
                radial-gradient(ellipse at top, rgba(197,165,90,0.08), transparent 60%),
                radial-gradient(ellipse at bottom, rgba(197,165,90,0.04), transparent 50%),
                #0a0a0a;
            color: #e5e5e5;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .container {
            max-width: 560px;
            width: 100%;
            text-align: center;
        }
        .logo {
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            font-size: 40px;
            letter-spacing: 6px;
            color: #C5A55A;
            margin-bottom: 4px;
        }
        .tagline {
            font-size: 11px;
            color: #777;
            letter-spacing: 3px;
            text-transform: uppercase;
            margin-bottom: 48px;
        }
        .icon-wrap {
            width: 96px;
            height: 96px;
            border-radius: 50%;
            background: linear-gradient(135deg, rgba(197,165,90,0.15), rgba(197,165,90,0.05));
            border: 1px solid rgba(197,165,90,0.3);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 28px;
            position: relative;
        }
        .icon-wrap::after {
            content: '';
            position: absolute;
            inset: -8px;
            border-radius: 50%;
            border: 1px solid rgba(197,165,90,0.1);
            animation: pulse 2.4s ease-in-out infinite;
        }
        @keyframes pulse {
            0%, 100% { transform: scale(1);   opacity: 0.6; }
            50%      { transform: scale(1.1); opacity: 0; }
        }
        h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 12px;
            color: #fff;
        }
        .lead {
            font-size: 15px;
            color: #aaa;
            line-height: 1.6;
            margin-bottom: 24px;
        }
        .message {
            background: #1a1a1a;
            border: 1px solid #2a2a2a;
            border-left: 3px solid #C5A55A;
            border-radius: 8px;
            padding: 16px 20px;
            font-size: 14px;
            color: #ddd;
            text-align: left;
            white-space: pre-wrap;
            line-height: 1.6;
            margin-bottom: 28px;
        }
        .countdown {
            background: rgba(197,165,90,0.08);
            border: 1px solid rgba(197,165,90,0.2);
            border-radius: 10px;
            padding: 18px;
            margin-bottom: 28px;
        }
        .countdown-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: #C5A55A;
            margin-bottom: 8px;
        }
        .countdown-time {
            font-family: 'Outfit', sans-serif;
            font-size: 32px;
            font-weight: 700;
            color: #fff;
            font-variant-numeric: tabular-nums;
        }
        .countdown-target {
            font-size: 11px;
            color: #777;
            margin-top: 4px;
        }
        .actions {
            display: flex;
            gap: 12px;
            justify-content: center;
            margin-top: 8px;
        }
        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.2s;
            border: 1px solid transparent;
            display: inline-block;
            cursor: pointer;
            font-family: 'Inter', sans-serif;
        }
        .btn-gold {
            background: #C5A55A;
            color: #000;
        }
        .btn-gold:hover { background: #d4b76a; }
        .btn-ghost {
            background: transparent;
            border-color: #333;
            color: #999;
        }
        .btn-ghost:hover {
            border-color: #555;
            color: #fff;
        }
        .footer {
            margin-top: 56px;
            font-size: 11px;
            color: #555;
        }
        .footer a {
            color: #888;
            text-decoration: none;
            border-bottom: 1px dashed #444;
        }
        .footer a:hover { color: #C5A55A; border-color: #C5A55A; }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">FLiK</div>
        <div class="tagline">Rumah Sinema Indonesia</div>

        <div class="icon-wrap">
            <svg width="44" height="44" fill="none" stroke="#C5A55A" stroke-width="1.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 0 0-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 0 0-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 0 0-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 0 0-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 0 0 1.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065Z"/>
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
            </svg>
        </div>

        <h1>Sedang dalam pemeliharaan</h1>
        <p class="lead">Kami sedang melakukan beberapa peningkatan untuk pengalaman menonton yang lebih baik. Mohon coba lagi sebentar lagi.</p>

        @if(!empty($message))
            <div class="message">{{ $message }}</div>
        @endif

        @if(!empty($scheduledUntil))
            @php
                // Render ISO 8601 string so JS can parse it without ambiguity.
                $targetIso = $scheduledUntil instanceof \Carbon\Carbon
                    ? $scheduledUntil->toIso8601String()
                    : (string) $scheduledUntil;
                $targetHuman = $scheduledUntil instanceof \Carbon\Carbon
                    ? $scheduledUntil->format('d M Y, H:i')
                    : (string) $scheduledUntil;
            @endphp
            <div class="countdown" id="countdown-card" data-target="{{ $targetIso }}">
                <div class="countdown-label">Diperkirakan kembali dalam</div>
                <div class="countdown-time" id="countdown-time">—</div>
                <div class="countdown-target">Target: {{ $targetHuman }}</div>
            </div>
        @endif

        <div class="actions">
            <a href="javascript:location.reload()" class="btn btn-gold">Coba Lagi</a>
            <a href="https://twitter.com/" class="btn btn-ghost" rel="noopener" target="_blank">Status Twitter</a>
        </div>

        <div class="footer">
            Butuh bantuan? Hubungi <a href="mailto:support@flik.id">support&#64;flik.id</a>
        </div>
    </div>

    @if(!empty($scheduledUntil))
        <script>
            (function () {
                var card = document.getElementById('countdown-card');
                var slot = document.getElementById('countdown-time');
                if (!card || !slot) return;
                var target = new Date(card.getAttribute('data-target')).getTime();
                if (isNaN(target)) return;

                function tick () {
                    var now = Date.now();
                    var diff = Math.max(0, target - now);
                    var s = Math.floor(diff / 1000);
                    var h = Math.floor(s / 3600); s -= h * 3600;
                    var m = Math.floor(s / 60);   s -= m * 60;
                    function pad (n) { return n < 10 ? '0' + n : '' + n; }
                    slot.textContent = pad(h) + ':' + pad(m) + ':' + pad(s);

                    if (diff <= 0) {
                        // Target passed — auto-refresh so the user lands back on the
                        // live site as soon as the admin disables the switch.
                        location.reload();
                    }
                }
                tick();
                setInterval(tick, 1000);
            })();
        </script>
    @endif
</body>
</html>
