<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 — FLiK</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800&family=Inter:wght@400;500&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', sans-serif;
            background: #0a0a0a;
            color: #e5e5e5;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            overflow: hidden;
        }
        .container { padding: 20px; }
        .code {
            font-family: 'Outfit', sans-serif;
            font-size: 120px;
            font-weight: 800;
            background: linear-gradient(135deg, #C5A55A, #E8D5A3, #C5A55A);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            line-height: 1;
            margin-bottom: 16px;
            animation: shimmer 3s ease-in-out infinite;
        }
        @keyframes shimmer {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        h1 { font-family: 'Outfit', sans-serif; font-size: 24px; font-weight: 600; margin-bottom: 12px; }
        p { color: #666; font-size: 15px; max-width: 400px; margin: 0 auto 32px; line-height: 1.6; }
        .btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 12px 28px; border-radius: 8px;
            font-size: 14px; font-weight: 600;
            text-decoration: none; transition: all 0.3s;
            background: #C5A55A; color: #000;
        }
        .btn:hover { background: #d4b76a; transform: translateY(-2px); }
        .bg-circle {
            position: fixed;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(197,165,90,0.05) 0%, transparent 70%);
        }
        .bg-circle:nth-child(1) { width: 600px; height: 600px; top: -200px; right: -200px; }
        .bg-circle:nth-child(2) { width: 400px; height: 400px; bottom: -100px; left: -100px; }
    </style>
</head>
<body>
    <div class="bg-circle"></div>
    <div class="bg-circle"></div>
    <div class="container">
        <div class="code">404</div>
        <h1>Halaman Tidak Ditemukan</h1>
        <p>Film yang kamu cari mungkin sudah tayang di bioskop lain. Yuk kembali ke halaman utama!</p>
        <a href="/" class="btn">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            Kembali ke FLiK
        </a>
    </div>
</body>
</html>
