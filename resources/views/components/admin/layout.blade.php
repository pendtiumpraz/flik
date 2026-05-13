<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>FLiK Admin — {{ $title ?? 'Dashboard' }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: #0f0f0f; color: #e5e5e5; min-height: 100vh; }
        h1, h2, h3, h4, h5, h6 { font-family: 'Outfit', sans-serif; }

        /* Sidebar */
        .admin-sidebar {
            position: fixed; top: 0; left: 0; bottom: 0; width: 250px;
            background: #1a1a1a; border-right: 1px solid #2a2a2a;
            display: flex; flex-direction: column; z-index: 40;
            transition: transform 0.3s ease;
        }
        .admin-sidebar .logo { padding: 20px 24px; font-family: 'Outfit'; font-size: 24px; font-weight: 700; color: #C5A55A; letter-spacing: 2px; border-bottom: 1px solid #2a2a2a; }
        .admin-sidebar .logo span { font-size: 11px; color: #666; font-weight: 400; display: block; letter-spacing: 0; margin-top: 2px; }
        .admin-sidebar nav { padding: 16px 12px; flex: 1; overflow-y: auto; }
        .admin-sidebar .nav-label { font-size: 10px; text-transform: uppercase; letter-spacing: 1.5px; color: #555; padding: 12px 12px 6px; font-weight: 600; }
        .admin-sidebar .nav-link {
            display: flex; align-items: center; gap: 10px; padding: 10px 12px;
            border-radius: 8px; font-size: 14px; color: #999; text-decoration: none;
            transition: all 0.2s; margin-bottom: 2px;
        }
        .admin-sidebar .nav-link:hover { background: #252525; color: #e5e5e5; }
        .admin-sidebar .nav-link.active { background: rgba(197,165,90,0.15); color: #C5A55A; font-weight: 500; }
        .admin-sidebar .nav-link svg { width: 18px; height: 18px; flex-shrink: 0; }
        .admin-sidebar .nav-footer { padding: 16px 12px; border-top: 1px solid #2a2a2a; }

        /* Main Content */
        .admin-main { margin-left: 250px; min-height: 100vh; }
        .admin-topbar {
            position: sticky; top: 0; z-index: 30;
            background: rgba(15,15,15,0.95); backdrop-filter: blur(8px);
            padding: 16px 32px; border-bottom: 1px solid #2a2a2a;
            display: flex; align-items: center; justify-content: space-between;
        }
        .admin-topbar h1 { font-size: 20px; font-weight: 600; }
        .admin-content { padding: 24px 32px; }

        /* Cards */
        .stat-card {
            background: #1a1a1a; border: 1px solid #2a2a2a; border-radius: 12px;
            padding: 20px 24px; transition: border-color 0.2s;
        }
        .stat-card:hover { border-color: #C5A55A; }
        .stat-card .label { font-size: 12px; color: #777; text-transform: uppercase; letter-spacing: 1px; }
        .stat-card .value { font-family: 'Outfit'; font-size: 32px; font-weight: 700; color: #fff; margin-top: 4px; }
        .stat-card .icon { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; }

        /* Table */
        .admin-table { width: 100%; border-collapse: collapse; }
        .admin-table th { padding: 12px 16px; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #666; border-bottom: 1px solid #2a2a2a; font-weight: 600; }
        .admin-table td { padding: 12px 16px; border-bottom: 1px solid #1f1f1f; font-size: 14px; vertical-align: middle; }
        .admin-table tr:hover td { background: #1a1a1a; }

        /* Buttons */
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 500; border: none; cursor: pointer; transition: all 0.2s; text-decoration: none; }
        .btn-gold { background: #C5A55A; color: #000; }
        .btn-gold:hover { background: #d4b76a; }
        .btn-ghost { background: transparent; border: 1px solid #333; color: #999; }
        .btn-ghost:hover { border-color: #555; color: #fff; }
        .btn-danger { background: transparent; border: 1px solid #dc2626; color: #dc2626; }
        .btn-danger:hover { background: #dc2626; color: #fff; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }

        /* Form */
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-size: 13px; font-weight: 500; color: #aaa; margin-bottom: 6px; }
        .form-input {
            width: 100%; padding: 10px 14px; background: #1a1a1a; border: 1px solid #2a2a2a;
            border-radius: 8px; color: #e5e5e5; font-size: 14px; font-family: 'Inter';
            transition: border-color 0.2s;
        }
        .form-input:focus { outline: none; border-color: #C5A55A; }
        .form-input::placeholder { color: #555; }
        textarea.form-input { min-height: 100px; resize: vertical; }

        /* Badge */
        .badge { display: inline-flex; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .badge-gold { background: rgba(197,165,90,0.2); color: #C5A55A; }
        .badge-green { background: rgba(34,197,94,0.2); color: #22c55e; }
        .badge-blue { background: rgba(59,130,246,0.2); color: #3b82f6; }

        /* Grid */
        .grid-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; }

        /* Flash messages */
        .flash-success { background: rgba(34,197,94,0.15); border: 1px solid rgba(34,197,94,0.3); color: #22c55e; padding: 12px 20px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; }

        /* Checkbox toggle */
        .toggle { position: relative; width: 44px; height: 24px; }
        .toggle input { opacity: 0; width: 0; height: 0; }
        .toggle .slider {
            position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0;
            background: #333; border-radius: 24px; transition: 0.3s;
        }
        .toggle .slider:before {
            content: ""; position: absolute; height: 18px; width: 18px; left: 3px; bottom: 3px;
            background: white; border-radius: 50%; transition: 0.3s;
        }
        .toggle input:checked + .slider { background: #C5A55A; }
        .toggle input:checked + .slider:before { transform: translateX(20px); }

        /* Mobile */
        .mobile-toggle { display: none; }
        @media (max-width: 768px) {
            .admin-sidebar { transform: translateX(-100%); }
            .admin-sidebar.open { transform: translateX(0); }
            .admin-main { margin-left: 0; }
            .admin-topbar { padding: 12px 16px; }
            .admin-content { padding: 16px; }
            .mobile-toggle { display: flex; }
            .mobile-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 35; }
        }
        /* Alpine x-cloak: hide until Alpine initialises */
        [x-cloak] { display: none !important; }
    </style>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body>
    <!-- Sidebar -->
    <aside class="admin-sidebar" id="sidebar">
        <div class="logo">
            FLIK <span>Admin Panel</span>
        </div>
        <nav>
            <div class="nav-label">Menu</div>
            <a href="{{ route('admin.dashboard') }}" class="nav-link {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
                Dashboard
            </a>

            <div class="nav-label" style="margin-top:16px">Content</div>
            <a href="{{ route('admin.movies.index') }}" class="nav-link {{ request()->routeIs('admin.movies.*') ? 'active' : '' }}">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 4v16M17 4v16M3 8h4m10 0h4M3 12h18M3 16h4m10 0h4M4 20h16a1 1 0 001-1V5a1 1 0 00-1-1H4a1 1 0 00-1 1v14a1 1 0 001 1z"/></svg>
                Movies
            </a>
            <a href="{{ route('admin.genres.index') }}" class="nav-link {{ request()->routeIs('admin.genres.*') ? 'active' : '' }}">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                Genres
            </a>
            <a href="{{ route('admin.casts.index') }}" class="nav-link {{ request()->routeIs('admin.casts.*') ? 'active' : '' }}">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                Cast
            </a>

            <div class="nav-label" style="margin-top:16px">System</div>
            <a href="{{ route('admin.users.index') }}" class="nav-link {{ request()->routeIs('admin.users.*') ? 'active' : '' }}">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                Users
            </a>
            <a href="{{ route('admin.banners.index') }}" class="nav-link {{ request()->routeIs('admin.banners.*') ? 'active' : '' }}">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                Banners
            </a>
            @if (\Illuminate\Support\Facades\Route::has('admin.api-keys.index'))
                <a href="{{ route('admin.api-keys.index') }}" class="nav-link {{ request()->routeIs('admin.api-keys.*') ? 'active' : '' }}">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                    API Keys
                </a>
            @endif

            <div class="nav-label" style="margin-top:16px">Intelligence</div>
            <a href="{{ route('admin.ai.index') }}" class="nav-link {{ request()->routeIs('admin.ai.index') || request()->routeIs('admin.ai.store') || request()->routeIs('admin.ai.update') || request()->routeIs('admin.ai.toggle') || request()->routeIs('admin.ai.destroy') || request()->routeIs('admin.ai.test') ? 'active' : '' }}">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                AI Providers
            </a>
            <a href="{{ route('admin.ai.usage') }}" class="nav-link {{ request()->routeIs('admin.ai.usage') ? 'active' : '' }}">
                <x-icon name="lightning" :size="18" />
                AI Usage
            </a>
            <a href="{{ route('admin.audit-logs.index') }}" class="nav-link {{ request()->routeIs('admin.audit-logs.*') ? 'active' : '' }}">
                <x-icon name="info" :size="18" />
                Audit Logs
            </a>
            <a href="{{ route('admin.sentiment.index') }}" class="nav-link {{ request()->routeIs('admin.sentiment.*') ? 'active' : '' }}">
                <x-icon name="star" :size="18" />
                Sentiment Dashboard
            </a>
            <a href="{{ route('admin.comments.queue') }}" class="nav-link {{ request()->routeIs('admin.comments.*') ? 'active' : '' }}">
                <x-icon name="trophy" :size="18" />
                Comment Queue
            </a>
            @if (\Illuminate\Support\Facades\Route::has('admin.churn.dashboard'))
                <a href="{{ route('admin.churn.dashboard') }}" class="nav-link {{ request()->routeIs('admin.churn.*') ? 'active' : '' }}">
                    <x-icon name="fire" :size="18" />
                    Churn Risk
                </a>
            @endif
            @if (\Illuminate\Support\Facades\Route::has('admin.director-analyses.index'))
                <a href="{{ route('admin.director-analyses.index') }}" class="nav-link {{ request()->routeIs('admin.director-analyses.*') ? 'active' : '' }}">
                    <x-icon name="user-circle" :size="18" />
                    Director Analyses
                </a>
            @endif
            <a href="{{ route('admin.pitch-deck') }}" class="nav-link {{ request()->routeIs('admin.pitch-deck') ? 'active' : '' }}">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                Pitch Deck
            </a>

            @if (\Illuminate\Support\Facades\Route::has('admin.movies.encoding-status') || \Illuminate\Support\Facades\Route::has('admin.movies.index'))
                <div class="nav-label" style="margin-top:16px">Distribution</div>
                <a href="{{ route('admin.movies.index') }}" class="nav-link {{ request()->routeIs('admin.movies.encoding-status') || request()->routeIs('admin.movies.upload-master') ? 'active' : '' }}">
                    <x-icon name="server" :size="18" />
                    Encoding Jobs
                </a>
            @endif

            @if (\Illuminate\Support\Facades\Route::has('admin.insights.content-gap')
                 || \Illuminate\Support\Facades\Route::has('admin.insights.pricing')
                 || \Illuminate\Support\Facades\Route::has('admin.marketing-ops.email-subjects')
                 || \Illuminate\Support\Facades\Route::has('admin.marketing-ops.cs-reply'))
                <div class="nav-label" style="margin-top:16px">Marketing</div>
                @if (\Illuminate\Support\Facades\Route::has('admin.insights.content-gap'))
                    <a href="{{ route('admin.insights.content-gap') }}" class="nav-link {{ request()->routeIs('admin.insights.content-gap') ? 'active' : '' }}">
                        <x-icon name="sparkles" :size="18" />
                        Content Gap Analysis
                    </a>
                @endif
                @if (\Illuminate\Support\Facades\Route::has('admin.insights.pricing'))
                    <a href="{{ route('admin.insights.pricing') }}" class="nav-link {{ request()->routeIs('admin.insights.pricing') ? 'active' : '' }}">
                        <x-icon name="cog" :size="18" />
                        Pricing Optimization
                    </a>
                @endif
                @if (\Illuminate\Support\Facades\Route::has('admin.marketing-ops.email-subjects'))
                    <a href="{{ route('admin.marketing-ops.email-subjects') }}" class="nav-link {{ request()->routeIs('admin.marketing-ops.email-subjects*') ? 'active' : '' }}">
                        <x-icon name="lightning" :size="18" />
                        Email A/B Subjects
                    </a>
                @endif
                @if (\Illuminate\Support\Facades\Route::has('admin.marketing-ops.cs-reply'))
                    <a href="{{ route('admin.marketing-ops.cs-reply') }}" class="nav-link {{ request()->routeIs('admin.marketing-ops.cs-reply*') ? 'active' : '' }}">
                        <x-icon name="chat" :size="18" />
                        CS Reply Drafter
                    </a>
                @endif
            @endif

            @if (\Illuminate\Support\Facades\Route::has('admin.revenue.dashboard')
                 || \Illuminate\Support\Facades\Route::has('admin.geo.distribution'))
                <div class="nav-label" style="margin-top:16px">Business</div>
                @if (\Illuminate\Support\Facades\Route::has('admin.revenue.dashboard'))
                    <a href="{{ route('admin.revenue.dashboard') }}" class="nav-link {{ request()->routeIs('admin.revenue.*') ? 'active' : '' }}">
                        <x-icon name="coin" :size="18" />
                        Revenue Dashboard
                    </a>
                @endif
                @if (\Illuminate\Support\Facades\Route::has('admin.geo.distribution'))
                    <a href="{{ route('admin.geo.distribution') }}" class="nav-link {{ request()->routeIs('admin.geo.*') ? 'active' : '' }}">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" style="width:18px;height:18px;flex-shrink:0"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M3.6 9h16.8M3.6 15h16.8M11.5 3a16.5 16.5 0 000 18M12.5 3a16.5 16.5 0 010 18M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        Geo Distribution
                    </a>
                @endif
            @endif
        </nav>

        <div class="nav-footer">
            <a href="/" class="nav-link">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                Back to Site
            </a>
        </div>
    </aside>

    <!-- Main -->
    <main class="admin-main">
        <div class="admin-topbar">
            <div style="display:flex;align-items:center;gap:12px">
                <button class="mobile-toggle btn btn-ghost btn-sm" onclick="document.getElementById('sidebar').classList.toggle('open')">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <h1>{{ $title ?? 'Dashboard' }}</h1>
            </div>
            <div style="display:flex;align-items:center;gap:12px">
                <span style="font-size:13px;color:#777">{{ auth()->user()->name }}</span>
                <div style="width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#C5A55A,#E8D5A3);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:#000">
                    {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                </div>
            </div>
        </div>

        <div class="admin-content">
            @if(session('success'))
                <div class="flash-success">✅ {{ session('success') }}</div>
            @endif

            {{ $slot }}
        </div>
    </main>

    <script>
        // Close sidebar on mobile when clicking overlay
        document.addEventListener('click', function(e) {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth <= 768 && sidebar.classList.contains('open') && !sidebar.contains(e.target) && !e.target.closest('.mobile-toggle')) {
                sidebar.classList.remove('open');
            }
        });
    </script>
</body>
</html>
