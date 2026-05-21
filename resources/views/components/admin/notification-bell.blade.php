{{--
    Admin notification bell.

    Renders a small bell icon in the admin top bar with an unread-count badge,
    a click-to-open dropdown showing the most recent 10 notifications, and
    realtime updates via Echo/Pusher when configured (polls every 30s
    otherwise).

    The Alpine factory `adminNotifBell()` lives in
    `resources/js/admin-notifications.js` and is registered on the window.

    Route names follow the contract from peer NOTIF #1
    (`admin.notifications.*`). If those routes are not yet defined we fall
    back to plain string URLs so the bell still renders without errors.
--}}
@php
    // Defensive URL resolver — must NEVER throw, otherwise the bell will
    // 500 the entire admin layout. route() throws UrlGenerationException
    // when the parameter name doesn't match the route's actual binding
    // (e.g. our admin.notifications.read uses {adminNotification}, not
    // {notification}). Wrap every call in try/catch + fall back to the
    // literal URL pattern so the bell ALWAYS renders.
    $r = function (string $name, array $params = []) {
        try {
            if (! \Illuminate\Support\Facades\Route::has($name)) {
                return null;
            }
            return route($name, $params);
        } catch (\Throwable $e) {
            return null;
        }
    };

    // Build the per-notification "read" URL safely. The route parameter is
    // {adminNotification}; pass it under that name. If anything fails, fall
    // back to the literal path so the client-side {id} substitution still
    // works for the JS Alpine component.
    $readUrlTemplate = $r('admin.notifications.read', ['adminNotification' => '__ID__']);
    if ($readUrlTemplate) {
        $readUrlTemplate = str_replace('__ID__', '{id}', $readUrlTemplate);
    } else {
        $readUrlTemplate = '/admin/notifications/{id}/read';
    }

    $urls = [
        'list'        => $r('admin.notifications.index') ? $r('admin.notifications.index') . '?recent=1&limit=10' : '/admin/notifications?recent=1&limit=10',
        'unreadCount' => $r('admin.notifications.unread-count') ?? '/admin/notifications/unread-count',
        'readAll'     => $r('admin.notifications.read-all') ?? '/admin/notifications/read-all',
        'read'        => $readUrlTemplate,
        'indexPage'   => $r('admin.notifications.index') ?? '/admin/notifications',
    ];
@endphp

