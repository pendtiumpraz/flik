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

    {{-- Alpine.js is bundled inside resources/js/app.js (single instance);
         the CDN <script> was removed to stop the "multiple Alpine instances"
         double-init that broke every x-show + dropdown. --}}
    @vite(['resources/js/app.js'])

    {{-- Expose Pusher + role context to JS so the bell can subscribe to the
         right private channels. Falls back to polling when PUSHER_KEY is null. --}}
    @auth
        @php
            // Graceful role lookup — if the `roles` relation method is missing
            // (e.g. peer ROLE swarm #1 hasn't shipped yet) we still expose an
            // empty array so the bell falls back to subscribing only to the
            // `all-admins` channel rather than throwing.
            $authRoles = [];
            try {
                $u = auth()->user();
                if ($u && method_exists($u, 'roles')) {
                    $authRoles = $u->roles()->pluck('name')->all();
                }
            } catch (\Throwable $e) {
                $authRoles = [];
            }
        @endphp
        <script>
            window.PUSHER_KEY      = @json(config('broadcasting.connections.pusher.key'));
            window.PUSHER_CLUSTER  = @json(config('broadcasting.connections.pusher.options.cluster'));
            window.AUTH_USER_ROLES = @json($authRoles);
        </script>
    @endauth

    @stack('styles')
</head>
<body>
    @php
        // ── Permission-aware sidebar rendering ─────────────────────
        // Drives the entire nav off config/admin_menu.php so the Menu Matrix
        // audit page mirrors what users actually see.
        //
        // GRACEFUL DEGRADATION CONTRACT — three layers of fallback so this
        // works even if peer ROLE swarms #1 (Permission model + seeds) and
        // #2 (User::hasPermission + Gate::define) have not landed yet:
        //
        //   1. permission === null            → always visible.
        //   2. permissions table missing      → fall back to the existing
        //                                       `admin` gate so admins still
        //                                       see everything they used to.
        //   3. Gate has no definition for the → same fall back to `admin`,
        //      named permission yet              so we don't silently hide
        //                                        a brand-new nav entry just
        //                                        because the matching Gate
        //                                        line hasn't been written.
        //
        // Once ROLE #1/#2 ship, swap the body of the closure for a plain
        // `auth()->user()?->can($perm)` — but keep the null-permission
        // shortcut and the unknown-Gate fallback for safety.
        $user = auth()->user();

        $permissionsTableExists = \Illuminate\Support\Facades\Schema::hasTable('permissions');

        $canSeeMenuItem = function (?string $perm) use ($user, $permissionsTableExists): bool {
            if ($perm === null) {
                return true;
            }
            if ($user === null) {
                return false;
            }
            // No permissions infrastructure yet → keep legacy "admin sees all" behaviour.
            if (! $permissionsTableExists || ! \Illuminate\Support\Facades\Gate::has($perm)) {
                return \Illuminate\Support\Facades\Gate::allows('admin');
            }
            return $user->can($perm);
        };

        $routeExists = fn (?string $name): bool => $name !== null && \Illuminate\Support\Facades\Route::has($name);

        $sections = config('admin_menu.sections', []);
    @endphp

    <!-- Sidebar -->
    <aside class="admin-sidebar" id="sidebar">
        <div class="logo">
            FLIK <span>Admin Panel</span>
        </div>
        <nav>
            @foreach($sections as $sectionKey => $section)
                @php
                    // Build the list of items the current user is allowed to see
                    // AND whose route is registered. A section with zero visible
                    // items hides its header entirely (no orphaned labels).
                    $visibleItems = collect($section['items'] ?? [])
                        ->filter(fn ($item) => $routeExists($item['route'] ?? null)
                            && $canSeeMenuItem($item['permission'] ?? null))
                        ->values();
                @endphp
                @if($visibleItems->isNotEmpty())
                    <div class="nav-label" @if(! $loop->first) style="margin-top:16px" @endif>
                        {{ $section['label'] ?? ucfirst($sectionKey) }}
                    </div>
                    @foreach($visibleItems as $item)
                        @php
                            // Active-state — LONGEST MATCH WINS.
                            // Previously every item whose base prefix matched the
                            // current route lit up (so on /admin/movies/subtitles
                            // BOTH "Movies" and "Subtitles" would activate; and
                            // "Dashboard" lit up on every admin page because its
                            // base = "admin" matched admin.*).
                            //
                            // Strategy: find which item in the WHOLE sidebar has
                            // the longest prefix-match against the current route
                            // name. Only that one is active. The match is computed
                            // once per request and memoised in a config-time
                            // closure below.
                            if (! isset($flikActiveItemRoute)) {
                                $currentRoute = optional(request()->route())->getName() ?? '';
                                $bestRoute = null;
                                $bestLen = -1;
                                foreach (config('admin_menu.sections', []) as $sec) {
                                    foreach ($sec['items'] ?? [] as $candidate) {
                                        $cand = $candidate['route'] ?? null;
                                        if (! $cand) continue;
                                        // Exact name OR prefix that matches a real
                                        // segment boundary (so admin.movies doesn't
                                        // match admin.movies-search).
                                        $isMatch = $currentRoute === $cand
                                            || str_starts_with($currentRoute, $cand . '.');
                                        if ($isMatch && strlen($cand) > $bestLen) {
                                            $bestRoute = $cand;
                                            $bestLen = strlen($cand);
                                        }
                                    }
                                }
                                $flikActiveItemRoute = $bestRoute;
                            }
                            $isActive = ($item['route'] === $flikActiveItemRoute);
                        @endphp
                        <a href="{{ route($item['route']) }}"
                           class="nav-link {{ $isActive ? 'active' : '' }}">
                            <x-icon :name="$item['icon'] ?? 'cog'" :size="18" />
                            {{ $item['label'] }}
                        </a>
                    @endforeach
                @endif
            @endforeach
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
                {{-- Realtime notification bell (Pusher when configured, polling fallback) --}}
                <x-admin.notification-bell />

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

    @stack('scripts')
</body>
</html>
