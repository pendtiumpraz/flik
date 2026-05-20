{{--
    FLiK PWA install banner.

    Wired to resources/js/pwa-install.js — which dispatches
    `flik:show-install-prompt` after a 30 s warm-up + cooldown checks.
    This banner is purely presentational; throttling/cooldown logic lives
    in the JS module so it can also wire `appinstalled` telemetry.

    Sits ABOVE the mobile bottom-nav (z-45) but below modals (z-50).
    Honours safe-area-inset-bottom + bumps further up when the bottom nav
    is rendered (`lg:hidden` mirrors mobile-nav.blade.php — desktop view
    pulls the banner flush to the viewport bottom instead).
--}}
<div
    x-data="{
        open: false,
        ios: false,
        init() {
            // Hide ourselves on initial paint if already standalone.
            if (window.FlikPwaInstall && window.FlikPwaInstall.isStandalone && window.FlikPwaInstall.isStandalone()) {
                return;
            }
            document.addEventListener('flik:show-install-prompt', (e) => {
                this.ios = !!(e.detail && e.detail.ios);
                this.open = true;
            });
        },
        async install() {
            // iOS path is informational — there's no native API to call.
            if (this.ios) {
                this.dismiss();
                return;
            }
            if (window.FlikPwaInstall && window.FlikPwaInstall.hasNativePrompt()) {
                await window.FlikPwaInstall.install();
            }
            this.open = false;
        },
        dismiss() {
            if (window.FlikPwaInstall) window.FlikPwaInstall.dismiss();
            this.open = false;
        }
    }"
    x-cloak
    x-show="open"
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="opacity-0 translate-y-4"
    x-transition:enter-end="opacity-100 translate-y-0"
    x-transition:leave="transition ease-in duration-200"
    x-transition:leave-start="opacity-100 translate-y-0"
    x-transition:leave-end="opacity-0 translate-y-4"
    class="fixed inset-x-0 z-[45] px-3 pointer-events-none lg:max-w-md lg:mx-auto"
    style="bottom: calc(env(safe-area-inset-bottom, 0px) + 72px);"
    role="dialog"
    aria-labelledby="pwa-install-title"
    aria-modal="false"
>
    <div
        class="pointer-events-auto rounded-2xl shadow-2xl overflow-hidden"
        style="background: linear-gradient(180deg, #1a1a1a 0%, #0f0f0f 100%); border: 1px solid rgba(197,165,90,0.35);"
    >
        <div class="flex items-start gap-3 p-4">
            <div
                class="h-10 w-10 flex-shrink-0 rounded-xl flex items-center justify-center"
                style="background: linear-gradient(135deg, #C5A55A, #E8D5A3);"
                aria-hidden="true"
            >
                <img src="{{ asset('img/flik-logo.png') }}" alt="" class="h-6 w-6 object-contain">
            </div>
            <div class="flex-1 min-w-0">
                <p id="pwa-install-title" class="text-sm font-semibold text-white">
                    <span x-show="!ios">{{ __('Install FLiK') }}</span>
                    <span x-show="ios" x-cloak>{{ __('Add FLiK to Home Screen') }}</span>
                </p>
                <p class="mt-0.5 text-xs text-gray-400 leading-snug">
                    <span x-show="!ios">{{ __('Install FLiK on your device for offline browsing and faster access.') }}</span>
                    <span x-show="ios" x-cloak>
                        {{ __('Tap') }}
                        <svg class="inline-block align-middle mx-0.5" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#C5A55A" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"></path>
                            <polyline points="16 6 12 2 8 6"></polyline>
                            <line x1="12" y1="2" x2="12" y2="15"></line>
                        </svg>
                        {{ __('Share') }} → {{ __('Add to Home Screen') }}.
                    </span>
                </p>
            </div>
            <button
                type="button"
                @click="dismiss()"
                class="flex-shrink-0 p-1 -mt-1 -mr-1 text-gray-500 hover:text-gray-300 transition-colors"
                aria-label="{{ __('Dismiss install prompt') }}"
            >
                <x-icon name="x" :size="18" />
            </button>
        </div>
        <div class="px-4 pb-4 flex items-center gap-2">
            <button
                type="button"
                @click="install()"
                class="flex-1 px-4 py-2 text-sm font-semibold text-black rounded-lg transition-opacity hover:opacity-90"
                style="background: linear-gradient(135deg, #C5A55A, #E8D5A3);"
            >
                <span x-show="!ios">{{ __('Install') }}</span>
                <span x-show="ios" x-cloak>{{ __('Got it') }}</span>
            </button>
            <button
                type="button"
                @click="dismiss()"
                class="px-3 py-2 text-xs font-medium text-gray-400 hover:text-gray-200 transition-colors"
            >
                {{ __('Not now') }}
            </button>
        </div>
    </div>
</div>