<div class="notif-bell-wrap"
     x-data="adminNotifBell()"
     x-init="urls = @js($urls); init()"
     @keydown.escape.window="open = false"
     @click.outside="open = false">

    {{-- Bell button --}}
    <button type="button"
            class="notif-bell-btn"
            :class="{ 'is-open': open, 'has-unread': unreadCount > 0 }"
            :aria-expanded="open.toString()"
            aria-haspopup="true"
            aria-label="Notifications"
            @click="toggle()">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/>
            <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>
        </svg>
        <span class="notif-bell-badge"
              x-cloak
              x-show="unreadCount > 0"
              x-text="unreadCount > 99 ? '99+' : unreadCount"></span>
        <span class="notif-bell-pulse" x-cloak x-show="unreadCount > 0"></span>
    </button>

    {{-- Dropdown panel --}}
    <div class="notif-bell-panel"
         x-cloak
         x-show="open"
         x-data="{ filter: 'all' }"
         x-transition:enter="notif-trans-enter"
         x-transition:enter-start="notif-trans-enter-start"
         x-transition:enter-end="notif-trans-enter-end"
         role="dialog"
         aria-label="Notifications">

        {{-- Header w/ gold accent + actions --}}
        <div class="notif-panel-header">
            <div class="notif-header-titles">
                <div class="notif-header-title-row">
                    <span class="notif-header-icon">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/></svg>
                    </span>
                    <strong>Notifications</strong>
                    <span x-cloak x-show="usingRealtime" class="notif-live-badge" title="Realtime via Pusher">
                        <span class="notif-live-dot"></span>LIVE
                    </span>
                </div>
                <div class="notif-header-sub">
                    <span x-text="unreadCount + ' unread'"></span>
                    <span x-cloak x-show="items.length > 0"> · <span x-text="items.length"></span> total</span>
                </div>
            </div>
            <div class="notif-header-actions">
                <button type="button" class="notif-icon-btn"
                        :title="muted ? 'Unmute sound' : 'Mute sound'"
                        @click="toggleMute()">
                    <template x-if="!muted">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/></svg>
                    </template>
                    <template x-if="muted">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><line x1="23" y1="9" x2="17" y2="15"/><line x1="17" y1="9" x2="23" y2="15"/></svg>
                    </template>
                </button>
            </div>
        </div>

        {{-- Filter chips --}}
        <div class="notif-filter-row">
            <button type="button" class="notif-chip" :class="{ 'is-active': filter === 'all' }" @click="filter = 'all'">
                All <span class="notif-chip-count" x-text="items.length"></span>
            </button>
            <button type="button" class="notif-chip" :class="{ 'is-active': filter === 'unread' }" @click="filter = 'unread'">
                Unread <span class="notif-chip-count" x-cloak x-show="unreadCount > 0" x-text="unreadCount"></span>
            </button>
            <div class="notif-filter-spacer"></div>
            <button type="button" class="notif-mark-all"
                    x-cloak x-show="unreadCount > 0" @click="markAllRead()">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                Mark all read
            </button>
        </div>

        {{-- Body --}}
        <div class="notif-panel-body">
            <template x-if="loading && items.length === 0">
                <div class="notif-skeleton">
                    <div class="notif-skel-row"></div>
                    <div class="notif-skel-row"></div>
                    <div class="notif-skel-row"></div>
                </div>
            </template>

            <template x-if="!loading && items.length === 0">
                <div class="notif-empty">
                    <div class="notif-empty-icon">
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
                    </div>
                    <div class="notif-empty-title">You're all caught up</div>
                    <div class="notif-empty-sub">New notifications will appear here.</div>
                </div>
            </template>

            <template x-if="!loading && items.length > 0 && filter === 'unread' && items.filter(i => !i.read).length === 0">
                <div class="notif-empty">
                    <div class="notif-empty-title">No unread items.</div>
                    <button type="button" class="notif-mini-link" @click="filter = 'all'">Show all →</button>
                </div>
            </template>

            <template x-for="item in items.filter(i => filter === 'all' || !i.read)" :key="item.id">
                <a href="#"
                   class="notif-item"
                   :class="{ 'is-read': item.read, [`sev-${item.severity || 'info'}`]: true }"
                   @click.prevent="markRead(item)">
                    <span class="notif-stripe"></span>
                    <span class="notif-cat-icon" :style="{ background: severityColor(item.severity) + '22', color: severityColor(item.severity) }"
                          x-html="categoryIcon(item.category)"></span>
                    <div class="notif-item-body">
                        <div class="notif-item-title">
                            <span x-text="item.title"></span>
                            <span x-cloak x-show="!item.read" class="notif-dot" aria-label="unread"></span>
                        </div>
                        <div class="notif-item-msg" x-text="snippet(item.message)"></div>
                        <div class="notif-item-meta">
                            <span class="notif-time" x-text="timeAgo(item.created_at)"></span>
                            <span x-cloak x-show="item.category" class="notif-cat" x-text="item.category"></span>
                        </div>
                    </div>
                </a>
            </template>
        </div>

        {{-- Footer --}}
        <div class="notif-panel-footer">
            <a href="{{ $urls['indexPage'] }}" class="notif-footer-link">
                <span>View all notifications</span>
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
        </div>
    </div>
</div>

{{-- Inline <script> instead of @push('scripts') — same reason as the
     style block above: @stack('scripts') in admin layout is already
     flushed by the time the bell renders in the body. --}}
<script>
    // Inject category-icon helper into the Alpine factory's prototype-ish
    // surface. The factory lives in resources/js/admin-notifications.js;
    // this extends it with a richer icon mapping w/o touching the JS file.
    document.addEventListener('alpine:init', () => {
        if (window.adminNotifBell && !window.__notifIconsPatched) {
            window.__notifIconsPatched = true;
            const ICONS = {
                'comment':       '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>',
                'user':          '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
                'payment':       '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>',
                'subscription':  '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>',
                'security':      '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
                'encoding':      '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>',
                'system':        '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
            };
            const generic = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
            window.notifCategoryIcon = function (category) {
                if (!category) return generic;
                const key = category.split('.')[0];
                return ICONS[key] || generic;
            };
        }
    });
</script>

{{-- Inline <style> instead of @push('styles') — @push only works when
     the @stack target hasn't been rendered yet. On admin pages the
     stack is in <head>, the bell renders in <body>, so by the time the
     bell pushes its CSS the head is already flushed → no styles apply.
     Inline style here is browser-tolerant and guaranteed to take effect. --}}
