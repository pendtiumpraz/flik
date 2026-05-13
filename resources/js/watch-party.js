/**
 * Watch Party client logic.
 *
 * Responsibilities:
 *  - Subscribe to presence-watch-party.{roomCode} via Pusher.
 *  - Host: intercept play/pause/seek on the <video>, POST to /sync,
 *    Pusher fans out a `watch-party.sync` event to every other member.
 *  - Member: receive sync events and apply them to the local <video>
 *    (seek + play/pause). Suppress feedback loops with a local guard
 *    flag so applying a remote action doesn't re-broadcast.
 *  - Chat: simple POST → broadcast → append to log.
 *  - Member roster: presence subscription_succeeded / member_added /
 *    member_removed events keep the side panel live.
 *
 * Pusher is optional: if `data-pusher-enabled` is 0 we still wire up
 * local controls (so the host can preview the room) but skip subscribe
 * + broadcast — the server-side controller surfaces the same warning.
 */

(function () {
    const boot = document.getElementById('watch-party-bootstrap');
    if (!boot) return;

    const cfg = {
        roomCode: boot.dataset.roomCode,
        isHost: boot.dataset.isHost === '1',
        userId: parseInt(boot.dataset.userId, 10),
        userName: boot.dataset.userName,
        position: parseFloat(boot.dataset.position) || 0,
        isPlaying: boot.dataset.isPlaying === '1',
        pusherEnabled: boot.dataset.pusherEnabled === '1',
        pusherKey: boot.dataset.pusherKey,
        pusherCluster: boot.dataset.pusherCluster,
        syncUrl: boot.dataset.syncUrl,
        chatUrl: boot.dataset.chatUrl,
        leaveUrl: boot.dataset.leaveUrl,
        csrf: boot.dataset.csrf,
    };

    const video = document.getElementById('wp-video');
    const statusEl = document.getElementById('wp-status');
    const chatLog = document.getElementById('wp-chat-log');
    const chatForm = document.getElementById('wp-chat-form');
    const chatInput = document.getElementById('wp-chat-input');
    const memberList = document.getElementById('wp-member-list');
    const memberCount = document.getElementById('wp-member-count');

    // Suppression flag — when we're applying a remote action to the local
    // player, the resulting play/pause/seeked events should NOT be re-broadcast.
    let applyingRemote = false;

    function setStatus(msg, isError = false) {
        if (!statusEl) return;
        statusEl.textContent = msg;
        statusEl.classList.toggle('text-red-400', !!isError);
        statusEl.classList.toggle('text-gray-500', !isError);
    }

    // XSS NOTE: this function uses `innerHTML` for layout convenience.
    // Both `name` and `message` MUST be passed through `escapeHtml()`
    // (below) before interpolation. The class-list strings on the
    // template are static, but if you add any new dynamic field here
    // wrap it in escapeHtml() too — and as a second line of defence
    // the server-side WatchPartyChat event already sanitizes incoming
    // messages with App\Services\Security\HtmlSanitizer. See
    // docs/security/xss-audit.md.
    function appendChat(name, message, mine = false) {
        if (!chatLog) return;
        const wrap = document.createElement('div');
        wrap.className = mine ? 'text-right' : '';
        wrap.innerHTML = `
            <div class="inline-block max-w-[85%] px-3 py-1.5 rounded-lg ${mine ? 'bg-[#C5A55A] text-black' : 'bg-white/10 text-gray-100'}">
                <div class="text-[10px] uppercase tracking-wider ${mine ? 'text-black/60' : 'text-[#C5A55A]'} mb-0.5">${escapeHtml(name)}</div>
                <div class="text-sm whitespace-pre-wrap break-words">${escapeHtml(message)}</div>
            </div>
        `;
        chatLog.appendChild(wrap);
        chatLog.scrollTop = chatLog.scrollHeight;
    }

    function appendSystem(message) {
        if (!chatLog) return;
        const el = document.createElement('div');
        el.className = 'text-xs text-gray-500 italic text-center';
        el.textContent = message;
        chatLog.appendChild(el);
        chatLog.scrollTop = chatLog.scrollHeight;
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        })[c]);
    }

    // ── HTTP helpers ───────────────────────────────────────────
    async function postJson(url, body) {
        const res = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': cfg.csrf,
            },
            body: JSON.stringify(body || {}),
        });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.json();
    }

    // ── Restore initial state on load ─────────────────────────
    if (video) {
        video.addEventListener('loadedmetadata', () => {
            try {
                if (cfg.position > 0) {
                    applyingRemote = true;
                    video.currentTime = cfg.position;
                    setTimeout(() => { applyingRemote = false; }, 200);
                }
                if (cfg.isPlaying && !cfg.isHost) {
                    video.play().catch(() => {
                        setStatus('Klik tombol play untuk mulai (browser auto-play diblokir).');
                    });
                }
            } catch (e) { /* ignore */ }
        });
    }

    // ── Host: broadcast play/pause/seek ───────────────────────
    if (cfg.isHost && video) {
        let lastSync = 0;
        const SYNC_THROTTLE_MS = 250;

        async function broadcast(action) {
            if (applyingRemote) return;
            const now = Date.now();
            if (action === 'seek' && now - lastSync < SYNC_THROTTLE_MS) return;
            lastSync = now;
            try {
                const r = await postJson(cfg.syncUrl, {
                    action,
                    position: video.currentTime || 0,
                });
                if (r && r.warning) setStatus(r.warning, true);
            } catch (e) {
                setStatus('Gagal kirim sync: ' + e.message, true);
            }
        }

        video.addEventListener('play', () => broadcast('play'));
        video.addEventListener('pause', () => broadcast('pause'));
        video.addEventListener('seeked', () => broadcast('seek'));
    } else if (video) {
        // Member: prevent accidental local control desync.
        // We keep controls enabled so the user can adjust volume etc. but
        // play/pause/seek are reconciled to the host on next sync event.
    }

    // ── Pusher subscribe ──────────────────────────────────────
    let channel = null;
    if (cfg.pusherEnabled && typeof Pusher !== 'undefined' && cfg.pusherKey) {
        try {
            const pusher = new Pusher(cfg.pusherKey, {
                cluster: cfg.pusherCluster || 'mt1',
                forceTLS: true,
                authEndpoint: '/broadcasting/auth',
                auth: {
                    headers: { 'X-CSRF-TOKEN': cfg.csrf },
                },
            });

            channel = pusher.subscribe('presence-watch-party.' + cfg.roomCode);

            channel.bind('pusher:subscription_succeeded', (members) => {
                setStatus('Tersambung ke room (' + (members.count || 0) + ' anggota).');
            });

            channel.bind('pusher:subscription_error', (err) => {
                setStatus('Gagal terhubung ke Pusher: ' + (err && err.error ? err.error : 'unknown'), true);
            });

            channel.bind('pusher:member_added', (m) => {
                appendSystem((m.info && m.info.name ? m.info.name : 'Seseorang') + ' bergabung.');
                if (memberCount) memberCount.textContent = String(parseInt(memberCount.textContent || '0', 10) + 1);
            });

            channel.bind('pusher:member_removed', (m) => {
                appendSystem((m.info && m.info.name ? m.info.name : 'Seseorang') + ' keluar.');
                if (memberCount) memberCount.textContent = String(Math.max(0, parseInt(memberCount.textContent || '0', 10) - 1));
            });

            channel.bind('watch-party.sync', (payload) => {
                handleSync(payload);
            });
        } catch (e) {
            setStatus('Pusher init error: ' + e.message, true);
        }
    } else if (!cfg.pusherEnabled) {
        setStatus('Mode preview lokal — Pusher tidak aktif, sinkronisasi nonaktif.');
    }

    function handleSync(payload) {
        if (!payload) return;
        const { action, position, user_id, extra } = payload;

        // Don't re-apply our own broadcasts.
        if (user_id === cfg.userId && (action === 'play' || action === 'pause' || action === 'seek')) {
            return;
        }

        if (action === 'chat' && extra && extra.message) {
            appendChat(extra.user_name || 'Anonim', extra.message, false);
            return;
        }

        if (action === 'join' && extra && extra.user_name) {
            // member_added already covers presence; keep this idempotent.
            return;
        }

        if (action === 'leave') {
            if (extra && extra.ended) {
                appendSystem('Host menutup Watch Party. Anda akan diarahkan kembali.');
                setStatus('Watch Party berakhir.', true);
                setTimeout(() => { window.location.href = '/movies'; }, 2500);
            }
            return;
        }

        // Members reconcile their video to the host.
        if (!cfg.isHost && video) {
            try {
                applyingRemote = true;
                if (typeof position === 'number' && Math.abs(video.currentTime - position) > 1.0) {
                    video.currentTime = position;
                }
                if (action === 'play') {
                    video.play().catch(() => setStatus('Browser auto-play diblokir — klik play.'));
                } else if (action === 'pause') {
                    video.pause();
                } else if (action === 'seek') {
                    if (typeof position === 'number') video.currentTime = position;
                }
                setTimeout(() => { applyingRemote = false; }, 250);
            } catch (e) {
                applyingRemote = false;
            }
        }
    }

    // ── Chat ───────────────────────────────────────────────────
    if (chatForm && chatInput) {
        chatForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const msg = (chatInput.value || '').trim();
            if (!msg) return;
            chatInput.value = '';
            appendChat(cfg.userName, msg, true);
            try {
                const r = await postJson(cfg.chatUrl, { message: msg });
                if (r && r.warning) setStatus(r.warning, true);
            } catch (err) {
                setStatus('Gagal kirim chat: ' + err.message, true);
            }
        });
    }
})();
