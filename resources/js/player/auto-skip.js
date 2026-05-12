/**
 * Auto-Skip Intro / Outro / Recap overlay for the FLiK player.
 *
 * Reads marker timestamps from data-* attributes on a host element (typically
 * the <video> tag itself, or a wrapper around it). Three independent windows:
 *
 *   - data-recap-end       → "Skip Recap"  shown from t=0 until recap_end
 *   - data-intro-start     → "Skip Intro"  shown from intro_start..intro_end
 *   - data-intro-end          (default intro_start = recap_end || 0)
 *   - data-outro-start     → "Skip Outro"  shown from outro_start..end-of-video
 *
 * The button overlay is created lazily and injected into a sibling overlay
 * container (or the video's parent if none provided). Clicking jumps the
 * player to the end of the relevant segment.
 *
 * "Always skip" preference is persisted in localStorage. When enabled, the
 * relevant segment is auto-skipped on first entry without showing the button.
 *
 * Designed for Shaka Player but framework-agnostic — it only needs an HTML
 * <video> element. Pass the Shaka instance via opts.shakaPlayer if you want
 * us to call its API instead of HTMLMediaElement.currentTime (some live
 * pipelines need shaka.Player.seek() to clear the buffer cleanly).
 */

const STORAGE_KEY = 'flik.player.autoSkip';

const DEFAULTS = Object.freeze({
    intro: false,
    outro: false,
    recap: false,
});

/**
 * Read the per-user "always skip" preferences from localStorage.
 * Falls back to defaults on parse error / missing storage.
 */
function loadPrefs() {
    try {
        const raw = window.localStorage?.getItem(STORAGE_KEY);
        if (!raw) return { ...DEFAULTS };
        const parsed = JSON.parse(raw);
        return { ...DEFAULTS, ...parsed };
    } catch (_e) {
        return { ...DEFAULTS };
    }
}

function savePrefs(prefs) {
    try {
        window.localStorage?.setItem(STORAGE_KEY, JSON.stringify(prefs));
    } catch (_e) {
        // Quota exceeded / private mode — non-fatal.
    }
}

/**
 * Parse a data-* attribute as a finite, non-negative float. Returns null
 * when missing or unparsable so callers can branch on truthiness.
 *
 * @param {Element} el
 * @param {string}  attr  full data attribute name, e.g. 'data-intro-end'
 * @returns {number|null}
 */
function readSeconds(el, attr) {
    const raw = el.getAttribute(attr);
    if (raw === null || raw === '') return null;
    const v = parseFloat(raw);
    if (!Number.isFinite(v) || v < 0) return null;
    return v;
}

/**
 * Resolve the marker bundle for a given source element.
 *
 * @returns {{
 *   introStart: number,
 *   introEnd:   number|null,
 *   outroStart: number|null,
 *   recapEnd:   number|null,
 * }}
 */
function readMarkers(el) {
    const recapEnd = readSeconds(el, 'data-recap-end');
    let introStart = readSeconds(el, 'data-intro-start');
    const introEnd = readSeconds(el, 'data-intro-end');
    const outroStart = readSeconds(el, 'data-outro-start');

    if (introStart === null) {
        // Story dialog typically begins right after the recap (if any).
        introStart = recapEnd ?? 0;
    }

    return { introStart, introEnd, outroStart, recapEnd };
}

/**
 * Initialise the auto-skip overlay.
 *
 * @param {Object} opts
 * @param {HTMLVideoElement} opts.video         The HTMLMediaElement to observe / seek.
 * @param {HTMLElement}     [opts.markerSource] Element holding data-* attrs (default: opts.video).
 * @param {HTMLElement}     [opts.overlay]      Container for the button (default: video.parentElement).
 * @param {Object}          [opts.shakaPlayer]  Optional Shaka instance — uses its API for seeking when supplied.
 * @returns {{ destroy: () => void, refreshMarkers: () => void }}
 */
