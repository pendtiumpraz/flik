/**
 * Admin notification bell Alpine factory.
 *
 * Registered globally as `window.adminNotifBell` and consumed by
 * <x-admin.notification-bell /> via `x-data="adminNotifBell()"`.
 *
 * Realtime strategy:
 *   1. If `window.Echo` is available (Pusher configured + Echo loaded),
 *      subscribe to one private channel per role in `window.AUTH_USER_ROLES`
 *      plus the catch-all `admin-notifications.all-admins`.
 *   2. Otherwise fall back to polling the unread-count endpoint every 30s
 *      and refetching the list when the count changes.
 *
 * Endpoints (admin route group):
 *   GET  /admin/notifications/unread-count       → { count }
 *   GET  /admin/notifications?recent=1&limit=10  → { items: [...] }    (graceful: tolerates plain array too)
 *   POST /admin/notifications/{id}/read          → 204
 *   POST /admin/notifications/read-all           → { count }
 *
 * The component degrades gracefully if peer NOTIF #1 hasn't shipped the
 * routes yet — fetch failures are logged but the bell still renders.
 */

const POLL_INTERVAL_MS = 30_000;
const MUTE_STORAGE_KEY = 'flik.admin.notif.muted';

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

async function jsonFetch(url, init = {}) {
    const opts = {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(init.headers || {}),
        },
        ...init,
    };
    if (init.method && init.method.toUpperCase() !== 'GET') {
        opts.headers['X-CSRF-TOKEN'] = csrfToken();
    }
    const res = await fetch(url, opts);
    if (!res.ok) {
        throw new Error(`Request to ${url} failed with status ${res.status}`);
    }
    if (res.status === 204) {
        return null;
    }
    const text = await res.text();
    return text ? JSON.parse(text) : null;
}

/**
 * Tiny WebAudio fallback chime — used when the bundled mp3 is not
 * present (404). Plays a soft 880Hz sine for ~120ms with an exponential
 * decay so it sounds like a gentle ding rather than a square-wave beep.
 *
 * Swap with `<audio src="/sounds/notification-chime.mp3">` once a real
 * chime file is dropped at `public/sounds/notification-chime.mp3`.
 */
function playWebAudioChime() {
    try {
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (!AudioCtx) return;
        const ctx = new AudioCtx();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.type = 'sine';
        osc.frequency.value = 880;
        gain.gain.setValueAtTime(0.0001, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.18, ctx.currentTime + 0.01);
        gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.18);
        osc.connect(gain).connect(ctx.destination);
        osc.start();
        osc.stop(ctx.currentTime + 0.2);
        osc.onended = () => ctx.close().catch(() => {});
    } catch (e) {
        /* swallow — audio is best effort */
    }
}

function timeAgo(iso) {
    if (!iso) return '';
    const d = typeof iso === 'string' ? new Date(iso) : iso;
    const seconds = Math.max(1, Math.floor((Date.now() - d.getTime()) / 1000));
    if (seconds < 60) return `${seconds}s ago`;
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes}m ago`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours}h ago`;
    const days = Math.floor(hours / 24);
    if (days < 7) return `${days}d ago`;
    return d.toLocaleDateString();
}

function severityColor(severity) {
    switch ((severity || '').toLowerCase()) {
        case 'critical':
        case 'danger':
        case 'error':
            return '#ef4444';
        case 'warning':
        case 'warn':
            return '#eab308';
        case 'success':
            return '#22c55e';
        case 'info':
        default:
            return '#3b82f6';
    }
}

function normaliseItem(raw) {
    if (!raw || typeof raw !== 'object') return null;
    return {
        id: raw.id,
        category: raw.category || 'general',
        title: raw.title || 'Notification',
        message: raw.message || '',
        severity: raw.severity || 'info',
        meta: raw.meta || null,
        action_url: raw.action_url || null,
        created_at: raw.created_at || new Date().toISOString(),
        read: !!raw.read,
    };
}

