/**
 * FLiK cookie consent client.
 *
 * Stores the user's decision in localStorage under `flik_cookie_consent` as:
 *   { necessary: true, analytics: bool, marketing: bool, version: 1, decided_at: iso8601 }
 *
 * On every page load the script reads the saved decision and dispatches
 * `flik:cookies-updated` on `window` so other scripts (analytics loader,
 * marketing pixels, the Alpine banner component) can opt themselves in/out.
 *
 * If the saved version is missing, malformed, or older than CONSENT_VERSION
 * the banner re-prompts so we stay GDPR/UU PDP-compliant when categories
 * change. Bump CONSENT_VERSION whenever the disclosure copy or category set
 * changes materially.
 *
 * Public API exposed on `window.FlikConsent`:
 *   - get()                  -> current consent object or null
 *   - has(category)          -> boolean (necessary always true)
 *   - save(prefs)            -> persist + dispatch
 *   - acceptAll()            -> shortcut, accepts everything
 *   - rejectAll()            -> shortcut, only necessary
 *   - reopen()               -> re-show banner so user can edit prefs
 *   - VERSION                -> current schema version (for compare in views)
 */

const STORAGE_KEY = 'flik_cookie_consent';
const CONSENT_VERSION = 1;
const UPDATE_EVENT = 'flik:cookies-updated';
const REOPEN_EVENT = 'flik:cookies-reopen';

const DEFAULT_PREFS = Object.freeze({
    necessary: true,
    analytics: false,
    marketing: false,
});

/**
 * Read + sanity-check the stored decision. Returns null when missing,
 * malformed, or stamped with a stale schema version (so the caller knows
 * to prompt again).
 */
function readStored() {
    try {
        const raw = window.localStorage.getItem(STORAGE_KEY);
        if (!raw) return null;
        const parsed = JSON.parse(raw);
        if (!parsed || typeof parsed !== 'object') return null;
        if (parsed.version !== CONSENT_VERSION) return null;
        // Necessary is non-negotiable — always force true regardless of stored value.
        return {
            necessary: true,
            analytics: Boolean(parsed.analytics),
            marketing: Boolean(parsed.marketing),
            version: CONSENT_VERSION,
            decided_at: typeof parsed.decided_at === 'string' ? parsed.decided_at : null,
        };
    } catch (e) {
        // Corrupt JSON or localStorage blocked (private browsing on Safari) — re-prompt.
        return null;
    }
}

function dispatch(prefs) {
    try {
        window.dispatchEvent(new CustomEvent(UPDATE_EVENT, { detail: prefs }));
    } catch (e) {
        // Old browsers without CustomEvent constructor — silently ignore;
        // they're below our supported matrix anyway.
    }
}

function save(prefsInput) {
    const prefs = {
        necessary: true,
        analytics: Boolean(prefsInput && prefsInput.analytics),
        marketing: Boolean(prefsInput && prefsInput.marketing),
        version: CONSENT_VERSION,
        decided_at: new Date().toISOString(),
    };
    try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(prefs));
    } catch (e) {
        // Storage blocked — still dispatch so the in-memory consent applies for this session.
    }
    dispatch(prefs);
    return prefs;
}

function acceptAll() {
    return save({ analytics: true, marketing: true });
}

function rejectAll() {
    return save({ analytics: false, marketing: false });
}

function has(category) {
    if (category === 'necessary') return true;
    const stored = readStored();
    if (!stored) return false;
    return Boolean(stored[category]);
}

function reopen() {
    try {
        window.dispatchEvent(new CustomEvent(REOPEN_EVENT));
    } catch (e) {
        // ignore
    }
}

// Expose API on window so non-module scripts (Blade inline, third-party
// loaders) can opt-in/out without importing.
window.FlikConsent = {
    VERSION: CONSENT_VERSION,
    STORAGE_KEY,
    UPDATE_EVENT,
    REOPEN_EVENT,
    DEFAULTS: DEFAULT_PREFS,
    get: readStored,
    has,
    save,
    acceptAll,
    rejectAll,
    reopen,
};

// Fire the initial event so listeners registered before page load
// (e.g. the Alpine banner) can sync their UI to the persisted state.
// Use a microtask so any synchronous listeners attached on the same tick
// still get the dispatch.
Promise.resolve().then(() => {
    const stored = readStored();
    if (stored) dispatch(stored);
});

export default window.FlikConsent;