export function initAutoSkip(opts = {}) {
    const video = opts.video;
    if (!(video instanceof HTMLMediaElement)) {
        throw new Error('initAutoSkip: opts.video must be an HTMLMediaElement');
    }

    const source = opts.markerSource instanceof HTMLElement ? opts.markerSource : video;
    const overlayHost = opts.overlay instanceof HTMLElement
        ? opts.overlay
        : (video.parentElement ?? document.body);
    const shakaPlayer = opts.shakaPlayer ?? null;

    let markers = readMarkers(source);
    let prefs = loadPrefs();

    /** @type {HTMLDivElement|null} */
    let buttonEl = null;
    /** @type {'intro'|'outro'|'recap'|null} */
    let activeKind = null;

    /**
     * Seek the player to a target timestamp. Prefers Shaka's API when given
     * because some streaming setups (low-latency HLS) need it for clean seeks.
     */
    function seekTo(targetSec) {
        if (!Number.isFinite(targetSec)) return;

        if (shakaPlayer && typeof shakaPlayer.getMediaElement === 'function') {
            // Shaka v4+ exposes the media element; setting currentTime on it
            // is canonical and keeps Shaka's internal state consistent.
            const media = shakaPlayer.getMediaElement();
            if (media) {
                media.currentTime = targetSec;
                return;
            }
        }
        video.currentTime = targetSec;
    }

    function createButton() {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'flik-auto-skip-btn';
        // Inline minimal styling so this works even without the Tailwind layer.
        // Apps that ship `.flik-auto-skip-btn` in CSS will override these.
        Object.assign(btn.style, {
            position: 'absolute',
            right: '24px',
            bottom: '88px',
            padding: '10px 20px',
            background: 'rgba(10, 10, 10, 0.85)',
            color: '#C5A55A',
            border: '1px solid #C5A55A',
            borderRadius: '6px',
            fontSize: '14px',
            fontWeight: '600',
            letterSpacing: '0.04em',
            cursor: 'pointer',
            zIndex: '50',
            transition: 'opacity 200ms ease, transform 200ms ease',
            opacity: '0',
            transform: 'translateY(8px)',
            display: 'flex',
            alignItems: 'center',
            gap: '12px',
        });

        const label = document.createElement('span');
        label.className = 'flik-auto-skip-label';
        btn.appendChild(label);

        const toggle = document.createElement('span');
        toggle.className = 'flik-auto-skip-always';
        toggle.textContent = 'Always';
        Object.assign(toggle.style, {
            fontSize: '11px',
            opacity: '0.7',
            padding: '2px 8px',
            border: '1px solid currentColor',
            borderRadius: '999px',
            cursor: 'pointer',
            userSelect: 'none',
        });
        toggle.addEventListener('click', (ev) => {
            // Don't trigger the parent skip when toggling the preference.
            ev.stopPropagation();
            if (!activeKind) return;
            prefs = { ...prefs, [activeKind]: !prefs[activeKind] };
            savePrefs(prefs);
            renderToggleState(toggle);
        });
        btn.appendChild(toggle);

        btn.addEventListener('click', () => {
            performSkip();
        });

        return btn;
    }

    function renderToggleState(toggleEl) {
        if (!activeKind) return;
        if (prefs[activeKind]) {
            toggleEl.style.background = '#C5A55A';
            toggleEl.style.color = '#0a0a0a';
            toggleEl.textContent = 'Always: ON';
        } else {
            toggleEl.style.background = 'transparent';
            toggleEl.style.color = '';
            toggleEl.textContent = 'Always';
        }
    }

    /**
     * Decide which skip window the playhead is currently inside (if any).
     * Priority: recap > intro > outro. Recap & intro can technically overlap
     * if intro_start defaulted to recap_end — recap wins so the user sees
     * the more accurate "Skip Recap" copy first.
     *
     * @param {number} t
     * @returns {'recap'|'intro'|'outro'|null}
     */
    function classify(t) {
        if (markers.recapEnd !== null && t >= 0 && t < markers.recapEnd) {
            return 'recap';
        }
        if (markers.introEnd !== null && t >= markers.introStart && t < markers.introEnd) {
            return 'intro';
        }
        if (markers.outroStart !== null && t >= markers.outroStart) {
            return 'outro';
        }
        return null;
    }

    function targetForKind(kind) {
        switch (kind) {
            case 'recap': return markers.recapEnd;
            case 'intro': return markers.introEnd;
            case 'outro': return Number.isFinite(video.duration) ? video.duration : null;
            default:      return null;
        }
    }

    function labelForKind(kind) {
        switch (kind) {
            case 'recap': return 'Skip Recap';
            case 'intro': return 'Skip Intro';
            case 'outro': return 'Skip Credits';
            default:      return '';
        }
    }

    function performSkip() {
        if (!activeKind) return;
        const target = targetForKind(activeKind);
        if (target !== null && target > video.currentTime) {
            seekTo(target);
        }
        hideButton();
    }

    function showButton(kind) {
        if (kind === activeKind && buttonEl && buttonEl.isConnected) return;
        activeKind = kind;

        if (!buttonEl) {
            buttonEl = createButton();
        }
        if (!buttonEl.isConnected) {
            // Ensure the host can position the absolute button predictably.
            const cs = window.getComputedStyle(overlayHost);
            if (cs.position === 'static') {
                overlayHost.style.position = 'relative';
            }
            overlayHost.appendChild(buttonEl);
        }

        const label = buttonEl.querySelector('.flik-auto-skip-label');
        const toggle = buttonEl.querySelector('.flik-auto-skip-always');
        if (label) label.textContent = labelForKind(kind);
        if (toggle) renderToggleState(toggle);

        // Animate in on the next frame so the transition fires.
        requestAnimationFrame(() => {
            if (!buttonEl) return;
            buttonEl.style.opacity = '1';
            buttonEl.style.transform = 'translateY(0)';
        });
    }

    function hideButton() {
        activeKind = null;
        if (!buttonEl || !buttonEl.isConnected) return;
        buttonEl.style.opacity = '0';
        buttonEl.style.transform = 'translateY(8px)';
        // Detach after the fade so it doesn't intercept pointer events.
        const node = buttonEl;
        window.setTimeout(() => {
            if (node.isConnected && activeKind === null) {
                node.parentElement?.removeChild(node);
            }
        }, 220);
    }

    // Track which kind we've already auto-skipped this play-session so we
    // don't fight the user if they manually scrub back into the segment.
    const autoSkippedThisSession = new Set();

    function onTimeUpdate() {
        const t = video.currentTime;
        const kind = classify(t);

        if (kind === null) {
            if (activeKind !== null) hideButton();
            return;
        }

        if (prefs[kind] && !autoSkippedThisSession.has(kind)) {
            autoSkippedThisSession.add(kind);
            const target = targetForKind(kind);
            if (target !== null && target > t) {
                seekTo(target);
            }
            return;
        }

        showButton(kind);
    }

    function onSeeked() {
        // User scrubbed — re-enable auto-skip windows they jumped past.
        const t = video.currentTime;
        if (markers.recapEnd !== null && t < markers.recapEnd)            autoSkippedThisSession.delete('recap');
        if (markers.introEnd !== null && t < markers.introEnd)            autoSkippedThisSession.delete('intro');
        if (markers.outroStart !== null && t < markers.outroStart)        autoSkippedThisSession.delete('outro');
    }

    function onEnded() {
        hideButton();
    }

    video.addEventListener('timeupdate', onTimeUpdate);
    video.addEventListener('seeked', onSeeked);
    video.addEventListener('ended', onEnded);

    return {
        destroy() {
            video.removeEventListener('timeupdate', onTimeUpdate);
            video.removeEventListener('seeked', onSeeked);
            video.removeEventListener('ended', onEnded);
            hideButton();
            buttonEl = null;
        },
        refreshMarkers() {
            markers = readMarkers(source);
            autoSkippedThisSession.clear();
        },
    };
}

export default initAutoSkip;