function adminNotifBell() {
    return {
        // ── state ─────────────────────────────────────────────
        open: false,
        loading: false,
        items: [],
        unreadCount: 0,
        muted: false,
        permission: typeof Notification !== 'undefined' ? Notification.permission : 'denied',
        usingRealtime: false,
        pollHandle: null,
        chimeAudio: null,

        // Endpoint URLs — supplied by the bell blade so we can use
        // Laravel's named-route helper instead of hardcoding strings.
        urls: {
            list: '/admin/notifications?recent=1&limit=10',
            unreadCount: '/admin/notifications/unread-count',
            readAll: '/admin/notifications/read-all',
            // read uses a template — we substitute {id}
            read: '/admin/notifications/{id}/read',
            indexPage: '/admin/notifications',
        },

        // ── lifecycle ────────────────────────────────────────
        init() {
            // Read mute preference from localStorage (per-browser, per-device)
            try {
                this.muted = localStorage.getItem(MUTE_STORAGE_KEY) === '1';
            } catch (e) {
                this.muted = false;
            }

            // Allow blade to override URLs via x-init=" $data.urls = {...} "
            if (this.$el && this.$el.dataset && this.$el.dataset.urls) {
                try {
                    this.urls = { ...this.urls, ...JSON.parse(this.$el.dataset.urls) };
                } catch (e) {
                    /* ignore */
                }
            }

            // Try to preload the bundled chime; gracefully ignore if missing.
            try {
                this.chimeAudio = new Audio('/sounds/notification-chime.mp3');
                this.chimeAudio.preload = 'auto';
                this.chimeAudio.volume = 0.5;
            } catch (e) {
                this.chimeAudio = null;
            }

            this.refresh();
            this.startRealtimeOrPolling();
        },

        startRealtimeOrPolling() {
            if (window.Echo && Array.isArray(window.AUTH_USER_ROLES) && window.AUTH_USER_ROLES.length > 0) {
                this.subscribeEcho();
            } else if (window.Echo) {
                // Authenticated admin with no roles array — still subscribe to all-admins.
                this.subscribeEchoAllAdmins();
            } else {
                this.startPolling();
            }
        },

        subscribeEcho() {
            try {
                const handler = (payload) => this.onIncoming(payload);
                window.AUTH_USER_ROLES.forEach((role) => {
                    if (!role) return;
                    window.Echo
                        .private(`admin-notifications.${role}`)
                        .listen('.created', handler);
                });
                window.Echo
                    .private('admin-notifications.all-admins')
                    .listen('.created', handler);
                this.usingRealtime = true;
            } catch (e) {
                console.warn('[admin-notif] Echo subscribe failed, falling back to polling', e);
                this.startPolling();
            }
        },

        subscribeEchoAllAdmins() {
            try {
                window.Echo
                    .private('admin-notifications.all-admins')
                    .listen('.created', (payload) => this.onIncoming(payload));
                this.usingRealtime = true;
            } catch (e) {
                this.startPolling();
            }
        },

        startPolling() {
            if (this.pollHandle) return;
            this.pollHandle = setInterval(() => this.pollTick(), POLL_INTERVAL_MS);
        },

        async pollTick() {
            try {
                const data = await jsonFetch(this.urls.unreadCount);
                const newCount = Number(data?.count ?? 0);
                if (newCount !== this.unreadCount) {
                    this.unreadCount = newCount;
                    // Re-fetch the list so the dropdown is fresh next time it opens.
                    await this.fetchList();
                }
            } catch (e) {
                // Silent — endpoint may not exist yet.
            }
        },

        // ── data fetching ─────────────────────────────────────
        async refresh() {
            await Promise.all([this.fetchCount(), this.fetchList()]);
        },

        async fetchCount() {
            try {
                const data = await jsonFetch(this.urls.unreadCount);
                this.unreadCount = Number(data?.count ?? 0);
            } catch (e) {
                /* graceful: leave existing count */
            }
        },

        async fetchList() {
            this.loading = true;
            try {
                const data = await jsonFetch(this.urls.list);
                // Tolerate { items: [...] }, { data: [...] }, or a bare array.
                const raw = Array.isArray(data) ? data : (data?.items || data?.data || []);
                this.items = raw.map(normaliseItem).filter(Boolean);
            } catch (e) {
                this.items = [];
            } finally {
                this.loading = false;
            }
        },

        // ── realtime push handler ─────────────────────────────
        onIncoming(payload) {
            const item = normaliseItem(payload);
            if (!item) return;
            // Avoid duplicates if the same id is already at the top.
            this.items = [item, ...this.items.filter((i) => i.id !== item.id)].slice(0, 20);
            this.unreadCount = this.unreadCount + 1;
            this.playSound();
            this.notifyBrowser(item);
        },

        // ── user actions ──────────────────────────────────────
        toggle() {
            this.open = !this.open;
            if (this.open) {
                // Refresh list on open so the user sees the latest even after
                // a long idle period.
                this.fetchList();
            }
        },

        close() {
            this.open = false;
        },

        async markRead(item) {
            if (!item || item.read) {
                if (item?.action_url) window.location.href = item.action_url;
                return;
            }
            const url = this.urls.read.replace('{id}', encodeURIComponent(item.id));
            try {
                await jsonFetch(url, { method: 'POST' });
                item.read = true;
                this.unreadCount = Math.max(0, this.unreadCount - 1);
            } catch (e) {
                /* swallow */
            }
            if (item.action_url) {
                window.location.href = item.action_url;
            }
        },

        async markAllRead() {
            try {
                await jsonFetch(this.urls.readAll, { method: 'POST' });
            } catch (e) {
                /* swallow */
            }
            this.items = this.items.map((i) => ({ ...i, read: true }));
            this.unreadCount = 0;
        },

        toggleMute() {
            this.muted = !this.muted;
            try {
                localStorage.setItem(MUTE_STORAGE_KEY, this.muted ? '1' : '0');
            } catch (e) {
                /* ignore */
            }
        },

        // ── effects ───────────────────────────────────────────
        playSound() {
            if (this.muted) return;
            if (this.chimeAudio) {
                // Reset to start in case it's mid-play.
                try {
                    this.chimeAudio.currentTime = 0;
                    const p = this.chimeAudio.play();
                    if (p && typeof p.catch === 'function') {
                        p.catch(() => playWebAudioChime());
                    }
                    return;
                } catch (e) {
                    /* fall through to WebAudio fallback */
                }
            }
            playWebAudioChime();
        },

        notifyBrowser(item) {
            if (typeof Notification === 'undefined') return;
            if (Notification.permission === 'granted') {
                try {
                    new Notification(item.title, {
                        body: (item.message || '').slice(0, 140),
                        icon: '/favicon.png',
                        tag: `admin-notif-${item.id}`,
                    });
                } catch (e) {
                    /* swallow */
                }
            } else if (Notification.permission === 'default') {
                // Ask once when the first notification arrives.
                Notification.requestPermission().then((perm) => {
                    this.permission = perm;
                });
            }
        },

        // ── view helpers ──────────────────────────────────────
        severityColor,
        timeAgo,
        snippet(text) {
            if (!text) return '';
            const t = String(text).trim();
            return t.length > 90 ? `${t.slice(0, 90)}…` : t;
        },
        // categoryIcon — proxies to global window.notifCategoryIcon which the
        // bell component registers via @push('scripts'). Falls back to a
        // generic info dot if the helper hasn't loaded yet.
        categoryIcon(category) {
            if (typeof window.notifCategoryIcon === 'function') {
                return window.notifCategoryIcon(category);
            }
            return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg>';
        },
    };
}

if (typeof window !== 'undefined') {
    window.adminNotifBell = adminNotifBell;
}

export default adminNotifBell;
