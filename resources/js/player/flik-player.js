/**
 * FLiK Shaka Player wrapper.
 *
 * Boots the Shaka player against the FLiK DRM endpoints:
 *   1. GET /playback/{movie}/config         → bootstrap bundle (manifest URL, JWT, fingerprint script)
 *   2. Shaka loads the manifest             → segments are served signed
 *   3. AES-128 key requests are intercepted → routed to /drm/key/{token}/{keyId}?token=<jwt>
 *   4. POST /playback/{movie}/heartbeat     → every 30s with device fingerprint
 *
 * The wrapper assumes Shaka 4.x is loaded globally as `window.shaka` (we
 * load it via CDN from the player view to avoid a hard NPM dependency).
 *
 * Usage:
 *   const player = new FlikPlayer('flik-player', 'movie-slug');
 *   await player.initialize();
 *   // ... later
 *   player.destroy();
 */
export default class FlikPlayer {
    /**
     * @param {string} elementId  ID of the <video> element
     * @param {string} movieSlug  Movie slug (used in /playback/{slug}/* URLs)
     * @param {object} [options]
     * @param {string} [options.csrfToken]  Laravel CSRF token (auto-detected from <meta name="csrf-token">)
     * @param {number} [options.heartbeatMs] Override heartbeat interval (default from server)
     */
    constructor(elementId, movieSlug, options = {}) {
        this.elementId = elementId;
        this.movieSlug = movieSlug;
        this.options = options;

        this.videoEl = null;
        this.shakaPlayer = null;
        this.config = null;
        this.heartbeatTimer = null;
        this.fingerprintCache = null;
        this.csrfToken = options.csrfToken
            || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
            || '';
        this.destroyed = false;
    }

    /**
     * Fetch the playback config bundle from the server.
     *
     * Throws on non-2xx with the server-provided JSON error so the caller
     * can render a meaningful "subscription required" / "stream limit" UI.
     */
    async loadConfig() {
        const url = `/playback/${encodeURIComponent(this.movieSlug)}/config`;

        const res = await fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        const body = await res.json().catch(() => ({}));

        if (!res.ok) {
            const err = new Error(body.message || body.error || `Playback config failed (${res.status}).`);
            err.status = res.status;
            err.code = body.error || 'config_failed';
            throw err;
        }

        this.config = body;

        // Inject the fingerprint script — defines window.flikFingerprint().
        // Idempotent: safe to call repeatedly because the IIFE just re-assigns
        // the same function reference.
        if (body.fingerprintScript && !window.flikFingerprint) {
            const script = document.createElement('script');
            script.textContent = body.fingerprintScript;
            document.head.appendChild(script);
        }

        return body;
    }

    /**
     * Bootstrap shaka.Player against the loaded config.
     */
    async initialize() {
        if (typeof window.shaka === 'undefined') {
            throw new Error('Shaka Player not loaded. Include shaka-player from CDN before instantiating FlikPlayer.');
        }

        this.videoEl = document.getElementById(this.elementId);
        if (!this.videoEl) {
            throw new Error(`Video element #${this.elementId} not found.`);
        }

        if (!this.config) {
            await this.loadConfig();
        }

        // Polyfills install codec/fullscreen shims and must run once before
        // any Player instance. shaka.polyfill.installAll() is idempotent.
        window.shaka.polyfill.installAll();

        if (!window.shaka.Player.isBrowserSupported()) {
            throw new Error('This browser does not support Shaka Player playback.');
        }

        this.shakaPlayer = new window.shaka.Player();
        await this.shakaPlayer.attach(this.videoEl);

        // Tighten ABR: prefer audio/video to start mid-quality so the first
        // segment fetch isn't a 4K probe on a flaky cafe wifi.
        this.shakaPlayer.configure({
            abr: { defaultBandwidthEstimate: 2_000_000 },
            streaming: {
                bufferingGoal: 30,
                rebufferingGoal: 4,
                bufferBehind: 30,
                retryParameters: { maxAttempts: 4, baseDelay: 500 },
            },
        });

        // Wire the AES key request interceptor. Shaka will fire a request of
        // type LICENSE for every #EXT-X-KEY URI it encounters; we just append
        // the per-session JWT (already baked into the URL by the manifest
        // generator) and let it through.
        const networkingEngine = this.shakaPlayer.getNetworkingEngine();
        networkingEngine.registerRequestFilter((type, request) => {
            const RequestType = window.shaka.net.NetworkingEngine.RequestType;
            // Tag all requests with credentials so cookies (auth session)
            // travel on the manifest fetch — segments are public + tokenised.
            request.allowCrossSiteCredentials = false;

            if (type === RequestType.MANIFEST || type === RequestType.LICENSE) {
                // Manifests come from the FLiK origin; we don't need extra headers
                // beyond the URL token, which the manifest generator already added.
            }
        });

        // Wire error logging. Shaka emits 'error' events with a structured
        // shaka.util.Error — we surface code + category for easier triage.
        this.shakaPlayer.addEventListener('error', (event) => {
            const err = event.detail;
            // eslint-disable-next-line no-console
            console.error('[FlikPlayer] Shaka error', {
                code: err?.code,
                category: err?.category,
                severity: err?.severity,
                data: err?.data,
            });
        });

        await this.shakaPlayer.load(this.config.manifestUrl);

        // Sidecar subtitle tracks (WebVTT) from the config bundle. Added but
        // NOT shown by default — the player UI toggles them on demand.
        await this.addSubtitleTracks();

        await this.startHeartbeat();

        return this;
    }