@once
    <div style="display:none">
        <style>
            /* ━━━ Admin notification bell — premium redesign ━━━ */
            .notif-bell-wrap { position: relative; }
            .notif-bell-btn {
                position: relative;
                background: transparent;
                border: 1px solid #2a2a2a;
                color: #999;
                width: 38px; height: 38px;
                border-radius: 50%;
                display: inline-flex;
                align-items: center; justify-content: center;
                cursor: pointer;
                transition: all 0.18s cubic-bezier(.4,0,.2,1);
            }
            .notif-bell-btn:hover {
                border-color: #C5A55A;
                color: #C5A55A;
                transform: translateY(-1px);
                box-shadow: 0 6px 14px -6px rgba(197,165,90,0.4);
            }
            .notif-bell-btn.is-open {
                border-color: #C5A55A;
                color: #C5A55A;
                background: linear-gradient(135deg, rgba(197,165,90,0.15), rgba(197,165,90,0.05));
                box-shadow: 0 0 0 3px rgba(197,165,90,0.1);
            }
            .notif-bell-btn.has-unread { color: #C5A55A; }
            .notif-bell-btn.has-unread svg { animation: notif-bell-shake 2.4s ease-in-out infinite; transform-origin: 50% 4px; }
            @keyframes notif-bell-shake {
                0%, 88%, 100% { transform: rotate(0); }
                90% { transform: rotate(-12deg); }
                92% { transform: rotate(10deg); }
                94% { transform: rotate(-8deg); }
                96% { transform: rotate(6deg); }
                98% { transform: rotate(-3deg); }
            }
            .notif-bell-badge {
                position: absolute;
                top: -3px; right: -3px;
                min-width: 18px; height: 18px; padding: 0 5px;
                background: linear-gradient(135deg, #ef4444, #dc2626);
                color: #fff;
                font-size: 10px; font-weight: 800;
                border-radius: 9px;
                display: inline-flex; align-items: center; justify-content: center;
                line-height: 1;
                border: 2px solid #0f0f0f;
                font-family: 'Inter', sans-serif;
                box-shadow: 0 0 0 0 rgba(239,68,68,0.5);
                animation: notif-badge-pulse 1.8s ease-out infinite;
            }
            @keyframes notif-badge-pulse {
                0% { box-shadow: 0 0 0 0 rgba(239,68,68,0.55); }
                70% { box-shadow: 0 0 0 7px rgba(239,68,68,0); }
                100% { box-shadow: 0 0 0 0 rgba(239,68,68,0); }
            }
            .notif-bell-pulse {
                position: absolute;
                top: -3px; right: -3px;
                width: 18px; height: 18px;
                border-radius: 9px;
                background: rgba(239,68,68,0.4);
                animation: notif-pulse-ring 1.8s ease-out infinite;
                pointer-events: none;
            }
            @keyframes notif-pulse-ring {
                0% { transform: scale(1); opacity: 1; }
                100% { transform: scale(2.2); opacity: 0; }
            }

            /* ── Panel ─────────────────────────────────────────── */
            .notif-bell-panel {
                position: absolute;
                top: calc(100% + 12px);
                right: 0;
                width: 380px;
                max-width: calc(100vw - 32px);
                background: linear-gradient(180deg, #1a1a1a 0%, #141414 100%);
                border: 1px solid rgba(197,165,90,0.18);
                border-radius: 14px;
                box-shadow: 0 24px 48px -8px rgba(0,0,0,0.65), 0 0 0 1px rgba(197,165,90,0.05);
                overflow: hidden;
                z-index: 60;
            }
            /* Caret pointer at top */
            .notif-bell-panel::before {
                content: '';
                position: absolute;
                top: -7px; right: 14px;
                width: 14px; height: 14px;
                background: #1a1a1a;
                border-left: 1px solid rgba(197,165,90,0.18);
                border-top: 1px solid rgba(197,165,90,0.18);
                transform: rotate(45deg);
            }

            .notif-trans-enter { transition: all 0.22s cubic-bezier(.4,0,.2,1); }
            .notif-trans-enter-start { opacity: 0; transform: translateY(-8px) scale(0.98); }
            .notif-trans-enter-end   { opacity: 1; transform: translateY(0) scale(1); }

            /* ── Header ────────────────────────────────────────── */
            .notif-panel-header {
                display: flex; align-items: flex-start; justify-content: space-between;
                padding: 14px 16px 12px;
                background: linear-gradient(180deg, rgba(197,165,90,0.08), transparent);
                border-bottom: 1px solid rgba(197,165,90,0.1);
            }
            .notif-header-titles { display: flex; flex-direction: column; gap: 3px; }
            .notif-header-title-row { display: flex; align-items: center; gap: 8px; }
            .notif-header-icon {
                width: 22px; height: 22px;
                background: linear-gradient(135deg, #C5A55A, #E8D5A3);
                color: #0f0f0f;
                border-radius: 6px;
                display: inline-flex; align-items: center; justify-content: center;
                box-shadow: 0 2px 6px -2px rgba(197,165,90,0.5);
            }
            .notif-panel-header strong { color: #f5f5f5; font-size: 14px; font-weight: 700; letter-spacing: -0.2px; }
            .notif-header-sub { font-size: 11px; color: #888; padding-left: 30px; }
            .notif-live-badge {
                font-size: 9px; font-weight: 700; letter-spacing: 0.8px;
                background: rgba(34,197,94,0.18); color: #22c55e;
                padding: 2px 6px 2px 4px; border-radius: 10px;
                display: inline-flex; align-items: center; gap: 4px;
            }
            .notif-live-dot {
                width: 5px; height: 5px; border-radius: 50%;
                background: #22c55e;
                animation: notif-live-pulse 1.4s ease-in-out infinite;
            }
            @keyframes notif-live-pulse {
                0%, 100% { opacity: 1; }
                50% { opacity: 0.4; }
            }
            .notif-header-actions { display: flex; align-items: center; gap: 6px; }
            .notif-icon-btn {
                background: transparent; border: 1px solid #2a2a2a;
                color: #888; width: 28px; height: 28px;
                border-radius: 7px; cursor: pointer;
                display: inline-flex; align-items: center; justify-content: center;
                transition: all 0.18s ease;
            }
            .notif-icon-btn:hover {
                border-color: #C5A55A; color: #C5A55A;
                background: rgba(197,165,90,0.08);
            }

            /* ── Filter chips row ─────────────────────────────── */
            .notif-filter-row {
                display: flex; align-items: center; gap: 6px;
                padding: 10px 14px;
                border-bottom: 1px solid #1f1f1f;
                background: rgba(0,0,0,0.18);
            }
            .notif-chip {
                background: transparent; border: 1px solid #2a2a2a;
                color: #aaa; font-size: 11px; font-weight: 600;
                padding: 5px 10px; border-radius: 14px;
                cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
                transition: all 0.15s ease;
            }
            .notif-chip:hover { border-color: #444; color: #fff; }
            .notif-chip.is-active {
                background: linear-gradient(135deg, rgba(197,165,90,0.25), rgba(197,165,90,0.1));
                border-color: #C5A55A; color: #C5A55A;
            }
            .notif-chip-count {
                background: rgba(255,255,255,0.08);
                color: inherit;
                font-size: 10px;
                padding: 1px 6px;
                border-radius: 8px;
                font-weight: 700;
                min-width: 16px;
                text-align: center;
            }
            .notif-chip.is-active .notif-chip-count {
                background: rgba(197,165,90,0.25);
                color: #C5A55A;
            }
            .notif-filter-spacer { flex: 1; }
            .notif-mark-all {
                background: transparent;
                border: 1px solid rgba(197,165,90,0.3);
                color: #C5A55A;
                font-size: 10px; font-weight: 700; letter-spacing: 0.5px;
                cursor: pointer;
                padding: 5px 10px;
                border-radius: 14px;
                text-transform: uppercase;
                display: inline-flex; align-items: center; gap: 4px;
                transition: all 0.15s ease;
            }
            .notif-mark-all:hover {
                background: rgba(197,165,90,0.12);
                border-color: #C5A55A;
            }

            /* ── Body ─────────────────────────────────────────── */
            .notif-panel-body {
                max-height: 420px;
                overflow-y: auto;
                scrollbar-width: thin;
                scrollbar-color: rgba(197,165,90,0.3) transparent;
            }
            .notif-panel-body::-webkit-scrollbar { width: 6px; }
            .notif-panel-body::-webkit-scrollbar-track { background: transparent; }
            .notif-panel-body::-webkit-scrollbar-thumb { background: rgba(197,165,90,0.3); border-radius: 3px; }

            .notif-empty {
                padding: 48px 24px 40px;
                text-align: center;
            }
            .notif-empty-icon {
                width: 64px; height: 64px;
                margin: 0 auto 14px;
                background: linear-gradient(135deg, rgba(197,165,90,0.12), rgba(197,165,90,0.03));
                border: 1px solid rgba(197,165,90,0.15);
                border-radius: 50%;
                display: inline-flex; align-items: center; justify-content: center;
                color: #C5A55A;
            }
            .notif-empty-title { color: #ddd; font-size: 14px; font-weight: 600; margin-bottom: 4px; }
            .notif-empty-sub { color: #777; font-size: 12px; }
            .notif-mini-link {
                background: transparent; border: none;
                color: #C5A55A; font-size: 12px; font-weight: 600;
                cursor: pointer; margin-top: 10px;
                padding: 4px 10px; border-radius: 8px;
            }
            .notif-mini-link:hover { background: rgba(197,165,90,0.1); }

            /* Loading skeleton */
            .notif-skeleton { padding: 12px 14px; display: flex; flex-direction: column; gap: 12px; }
            .notif-skel-row {
                height: 56px;
                border-radius: 8px;
                background: linear-gradient(90deg, #1c1c1c 0%, #242424 50%, #1c1c1c 100%);
                background-size: 200% 100%;
                animation: notif-skel 1.5s ease-in-out infinite;
            }
            @keyframes notif-skel {
                0% { background-position: 200% 0; }
                100% { background-position: -200% 0; }
            }

            /* ── Item ─────────────────────────────────────────── */
            .notif-item {
                position: relative;
                display: flex;
                gap: 12px;
                padding: 13px 14px 13px 18px;
                border-bottom: 1px solid #1f1f1f;
                color: #e5e5e5;
                text-decoration: none;
                transition: all 0.15s ease;
            }
            .notif-item:hover {
                background: linear-gradient(90deg, rgba(197,165,90,0.06), transparent 80%);
                padding-left: 22px;
            }
            .notif-item.is-read { opacity: 0.55; }
            .notif-item.is-read:hover { opacity: 0.85; }
            .notif-item:last-child { border-bottom: none; }
            .notif-stripe {
                position: absolute; left: 0; top: 0; bottom: 0;
                width: 3px;
                background: #3b82f6;
                transition: width 0.15s ease;
            }
            .notif-item:hover .notif-stripe { width: 4px; }
            .notif-item.sev-info  .notif-stripe { background: linear-gradient(180deg, #3b82f6, #2563eb); }
            .notif-item.sev-warning .notif-stripe { background: linear-gradient(180deg, #eab308, #ca8a04); }
            .notif-item.sev-critical .notif-stripe { background: linear-gradient(180deg, #ef4444, #dc2626); box-shadow: 0 0 12px -2px rgba(239,68,68,0.5); }

            .notif-cat-icon {
                width: 32px; height: 32px;
                border-radius: 8px;
                display: inline-flex; align-items: center; justify-content: center;
                flex-shrink: 0;
                background: rgba(59,130,246,0.13);
                color: #3b82f6;
            }
            .notif-item-body { flex: 1; min-width: 0; }
            .notif-item-title {
                display: flex; align-items: center; gap: 6px;
                font-size: 13px; font-weight: 600; color: #f5f5f5;
                line-height: 1.35;
            }
            .notif-item.is-read .notif-item-title { font-weight: 500; }
            .notif-dot {
                width: 7px; height: 7px;
                border-radius: 50%;
                background: linear-gradient(135deg, #C5A55A, #E8D5A3);
                flex-shrink: 0;
                box-shadow: 0 0 6px rgba(197,165,90,0.6);
            }
            .notif-item-msg {
                margin-top: 4px;
                font-size: 12px;
                color: #999;
                line-height: 1.45;
            }
            .notif-item-meta {
                margin-top: 7px;
                display: flex; align-items: center; gap: 8px;
                font-size: 10px;
            }
            .notif-time {
                color: #666;
                font-weight: 500;
                letter-spacing: 0.2px;
            }
            .notif-cat {
                background: rgba(255,255,255,0.05);
                color: #aaa;
                padding: 2px 7px;
                border-radius: 8px;
                font-weight: 600;
                font-size: 9.5px;
                letter-spacing: 0.4px;
                text-transform: uppercase;
                font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            }

            /* ── Footer ───────────────────────────────────────── */
            .notif-panel-footer {
                padding: 0;
                border-top: 1px solid rgba(197,165,90,0.1);
                background: rgba(0,0,0,0.25);
            }
            .notif-footer-link {
                display: flex; align-items: center; justify-content: center; gap: 8px;
                padding: 12px 14px;
                color: #C5A55A;
                font-size: 12px;
                font-weight: 600;
                text-decoration: none;
                text-transform: uppercase;
                letter-spacing: 0.6px;
                transition: all 0.15s ease;
            }
            .notif-footer-link:hover {
                background: linear-gradient(135deg, rgba(197,165,90,0.12), rgba(197,165,90,0.04));
                gap: 12px;
            }
            .notif-footer-link svg { transition: transform 0.15s ease; }
            .notif-footer-link:hover svg { transform: translateX(3px); }

            @media (max-width: 480px) {
                .notif-bell-panel { width: calc(100vw - 24px); right: -8px; }
                .notif-bell-panel::before { right: 22px; }
            }
        </style>
    </div>
@endonce
