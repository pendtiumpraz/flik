@php
    /**
     * Friendly Indonesian 429 page.
     *
     * Laravel's ThrottleRequests middleware injects a `Retry-After` response
     * header (seconds until the limit resets). We surface it to the user as a
     * live countdown driven by Alpine.js, with a polite explanation of *why*
     * the throttle fired so they don't think the site is broken.
     *
     * Inherits the project's minimal error layout (no nav, no auth checks)
     * so it works for unauthenticated requests like POST /login or
     * /newsletter that get hit by their own dedicated limiters.
     */
    /** @var \Symfony\Component\HttpFoundation\Response|null $exceptionResponse */
    $retryAfter = (int) (
        (request()->headers->get('Retry-After') ?: 0)
        ?: (isset($exception) && method_exists($exception, 'getHeaders')
            ? ($exception->getHeaders()['Retry-After'] ?? 0)
            : 0)
    );

    // Sane bounds: at least 1s so the countdown isn't "0 detik" forever,
    // capped at 1h so a misconfigured limiter can't render "3600+" seconds.
    $retryAfter = max(1, min($retryAfter ?: 30, 3600));
@endphp
<!DOCTYPE html>
<html lang="id" class="bg-[#0a0a0a]">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Terlalu Banyak Permintaan — FLiK</title>

    {{-- Inline styles only — keep this page bulletproof even if Vite assets
         are unreachable (the app might be under attack right now). --}}
    <style>
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        html, body {
            margin: 0;
            padding: 0;
            min-height: 100vh;
            background: #0a0a0a;
            color: #e5e5e5;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }
        .wrap {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .card {
            max-width: 520px;
            width: 100%;
            background: #111;
            border: 1px solid rgba(197, 165, 90, 0.3);
            border-radius: 16px;
            padding: 36px 32px;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.6);
        }
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 999px;
            font-size: 12px;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            background: rgba(197, 165, 90, 0.12);
            color: #C5A55A;
            border: 1px solid rgba(197, 165, 90, 0.4);
            margin-bottom: 18px;
        }
        h1 {
            font-size: 28px;
            margin: 0 0 12px;
            color: #fff;
            font-weight: 600;
        }
        p {
            margin: 0 0 16px;
            line-height: 1.6;
            color: #b5b5b5;
        }
        .countdown {
            margin: 24px 0 28px;
            padding: 18px 12px;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.06);
            font-size: 15px;
        }
        .countdown strong {
            display: block;
            font-size: 38px;
            line-height: 1.1;
            color: #C5A55A;
            margin: 4px 0 6px;
            font-variant-numeric: tabular-nums;
        }
        .actions { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
        a.btn, button.btn {
            display: inline-block;
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            border: 1px solid transparent;
            transition: opacity 0.15s ease, transform 0.15s ease;
        }
        a.btn:hover, button.btn:hover { opacity: 0.85; transform: translateY(-1px); }
        a.btn:disabled, button.btn:disabled { opacity: 0.4; cursor: not-allowed; transform: none; }
        .btn-primary { background: #C5A55A; color: #0a0a0a; }
        .btn-secondary { background: transparent; color: #e5e5e5; border-color: rgba(255, 255, 255, 0.2); }
        .small { font-size: 12px; color: #777; margin-top: 18px; }
    </style>

    {{-- Alpine ships from CDN already; we only need it for the countdown.
         If it fails to load, the static "Coba lagi dalam X detik" message
         and the manual Refresh button below still work. --}}
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body>
    <div class="wrap">
        <div
            class="card"
            x-data="{
                remaining: {{ $retryAfter }},
                init() {
                    const tick = () => {
                        if (this.remaining <= 0) return;
                        this.remaining -= 1;
                        if (this.remaining > 0) setTimeout(tick, 1000);
                    };
                    setTimeout(tick, 1000);
                },
                get human() {
                    if (this.remaining <= 0) return 'sekarang';
                    if (this.remaining < 60) return this.remaining + ' detik';
                    const m = Math.floor(this.remaining / 60);
                    const s = this.remaining % 60;
                    return m + ' menit' + (s ? ' ' + s + ' detik' : '');
                }
            }"
        >
            <span class="badge">HTTP 429</span>
            <h1>Terlalu Banyak Permintaan</h1>

            <p>
                Anda telah mengirim permintaan terlalu cepat. Untuk menjaga
                layanan tetap stabil bagi semua pengguna, kami membatasi
                jumlah permintaan dalam waktu singkat.
            </p>

            <div class="countdown" role="status" aria-live="polite">
                Coba lagi dalam
                <strong x-text="human">{{ $retryAfter }} detik</strong>
                <span x-show="remaining <= 0" style="color:#7BC47F">Anda dapat mencoba lagi sekarang.</span>
            </div>

            <div class="actions">
                <button
                    class="btn btn-primary"
                    type="button"
                    x-bind:disabled="remaining > 0"
                    x-on:click="window.location.reload()"
                >
                    <span x-show="remaining > 0">Tunggu sebentar…</span>
                    <span x-show="remaining <= 0" x-cloak>Muat ulang halaman</span>
                </button>
                <a class="btn btn-secondary" href="{{ url('/') }}">Kembali ke beranda</a>
            </div>

            <p class="small">
                Jika Anda yakin ini adalah kesalahan, silakan hubungi tim
                dukungan kami dan sertakan kode error <code>429</code>.
            </p>
        </div>
    </div>

    {{-- Belt-and-braces: native meta refresh as a fallback in case JS is
         disabled. Adds 2s slack so the limiter window has fully reset. --}}
    <noscript>
        <meta http-equiv="refresh" content="{{ $retryAfter + 2 }}">
    </noscript>
</body>
</html>
