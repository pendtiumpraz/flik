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