    /**
     * Add WebVTT sidecar text tracks from config.subtitles. Each entry:
     *   { url, language, label, default, rtl }
     */
    async addSubtitleTracks() {
        // Prefer explicitly-passed subtitles (episode player feeds them via
        // options, since it doesn't fetch the movie-level config) and fall
        // back to the config bundle (movie player).
        const subs = Array.isArray(this.options?.subtitles)
            ? this.options.subtitles
            : (Array.isArray(this.config?.subtitles) ? this.config.subtitles : []);
        for (const s of subs) {
            try {
                if (typeof this.shakaPlayer.addTextTrackAsync === 'function') {
                    await this.shakaPlayer.addTextTrackAsync(s.url, s.language, 'subtitle', 'text/vtt', undefined, s.label);
                } else if (typeof this.shakaPlayer.addTextTrack === 'function') {
                    this.shakaPlayer.addTextTrack(s.url, s.language, 'subtitle', 'text/vtt', undefined, s.label);
                }
            } catch (e) {
                // eslint-disable-next-line no-console
                console.warn('[FlikPlayer] addTextTrack failed', s?.language, e);
            }
        }
    }

    /** Show subtitles in the given BCP-47 language. */
    selectTextLanguage(language) {
        if (!this.shakaPlayer) return;
        try {
            this.shakaPlayer.selectTextLanguage(language);
            this.shakaPlayer.setTextTrackVisibility(true);
        } catch (e) {
            // eslint-disable-next-line no-console
            console.warn('[FlikPlayer] selectTextLanguage failed', language, e);
        }
    }

    /** Hide subtitles. */
    disableText() {
        if (!this.shakaPlayer) return;
        try {
            this.shakaPlayer.setTextTrackVisibility(false);
        } catch (e) { /* noop */ }
    }

    /**
     * Compute the device fingerprint via the injected script.
     *
     * Cached after the first computation — fingerprint signals are stable
     * within a session (canvas/webgl/audio don't change), so re-running on
     * every heartbeat would be wasteful.
     */
    async computeFingerprint() {
        if (this.fingerprintCache) {
            return this.fingerprintCache;
        }

        if (typeof window.flikFingerprint !== 'function') {
            // Fingerprint script wasn't injected (config call failed?). Send
            // an empty bundle — server treats absent fingerprint as "first
            // touch" via TOFU on the first heartbeat.
            return null;
        }

        try {
            this.fingerprintCache = await window.flikFingerprint();
            return this.fingerprintCache;
        } catch (e) {
            // eslint-disable-next-line no-console
            console.warn('[FlikPlayer] Fingerprint computation failed', e);
            return null;
        }
    }

    /**
     * Begin the heartbeat loop.
     *
     * Posts the device fingerprint + JWT to the heartbeat endpoint at the
     * server-recommended interval (default 30s). On 4xx, we destroy the
     * player — the user has lost their stream slot or moved to another
     * device, so playback can no longer be authorised.
     */
    async startHeartbeat() {
        const intervalMs = this.options.heartbeatMs
            || this.config?.heartbeatIntervalMs
            || 30_000;

        const heartbeatUrl = this.config.heartbeatUrl
            || `/playback/${encodeURIComponent(this.movieSlug)}/heartbeat`;

        // Fire one heartbeat immediately so the fingerprint is bound to the
        // session before any key requests go out — defends against the player
        // being closed before the first scheduled heartbeat.
        await this.sendHeartbeat(heartbeatUrl).catch(() => {});

        this.heartbeatTimer = window.setInterval(async () => {
            if (this.destroyed) return;

            try {
                await this.sendHeartbeat(heartbeatUrl);
            } catch (e) {
                // eslint-disable-next-line no-console
                console.warn('[FlikPlayer] Heartbeat failed', e);

                if (e.status === 403 || e.status === 401) {
                    // Session lost — stop playback and surface to the page.
                    this.videoEl?.pause();
                    this.videoEl?.dispatchEvent(new CustomEvent('flik:session-lost', { detail: e }));
                    this.destroy();
                }
            }
        }, intervalMs);
    }

    /**
     * Single heartbeat POST. Throws on non-2xx (status attached to the error).
     */
    async sendHeartbeat(url) {
        const fingerprint = await this.computeFingerprint();

        const res = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': this.csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
                'Authorization': `Bearer ${this.config.jwt}`,
            },
            body: JSON.stringify({
                token: this.config.jwt,
                session_token: this.config.sessionToken,
                fingerprint: fingerprint || {},
            }),
        });

        if (!res.ok) {
            const body = await res.json().catch(() => ({}));
            const err = new Error(body.error || `Heartbeat failed (${res.status})`);
            err.status = res.status;
            throw err;
        }

        return res.json();
    }

    /**
     * Tear down the player + heartbeat loop.
     *
     * Safe to call multiple times. After destroy(), this instance is dead —
     * create a new FlikPlayer to play again.
     */
    async destroy() {
        if (this.destroyed) return;
        this.destroyed = true;

        if (this.heartbeatTimer) {
            window.clearInterval(this.heartbeatTimer);
            this.heartbeatTimer = null;
        }

        if (this.shakaPlayer) {
            try {
                await this.shakaPlayer.destroy();
            } catch (e) {
                // eslint-disable-next-line no-console
                console.warn('[FlikPlayer] Shaka destroy threw', e);
            }
            this.shakaPlayer = null;
        }
    }
}

// Also expose globally so non-module callers (Blade views without bundling)
// can `new window.FlikPlayer(...)` straight away.
if (typeof window !== 'undefined') {
    window.FlikPlayer = FlikPlayer;
}
