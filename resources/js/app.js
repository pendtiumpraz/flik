import './bootstrap';

// Alpine.js is auto-bundled & started by Livewire 3.
// Don't import/start it here to avoid double-init (which doubles every x-data state).
// If you need to extend Alpine (plugins, custom directives), use:
//   document.addEventListener('alpine:init', () => { window.Alpine.directive(...) })

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
