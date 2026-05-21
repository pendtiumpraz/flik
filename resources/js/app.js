import './bootstrap';

// ━━━ Alpine.js bundled via Vite — SINGLE SOURCE OF TRUTH ━━━
// Replaces the previous CDN script tags scattered across layout.blade.php,
// admin/layout.blade.php, and home.blade.php. Having multiple CDN tags +
// the implicit Alpine injected by Livewire-3 caused "multiple Alpine
// instances" warnings → every x-show became a no-op → all dropdowns
// rendered visible + click handlers detached. This bundle ensures ONE
// instance, deterministic init order, and proper plugin registration
// before Alpine.start().
import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';

// Guard: if some other consumer (legacy CDN tag, Livewire's bundled
// Alpine) already booted, do not start again — register on the existing
// instance instead.
if (!window.Alpine) {
    window.Alpine = Alpine;
    Alpine.plugin(collapse);
    Alpine.start();
}

// ━━━ Player modules (Shaka wrapper + auto-skip + X-Ray overlay) ━━━
// Each player file already self-registers on `window` for non-module callers,
// but we re-expose them here so Vite's tree-shaker keeps the bundles intact and
// Blade views can rely on a single canonical entry.
import FlikPlayer from './player/flik-player';
import initAutoSkip from './player/auto-skip';
import initXrayOverlay from './player/xray-overlay';

window.FlikPlayer = FlikPlayer;
window.initAutoSkip = initAutoSkip;
window.initXrayOverlay = initXrayOverlay;

// ━━━ Cookie consent (registers `window.FlikConsent`, dispatches
// `flik:cookies-updated` after persisted prefs load). Must load eagerly
// so analytics/marketing scripts can gate themselves before they fire. ━━━
import './cookie-consent';

// ━━━ Laravel Echo (Pusher) bootstrap — initialises `window.Echo` only when
// `window.PUSHER_KEY` is exposed by the admin layout. Gracefully no-ops in
// production environments where broadcasting is not configured (consumers
// like the admin notification bell fall back to polling). ━━━
import './echo';

// ━━━ Admin notification bell Alpine factory — registers
// `window.adminNotifBell()` used by <x-admin.notification-bell />.
// Safe to import on every page; the Alpine component only runs when the
// bell blade is actually rendered. ━━━
import './admin-notifications';

// ━━━ Web Push (VAPID) opt-in module — registers `window.FlikPush`.
// Hides itself gracefully when the browser lacks support OR when no
// VAPID public key is exposed via <meta name="vapid-public-key">.
// The Blade component <x-push-opt-in /> consumes this surface. ━━━
import './push-notifications';

// ━━━ Comment emoji reactions — registers `window.commentReactions(...)`
// Alpine factory consumed by the reaction pill bar in the comment list.
// Optimistic UI, optional Echo subscription for live updates when Pusher
// is configured (no-ops gracefully otherwise). ━━━
import './comment-reactions';

// ━━━ PWA install prompt — exposes `window.FlikPwaInstall` and dispatches
// the `flik:show-install-prompt` custom event after a 30 s warm-up. Consumed
// by <x-pwa-install-banner />. iOS Safari fallback is auto-detected. The
// module is a no-op when already running standalone or recently dismissed. ━━━
import './pwa-install';
