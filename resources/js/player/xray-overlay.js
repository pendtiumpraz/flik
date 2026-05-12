/**
 * X-Ray Overlay (Netflix-style "who's on screen?" actor info layer).
 *
 * Wraps a Shaka Player (or any HTMLVideoElement) and overlays clickable
 * hotspots above on-screen actors. Polls the backend every ~5s with the
 * current playback time and renders the returned cast members.
 *
 * Usage:
 *   import { initXrayOverlay } from './player/xray-overlay.js';
 *   const xray = initXrayOverlay({
 *     videoElement: document.getElementById('player'),
 *     movieSlug:    'inception',
 *     containerEl:  document.getElementById('player-wrap'), // positioned: relative
 *     csrfToken:    document.querySelector('meta[name=csrf-token]').content,
 *   });
 *   // later: xray.destroy();
 *
 * Backend: GET /api/xray/{movieSlug}?t={currentTime}
 *   → { actors: [ {id, name, character, bio_excerpt, photo_url, screen_x, screen_y} ] }
 */

const POLL_INTERVAL_MS = 5000;
const REQUEST_TIMEOUT_MS = 4000;

export function initXrayOverlay({
    videoElement,
    movieSlug,
    containerEl,
    csrfToken = '',
    pollIntervalMs = POLL_INTERVAL_MS,
    endpoint = null,
} = {}) {
    if (!videoElement) {
        console.warn('[xray] videoElement is required');
        return noopHandle();
    }
    if (!movieSlug) {
        console.warn('[xray] movieSlug is required');
        return noopHandle();
    }

    const host = containerEl || videoElement.parentElement;
    if (!host) {
        console.warn('[xray] could not resolve overlay host element');
        return noopHandle();
    }

    // Ensure the host can position the overlay absolutely.
    const hostStyle = window.getComputedStyle(host);
    if (hostStyle.position === 'static') {
        host.style.position = 'relative';
    }

    const url = (t) => (endpoint || `/api/xray/${encodeURIComponent(movieSlug)}`) + `?t=${t.toFixed(2)}`;

    // ── Build overlay container ────────────────────────────────
    const overlay = document.createElement('div');
    overlay.className = 'xray-overlay';
    Object.assign(overlay.style, {
        position: 'absolute',
        inset: '0',
        pointerEvents: 'none',
        zIndex: '20',
    });
    host.appendChild(overlay);

    // ── Modal (created lazily on first click) ──────────────────
    let modalEl = null;
    const openModal = (actor) => {
        if (!modalEl) modalEl = buildModal(host);
        renderModalContent(modalEl, actor);
        modalEl.classList.add('xray-modal--open');
        modalEl.style.display = 'flex';
    };

    // ── Polling state ──────────────────────────────────────────
    let timer = null;
    let lastSig = '';
    let inFlight = null;

    const poll = async () => {
        if (videoElement.paused || videoElement.ended) return;
        const t = videoElement.currentTime || 0;

        // Abort previous request if still pending.
        if (inFlight) {
            try { inFlight.abort(); } catch (_) { /* ignored */ }
        }

        const ctrl = new AbortController();
        inFlight = ctrl;
        const timeoutId = setTimeout(() => ctrl.abort(), REQUEST_TIMEOUT_MS);

        try {
            const resp = await fetch(url(t), {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
                },
                signal: ctrl.signal,
            });
            clearTimeout(timeoutId);

            if (!resp.ok) return;
            const data = await resp.json();
            const actors = Array.isArray(data?.actors) ? data.actors : [];

            // Cheap signature to skip re-render when nothing changed.
            const sig = actors.map((a) => `${a.id}:${a.screen_x ?? ''},${a.screen_y ?? ''}`).join('|');
            if (sig === lastSig) return;
            lastSig = sig;

            renderHotspots(overlay, actors, openModal);
        } catch (e) {
            if (e?.name !== 'AbortError') {
                // Network errors are non-fatal; just stay quiet.
            }
        } finally {
            clearTimeout(timeoutId);
            if (inFlight === ctrl) inFlight = null;
        }
    };

    const start = () => {
        if (timer) return;
        poll(); // immediate
        timer = window.setInterval(poll, pollIntervalMs);
    };

    const stop = () => {
        if (timer) {
            clearInterval(timer);
            timer = null;
        }
        if (inFlight) {
            try { inFlight.abort(); } catch (_) { /* ignored */ }
            inFlight = null;
        }
    };

    // Bind playback events.
    videoElement.addEventListener('play', start);
    videoElement.addEventListener('playing', start);
    videoElement.addEventListener('pause', stop);
    videoElement.addEventListener('ended', stop);
    videoElement.addEventListener('emptied', () => {
        stop();
        clearOverlay(overlay);
        lastSig = '';
    });

    // If video is already playing when initialised, start now.
    if (!videoElement.paused && !videoElement.ended) {
        start();
    }

    return {
        start,
        stop,
        destroy() {
            stop();
            videoElement.removeEventListener('play', start);
            videoElement.removeEventListener('playing', start);
            videoElement.removeEventListener('pause', stop);
            videoElement.removeEventListener('ended', stop);
            overlay.remove();
            if (modalEl) modalEl.remove();
        },
    };
}

