/**
 * FLiK Content Protection System
 * Anti-screenshot, anti-right-click, dynamic watermark
 */
(function () {
    'use strict';

    // ── Anti Right-Click ──────────────────────────────────────
    document.addEventListener('contextmenu', function (e) {
        if (document.querySelector('.video-player-container')?.contains(e.target)) {
            e.preventDefault();
            return false;
        }
    });

    // ── Anti Screenshot / Screen Capture Detection ────────────
    document.addEventListener('keydown', function (e) {
        // Block PrintScreen
        if (e.key === 'PrintScreen') {
            e.preventDefault();
            navigator.clipboard.writeText('').catch(() => { });
            showProtectionToast('Screenshot diblokir untuk melindungi konten');
        }
        // Block Ctrl+Shift+S (screenshot tools)
        if (e.ctrlKey && e.shiftKey && (e.key === 'S' || e.key === 's')) {
            e.preventDefault();
            showProtectionToast('Screenshot diblokir');
        }
        // Block Ctrl+Shift+I (DevTools)
        if (e.ctrlKey && e.shiftKey && (e.key === 'I' || e.key === 'i')) {
            e.preventDefault();
        }
    });

    // ── Dynamic Watermark ─────────────────────────────────────
    function createWatermark(username) {
        const existing = document.getElementById('flik-watermark');
        if (existing) existing.remove();

        const watermark = document.createElement('div');
        watermark.id = 'flik-watermark';
        watermark.style.cssText = `
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            pointer-events: none; z-index: 9999; overflow: hidden;
        `;

        // Create grid of watermark text
        const text = username || 'FLiK User';
        const now = new Date().toISOString().slice(0, 16);
        for (let i = 0; i < 8; i++) {
            const span = document.createElement('span');
            span.textContent = `${text} • ${now}`;
            span.style.cssText = `
                position: absolute;
                top: ${10 + i * 14}%;
                left: ${-5 + (i % 3) * 20}%;
                font-size: 12px;
                color: rgba(255,255,255,0.04);
                transform: rotate(-30deg);
                white-space: nowrap;
                user-select: none;
                font-family: monospace;
            `;
            watermark.appendChild(span);
        }

        return watermark;
    }

    // ── CSS Protections ───────────────────────────────────────
    function addCSSProtections() {
        const style = document.createElement('style');
        style.textContent = `
            .video-player-container {
                -webkit-user-select: none;
                user-select: none;
                -webkit-touch-callout: none;
            }
            .video-player-container video {
                -webkit-user-drag: none;
            }
        `;
        document.head.appendChild(style);
    }

    // ── Toast Notification ────────────────────────────────────
    function showProtectionToast(message) {
        const toast = document.createElement('div');
        toast.textContent = message;
        toast.style.cssText = `
            position: fixed; bottom: 80px; left: 50%; transform: translateX(-50%);
            background: rgba(197,165,90,0.9); color: #000; padding: 10px 24px;
            border-radius: 8px; font-size: 13px; font-weight: 600; z-index: 99999;
            animation: fadeIn 0.3s ease;
        `;
        document.body.appendChild(toast);
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transition = 'opacity 0.3s';
            setTimeout(() => toast.remove(), 300);
        }, 2500);
    }

    // ── Visibility Change Detection ───────────────────────────
    document.addEventListener('visibilitychange', function () {
        const videoContainer = document.querySelector('.video-player-container');
        if (videoContainer) {
            if (document.hidden) {
                // Pause video when tab is hidden (anti screen-record helper)
                const video = videoContainer.querySelector('video');
                if (video && !video.paused) {
                    video.pause();
                    video.dataset.wasPlaying = 'true';
                }
            } else {
                const video = videoContainer.querySelector('video');
                if (video && video.dataset.wasPlaying === 'true') {
                    video.play().catch(() => { });
                    delete video.dataset.wasPlaying;
                }
            }
        }
    });

    // ── Initialize ────────────────────────────────────────────
    addCSSProtections();

    // Enable watermark on video pages
    const playerContainer = document.querySelector('.video-player-container');
    if (playerContainer) {
        const username = document.querySelector('meta[name="user-name"]')?.content || '';
        if (username) {
            playerContainer.appendChild(createWatermark(username));
        }
    }
})();
