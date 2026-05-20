// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// FLiK PWA Install Prompt
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// Cross-browser install UX:
//   • Chrome/Edge/Android: captures the native `beforeinstallprompt` event
//     and lets a Blade banner trigger the install dialog via custom event.
//   • iOS Safari: no native event — we detect iOS + non-standalone and emit
//     the same custom event with `ios: true` so the banner shows
//     "Tap Share → Add to Home Screen" instructions.
//
// Throttling rules — we never want to nag:
//   - 30 s warm-up before first prompt (avoid first-impression banner spam).
//   - localStorage `pwa_install_dismissed_at` blocks re-show for 14 days.
//   - Skip entirely when already running standalone (display-mode standalone
//     OR iOS navigator.standalone).
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

(function () {
    'use strict';

    const STORAGE_KEY = 'pwa_install_dismissed_at';
    const COOLDOWN_DAYS = 14;
    const WARMUP_MS = 30_000;

    let deferredPrompt = null;
    let promptShown = false;

    /** True when the page is already running as an installed PWA. */
    function isStandalone() {
        return (
            (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) ||
            window.navigator.standalone === true
        );
    }

    /** True when the user dismissed within the cooldown window. */
    function recentlyDismissed() {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            if (!raw) return false;
            const ts = parseInt(raw, 10);
            if (!ts) return false;
            const ageMs = Date.now() - ts;
            return ageMs < COOLDOWN_DAYS * 24 * 60 * 60 * 1000;
        } catch (e) {
            return false;
        }
    }

    function markDismissed() {
        try {
            localStorage.setItem(STORAGE_KEY, String(Date.now()));
        } catch (e) {
            /* private mode — silently ignore */
        }
    }

    /** Best-effort install-event telemetry. Fire-and-forget; never blocks UX. */
    function trackInstall(outcome) {
        try {
            const meta = document.querySelector('meta[name="csrf-token"]');
            fetch('/api/pwa/track-install', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': meta ? meta.getAttribute('content') : '',
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    outcome,
                    device: navigator.platform || 'unknown',
                    ua: navigator.userAgent,
                }),
                keepalive: true,
            }).catch(() => {});
        } catch (e) {
            /* swallow — telemetry must never break UX */
        }
    }

    /** Dispatch the "show banner" event after WARMUP_MS. Banner does the rest. */
    function maybeShow(payload) {
        if (promptShown) return;
        if (isStandalone()) return;
        if (recentlyDismissed()) return;
        promptShown = true;

        window.setTimeout(() => {
            document.dispatchEvent(new CustomEvent('flik:show-install-prompt', { detail: payload }));
        }, WARMUP_MS);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Chromium path — beforeinstallprompt fires before the native banner
    // would have. We stash it + raise our own custom-event so the Blade
    // component can decide WHEN to show. .preventDefault() suppresses the
    // browser's "mini-infobar" so we own the experience.
    // ─────────────────────────────────────────────────────────────────────
    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferredPrompt = e;
        maybeShow({ ios: false });
    });

    // ─────────────────────────────────────────────────────────────────────
    // iOS fallback — no native prompt API, but we can detect Safari on iOS
    // and tell the user how to add the app manually.
    // ─────────────────────────────────────────────────────────────────────
    function isIosSafari() {
        const ua = navigator.userAgent || '';
        const isIos = /iPhone|iPad|iPod/.test(ua);
        // Exclude Chrome (CriOS) / Firefox (FxiOS) on iOS — neither supports A2HS via Share sheet
        // cleanly, but the instructions are still the same so we treat them all as iOS Safari.
        return isIos && !window.MSStream;
    }

    if (isIosSafari() && !isStandalone()) {
        maybeShow({ ios: true });
    }

    // ─────────────────────────────────────────────────────────────────────
    // appinstalled — fired after the user accepts. Clean up the deferred
    // prompt so we never re-prompt in the same session.
    // ─────────────────────────────────────────────────────────────────────
    window.addEventListener('appinstalled', () => {
        deferredPrompt = null;
        trackInstall('installed');
    });

    // ─────────────────────────────────────────────────────────────────────
    // Public surface — the Blade banner calls these via window.FlikPwaInstall.
    // ─────────────────────────────────────────────────────────────────────
    window.FlikPwaInstall = {
        /** Trigger the native prompt (Chromium only). Returns userChoice promise or null. */
        async install() {
            if (!deferredPrompt) return null;
            try {
                deferredPrompt.prompt();
                const choice = await deferredPrompt.userChoice;
                deferredPrompt = null;
                trackInstall(choice && choice.outcome ? choice.outcome : 'unknown');
                return choice;
            } catch (e) {
                return null;
            }
        },
        /** Persist a dismissal so we don't nag again for 14 days. */
        dismiss() {
            markDismissed();
        },
        /** Surface for Alpine: are we standalone right now? */
        isStandalone,
        /** Surface for Alpine: do we have a deferred prompt to fire? */
        hasNativePrompt() {
            return !!deferredPrompt;
        },
    };
})();
