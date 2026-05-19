{{--
    <x-push-opt-in />

    Floating banner that asks the visitor to enable browser notifications.
    Hides itself when:
      - The browser doesn't support Notification + PushManager
      - VAPID is not configured server-side (no meta tag emitted)
      - The user already has a subscription
      - The user dismissed the banner (localStorage flag)

    Mount on home/dashboard pages near the top of the body, AFTER @vite
    has loaded so window.FlikPush is defined.
--}}
@if (config('services.push.public_key'))
<div x-data="flikPushOptIn()"
     x-init="boot()"
     x-show="visible"
     x-cloak
     x-transition.opacity.duration.300ms
     style="position:fixed;bottom:24px;right:24px;z-index:9000;max-width:360px;background:#1a1a1a;border:1px solid #2a2a2a;border-radius:14px;padding:18px 20px;box-shadow:0 12px 40px rgba(0,0,0,0.5);font-family:'Inter',sans-serif;color:#e5e5e5">
    <div style="display:flex;gap:14px;align-items:flex-start">
        <div style="flex-shrink:0;width:42px;height:42px;border-radius:10px;background:rgba(197,165,90,0.15);display:flex;align-items:center;justify-content:center">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#C5A55A" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
            </svg>
        </div>
        <div style="flex:1">
            <h3 style="font-family:'Outfit',sans-serif;font-size:15px;font-weight:700;color:#fff;margin:0 0 4px">
                Get notified about new releases
            </h3>
            <p style="font-size:12.5px;color:#aaa;line-height:1.5;margin:0">
                Be the first to know when fresh Indonesian movies land on FLiK.
                You can turn this off anytime.
            </p>
            <div style="display:flex;gap:8px;margin-top:12px">
                <button type="button" @click="enable()"
                        :disabled="working"
                        style="background:#C5A55A;color:#000;border:none;padding:7px 14px;border-radius:8px;font-size:12.5px;font-weight:600;cursor:pointer;transition:background 0.2s"
                        x-text="working ? 'Working…' : 'Enable'">
                    Enable
                </button>
                <button type="button" @click="dismiss()"
                        style="background:transparent;color:#888;border:1px solid #333;padding:7px 14px;border-radius:8px;font-size:12.5px;font-weight:500;cursor:pointer">
                    Not now
                </button>
            </div>
            <p x-show="error" x-text="error" x-cloak style="font-size:11.5px;color:#ef4444;margin:8px 0 0"></p>
        </div>
    </div>
</div>

<script>
    // Alpine factory — registered with the global x-data so we avoid the
    // double-init footgun of inline x-data={…} blocks.
    function flikPushOptIn() {
        return {
            visible: false,
            working: false,
            error: '',

            async boot() {
                // window.FlikPush comes from resources/js/push-notifications.js
                if (!window.FlikPush || !window.FlikPush.isSupported()) return;
                if (window.FlikPush.isDismissed()) return;
                if (Notification.permission === 'denied') return;

                const already = await window.FlikPush.hasSubscription();
                if (already) return;

                this.visible = true;
            },

            async enable() {
                this.working = true;
                this.error = '';
                try {
                    const ok = await window.FlikPush.requestSubscription();
                    if (ok) {
                        this.visible = false;
                    } else {
                        const perm = window.FlikPush.currentPermission();
                        if (perm === 'denied') {
                            this.error = 'Notifications are blocked. Enable them in your browser settings.';
                        } else {
                            this.error = 'Could not enable notifications. Please try again.';
                        }
                    }
                } catch (e) {
                    this.error = 'Something went wrong. Please try again later.';
                } finally {
                    this.working = false;
                }
            },

            dismiss() {
                window.FlikPush.dismiss();
                this.visible = false;
            },
        };
    }
</script>
@endif