// ── Helpers ────────────────────────────────────────────────────

function noopHandle() {
    return { start() {}, stop() {}, destroy() {} };
}

function clearOverlay(overlay) {
    while (overlay.firstChild) overlay.removeChild(overlay.firstChild);
}

function renderHotspots(overlay, actors, openModal) {
    clearOverlay(overlay);

    if (!actors.length) return;

    // Layout: spatial hotspots (with screen_x/y) get absolute pins;
    // others stack as a small "On screen now" chip-strip in the bottom-left.
    const spatial = actors.filter((a) => a.screen_x !== null && a.screen_y !== null);
    const nonSpatial = actors.filter((a) => a.screen_x === null || a.screen_y === null);

    spatial.forEach((actor) => {
        const dot = buildHotspot(actor, openModal);
        Object.assign(dot.style, {
            position: 'absolute',
            left: `${clamp(actor.screen_x, 0, 100)}%`,
            top: `${clamp(actor.screen_y, 0, 100)}%`,
            transform: 'translate(-50%, -50%)',
        });
        overlay.appendChild(dot);
    });

    if (nonSpatial.length) {
        const strip = document.createElement('div');
        Object.assign(strip.style, {
            position: 'absolute',
            left: '16px',
            bottom: '64px',
            display: 'flex',
            flexDirection: 'column',
            gap: '6px',
            pointerEvents: 'auto',
            maxWidth: '280px',
        });

        const header = document.createElement('div');
        header.textContent = 'On screen now';
        Object.assign(header.style, {
            color: '#C5A55A',
            fontSize: '11px',
            fontWeight: '600',
            letterSpacing: '0.08em',
            textTransform: 'uppercase',
            textShadow: '0 1px 2px rgba(0,0,0,0.8)',
        });
        strip.appendChild(header);

        nonSpatial.forEach((actor) => strip.appendChild(buildChip(actor, openModal)));
        overlay.appendChild(strip);
    }
}

function buildHotspot(actor, openModal) {
    const wrap = document.createElement('button');
    wrap.type = 'button';
    wrap.setAttribute('aria-label', `X-Ray: ${actor.name}`);
    Object.assign(wrap.style, {
        pointerEvents: 'auto',
        background: 'rgba(10,10,10,0.85)',
        border: '2px solid #C5A55A',
        borderRadius: '999px',
        padding: '4px 10px 4px 4px',
        display: 'flex',
        alignItems: 'center',
        gap: '8px',
        cursor: 'pointer',
        color: '#fff',
        font: '500 12px/1.2 system-ui, sans-serif',
        boxShadow: '0 2px 12px rgba(0,0,0,0.6)',
    });

    if (actor.photo_url) {
        const img = document.createElement('img');
        img.src = actor.photo_url;
        img.alt = '';
        Object.assign(img.style, {
            width: '24px',
            height: '24px',
            borderRadius: '50%',
            objectFit: 'cover',
            flex: '0 0 auto',
        });
        wrap.appendChild(img);
    }

    const text = document.createElement('span');
    text.textContent = actor.character ? `${actor.name} (${actor.character})` : actor.name;
    wrap.appendChild(text);

    wrap.addEventListener('click', (e) => {
        e.stopPropagation();
        openModal(actor);
    });

    return wrap;
}

