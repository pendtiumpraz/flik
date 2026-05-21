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
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/>
            <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>
        </svg>
        <span class="notif-bell-badge"
              x-cloak
              x-show="unreadCount > 0"
              x-text="unreadCount > 99 ? '99+' : unreadCount"></span>
    </button>

    {{-- Dropdown panel --}}
    <div class="notif-bell-panel"
         x-cloak
         x-show="open"
         x-transition:enter="notif-trans-enter"
         x-transition:enter-start="notif-trans-enter-start"
         x-transition:enter-end="notif-trans-enter-end"
         role="dialog"
         aria-label="Notifications">

        <div class="notif-panel-header">
            <div style="display:flex;align-items:center;gap:8px">
                <strong style="color:#e5e5e5;font-size:14px">Notifications</strong>
                <span x-cloak x-show="usingRealtime"
                      title="Realtime updates via Pusher"
                      style="font-size:9px;background:rgba(34,197,94,0.18);color:#22c55e;padding:2px 6px;border-radius:10px;font-weight:600;letter-spacing:0.5px">LIVE</span>
            </div>
            <div style="display:flex;align-items:center;gap:6px">
                <button type="button"
                        class="notif-icon-btn"
                        :title="muted ? 'Unmute sound' : 'Mute sound'"
                        @click="toggleMute()">
                    <template x-if="!muted">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/></svg>
                    </template>
                    <template x-if="muted">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><line x1="23" y1="9" x2="17" y2="15"/><line x1="17" y1="9" x2="23" y2="15"/></svg>
                    </template>
                </button>
                <button type="button"
                        class="notif-mark-all"
                        x-cloak
                        x-show="unreadCount > 0"
                        @click="markAllRead()">
                    Mark all read
                </button>
            </div>
        </div>

        <div class="notif-panel-body">
            <template x-if="loading && items.length === 0">
                <div class="notif-empty">Loading…</div>
            </template>

            <template x-if="!loading && items.length === 0">
                <div class="notif-empty">No new notifications yet.</div>
            </template>

            <template x-for="item in items" :key="item.id">
                <a href="#"
                   class="notif-item"
                   :class="{ 'is-read': item.read }"
                   @click.prevent="markRead(item)">
                    <span class="notif-stripe" :style="{ background: severityColor(item.severity) }"></span>
                    <div class="notif-item-body">
                        <div class="notif-item-title">
                            <span x-text="item.title"></span>
                            <span x-cloak x-show="!item.read" class="notif-dot" aria-label="unread"></span>
                        </div>
                        <div class="notif-item-msg" x-text="snippet(item.message)"></div>
                        <div class="notif-item-meta">
                            <span x-text="timeAgo(item.created_at)"></span>
                            <span x-cloak x-show="item.category"
                                  class="notif-cat"
                                  x-text="item.category"></span>
                        </div>
                    </div>
                </a>
            </template>
        </div>

        <div class="notif-panel-footer">
            <a href="{{ $urls['indexPage'] }}">View all →</a>
        </div>
    </div>
</div>

@once
    @push('styles')
        <style>
            /* ── Admin notification bell ─────────────────────────── */
            .notif-bell-wrap { position: relative; }
            .notif-bell-btn {
                position: relative;
                background: transparent;
                border: 1px solid #2a2a2a;
                color: #999;
                width: 36px; height: 36px;
                border-radius: 50%;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
                transition: all 0.18s ease;
            }
            .notif-bell-btn:hover { border-color: #C5A55A; color: #C5A55A; }
            .notif-bell-btn.is-open { border-color: #C5A55A; color: #C5A55A; background: rgba(197,165,90,0.08); }
            .notif-bell-btn.has-unread { color: #C5A55A; }
            .notif-bell-badge {
                position: absolute;
                top: -4px; right: -4px;
                min-width: 18px; height: 18px; padding: 0 5px;
                background: #ef4444; color: #fff;
                font-size: 10px; font-weight: 700;
                border-radius: 9px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                line-height: 1;
                border: 2px solid #0f0f0f;
                font-family: 'Inter', sans-serif;
            }

            .notif-bell-panel {
                position: absolute;
                top: calc(100% + 10px);
                right: 0;
                width: 360px;
                max-width: calc(100vw - 32px);
                background: #161616;
                border: 1px solid #2a2a2a;
                border-radius: 12px;
                box-shadow: 0 24px 48px rgba(0,0,0,0.55);
                overflow: hidden;
                z-index: 60;
            }

            .notif-trans-enter { transition: all 0.16s ease-out; }
            .notif-trans-enter-start { opacity: 0; transform: translateY(-6px); }
            .notif-trans-enter-end   { opacity: 1; transform: translateY(0); }

            .notif-panel-header {
                display: flex; align-items: center; justify-content: space-between;
                padding: 12px 14px;
                border-bottom: 1px solid #242424;
                background: linear-gradient(180deg, rgba(197,165,90,0.05), transparent);
            }
            .notif-icon-btn {
                background: transparent; border: 1px solid #2a2a2a;
                color: #888; width: 26px; height: 26px;
                border-radius: 6px; cursor: pointer;
                display: inline-flex; align-items: center; justify-content: center;
                transition: all 0.18s ease;
            }
            .notif-icon-btn:hover { border-color: #C5A55A; color: #C5A55A; }
            .notif-mark-all {
                background: transparent; border: none;
                color: #C5A55A;
                font-size: 11px; font-weight: 600;
                cursor: pointer;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                padding: 4px 6px;
            }
            .notif-mark-all:hover { text-decoration: underline; }

            .notif-panel-body {
                max-height: 380px;
                overflow-y: auto;
            }
            .notif-empty {
                padding: 32px 16px;
                text-align: center;
                color: #666;
                font-size: 13px;
            }

            .notif-item {
                position: relative;
                display: flex;
                gap: 10px;
                padding: 12px 14px 12px 18px;
                border-bottom: 1px solid #1f1f1f;
                color: #e5e5e5;
                text-decoration: none;
                transition: background 0.15s ease;
            }
            .notif-item:hover { background: rgba(255,255,255,0.02); }
            .notif-item.is-read { opacity: 0.65; }
            .notif-item:last-child { border-bottom: none; }
            .notif-stripe {
                position: absolute; left: 0; top: 0; bottom: 0;
                width: 3px;
            }
            .notif-item-body { flex: 1; min-width: 0; }
            .notif-item-title {
                display: flex; align-items: center; gap: 6px;
                font-size: 13px; font-weight: 600; color: #f5f5f5;
                line-height: 1.3;
            }
            .notif-dot {
                width: 6px; height: 6px;
                border-radius: 50%;
                background: #C5A55A;
                flex-shrink: 0;
            }
            .notif-item-msg {
                margin-top: 3px;
                font-size: 12px;
                color: #999;
                line-height: 1.4;
            }
            .notif-item-meta {
                margin-top: 6px;
                display: flex; align-items: center; gap: 8px;
                font-size: 10px;
                color: #666;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            .notif-cat {
                background: #222;
                color: #aaa;
                padding: 1px 6px;
                border-radius: 8px;
                font-weight: 600;
            }

            .notif-panel-footer {
                padding: 10px 14px;
                border-top: 1px solid #242424;
                text-align: center;
                background: #131313;
            }
            .notif-panel-footer a {
                color: #C5A55A;
                font-size: 12px;
                font-weight: 600;
                text-decoration: none;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            .notif-panel-footer a:hover { text-decoration: underline; }

            @media (max-width: 480px) {
                .notif-bell-panel { width: calc(100vw - 24px); right: -8px; }
            }
        </style>
    @endpush
@endonce
