// ─────────────────────────────────────────────────────────────
// FLiK Web Push opt-in module
//
// Public API (exposed on `window.FlikPush`):
//   - isSupported()           → boolean
//   - currentPermission()     → 'default' | 'granted' | 'denied' | 'unsupported'
//   - hasSubscription()       → Promise<boolean>
//   - requestSubscription()   → Promise<boolean>     // asks permission + subscribes
//   - unsubscribe()           → Promise<boolean>
//
// VAPID public key is read from <meta name="vapid-public-key" content="…">.
// When the meta tag is absent (env var unset → graceful degradation) every
// entry point returns false without throwing.
// ─────────────────────────────────────────────────────────────

const SW_PATH = '/sw.js';
const SUBSCRIBE_URL = '/api/push/subscribe';
const UNSUBSCRIBE_URL = '/api/push/unsubscribe';
const DISMISS_KEY = 'flik:push-opt-in:dismissed';

function isSupported() {
    return typeof window !== 'undefined'
        && 'serviceWorker' in navigator
        && 'PushManager' in window
        && 'Notification' in window;
}

function currentPermission() {
    if (!isSupported()) return 'unsupported';
    return Notification.permission;
}

function vapidPublicKey() {
    const meta = document.querySelector('meta[name="vapid-public-key"]');
    return meta ? meta.getAttribute('content') : null;
}

function csrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : null;
}

// Convert a URL-safe base64 VAPID key into the Uint8Array the PushManager
// expects as applicationServerKey.
function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; ++i) outputArray[i] = rawData.charCodeAt(i);
    return outputArray;
}

function arrayBufferToBase64Url(buffer) {
    if (!buffer) return '';
    const bytes = new Uint8Array(buffer);
    let binary = '';
    for (let i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i]);
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

async function ensureRegistration() {
    // navigator.serviceWorker.ready waits for the SW currently controlling
    // this page to be active — but on first page-load the SW may not be
    // registered yet, so we register defensively.
    if (!navigator.serviceWorker.controller) {
        await navigator.serviceWorker.register(SW_PATH);
    }
    return navigator.serviceWorker.ready;
}

async function hasSubscription() {
    if (!isSupported()) return false;
    try {
        const reg = await ensureRegistration();
        const sub = await reg.pushManager.getSubscription();
        return !!sub;
    } catch {
        return false;
    }
}

async function requestSubscription() {
    if (!isSupported()) return false;
    const vapid = vapidPublicKey();
    if (!vapid) return false; // env-gated — backend has no VAPID configured

    // 1. Ask for permission if we haven't yet.
    let permission = Notification.permission;
    if (permission === 'default') {
        permission = await Notification.requestPermission();
    }
    if (permission !== 'granted') return false;

    // 2. Register the SW + subscribe via PushManager.
    const reg = await ensureRegistration();
    let sub = await reg.pushManager.getSubscription();
    if (!sub) {
        try {
            sub = await reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(vapid),
            });
        } catch (e) {
            console.warn('[FlikPush] subscribe failed:', e);
            return false;
        }
    }

    // 3. POST the subscription to our backend.
    try {
        const res = await fetch(SUBSCRIBE_URL, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                ...(csrfToken() ? { 'X-CSRF-TOKEN': csrfToken() } : {}),
            },
            body: JSON.stringify({
                endpoint: sub.endpoint,
                keys: {
                    p256dh: arrayBufferToBase64Url(sub.getKey('p256dh')),
                    auth: arrayBufferToBase64Url(sub.getKey('auth')),
                },
                userAgent: navigator.userAgent,
            }),
        });
        if (!res.ok) {
            console.warn('[FlikPush] subscribe POST failed:', res.status);
            return false;
        }
        return true;
    } catch (e) {
        console.warn('[FlikPush] subscribe POST error:', e);
        return false;
    }
}

async function unsubscribe() {
    if (!isSupported()) return false;
    try {
        const reg = await ensureRegistration();
        const sub = await reg.pushManager.getSubscription();
        if (!sub) return true;

        const endpoint = sub.endpoint;
        await sub.unsubscribe();

        await fetch(UNSUBSCRIBE_URL, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                ...(csrfToken() ? { 'X-CSRF-TOKEN': csrfToken() } : {}),
            },
            body: JSON.stringify({ endpoint }),
        });
        return true;
    } catch (e) {
        console.warn('[FlikPush] unsubscribe error:', e);
        return false;
    }
}

function isDismissed() {
    try { return localStorage.getItem(DISMISS_KEY) === '1'; } catch { return false; }
}

function dismiss() {
    try { localStorage.setItem(DISMISS_KEY, '1'); } catch { /* noop */ }
}

// Public surface
const FlikPush = {
    isSupported,
    currentPermission,
    hasSubscription,
    requestSubscription,
    unsubscribe,
    isDismissed,
    dismiss,
};

if (typeof window !== 'undefined') {
    window.FlikPush = FlikPush;
}

export default FlikPush;