function buildChip(actor, openModal) {
    const chip = document.createElement('button');
    chip.type = 'button';
    Object.assign(chip.style, {
        pointerEvents: 'auto',
        background: 'rgba(10,10,10,0.85)',
        border: '1px solid rgba(197,165,90,0.55)',
        borderRadius: '6px',
        padding: '6px 10px',
        display: 'flex',
        alignItems: 'center',
        gap: '8px',
        cursor: 'pointer',
        color: '#fff',
        font: '500 12px/1.2 system-ui, sans-serif',
        textAlign: 'left',
    });

    if (actor.photo_url) {
        const img = document.createElement('img');
        img.src = actor.photo_url;
        img.alt = '';
        Object.assign(img.style, {
            width: '20px', height: '20px', borderRadius: '50%', objectFit: 'cover', flex: '0 0 auto',
        });
        chip.appendChild(img);
    }

    const text = document.createElement('span');
    text.textContent = actor.character ? `${actor.name} — ${actor.character}` : actor.name;
    chip.appendChild(text);

    chip.addEventListener('click', (e) => {
        e.stopPropagation();
        openModal(actor);
    });

    return chip;
}

function buildModal(host) {
    const modal = document.createElement('div');
    modal.className = 'xray-modal';
    Object.assign(modal.style, {
        position: 'absolute',
        inset: '0',
        background: 'rgba(0,0,0,0.75)',
        display: 'none',
        alignItems: 'center',
        justifyContent: 'center',
        zIndex: '30',
        pointerEvents: 'auto',
    });

    modal.addEventListener('click', (e) => {
        if (e.target === modal) closeModal(modal);
    });

    host.appendChild(modal);
    return modal;
}

function renderModalContent(modal, actor) {
    modal.innerHTML = '';

    const card = document.createElement('div');
    Object.assign(card.style, {
        background: '#0a0a0a',
        border: '1px solid #C5A55A',
        borderRadius: '12px',
        padding: '20px',
        maxWidth: '480px',
        width: 'calc(100% - 40px)',
        maxHeight: '80%',
        overflow: 'auto',
        color: '#fff',
        font: '14px/1.55 system-ui, sans-serif',
        boxShadow: '0 12px 40px rgba(0,0,0,0.8)',
    });

    const close = document.createElement('button');
    close.type = 'button';
    close.textContent = '×';
    Object.assign(close.style, {
        position: 'absolute',
        top: '12px',
        right: '16px',
        background: 'transparent',
        color: '#C5A55A',
        border: 'none',
        font: 'bold 28px/1 sans-serif',
        cursor: 'pointer',
        zIndex: '1',
    });
    close.addEventListener('click', () => closeModal(modal));

    const header = document.createElement('div');
    Object.assign(header.style, {
        display: 'flex', gap: '14px', alignItems: 'center', marginBottom: '14px',
    });

    if (actor.photo_url) {
        const img = document.createElement('img');
        img.src = actor.photo_url;
        img.alt = '';
        Object.assign(img.style, {
            width: '72px', height: '72px', borderRadius: '50%', objectFit: 'cover', flex: '0 0 auto',
            border: '2px solid #C5A55A',
        });
        header.appendChild(img);
    }

    const titleWrap = document.createElement('div');
    const name = document.createElement('div');
    name.textContent = actor.name;
    Object.assign(name.style, { fontSize: '18px', fontWeight: '700' });
    titleWrap.appendChild(name);

    if (actor.character) {
        const role = document.createElement('div');
        role.textContent = `as ${actor.character}`;
        Object.assign(role.style, { color: '#C5A55A', fontSize: '13px', marginTop: '2px' });
        titleWrap.appendChild(role);
    }
    header.appendChild(titleWrap);

    const bio = document.createElement('p');
    bio.textContent = actor.bio_excerpt || 'Biografi belum tersedia untuk aktor ini.';
    Object.assign(bio.style, { margin: '0', color: '#e5e5e5' });

    card.style.position = 'relative';
    card.appendChild(close);
    card.appendChild(header);
    card.appendChild(bio);
    modal.appendChild(card);
}

function closeModal(modal) {
    modal.classList.remove('xray-modal--open');
    modal.style.display = 'none';
    modal.innerHTML = '';
}

function clamp(n, min, max) {
    n = Number(n);
    if (Number.isNaN(n)) return min;
    return Math.max(min, Math.min(max, n));
}

export default initXrayOverlay;
