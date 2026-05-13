{{--
    FLiK cookie consent banner.

    Bottom-fixed bar with three category toggles (Necessary always-on,
    Analytics, Marketing) plus Accept All / Reject All / Save Preferences.

    The banner is hidden once `window.FlikConsent.get()` returns a non-null
    decision matching the current schema version. Other scripts subscribe to
    the `flik:cookies-updated` event to opt in/out of their integrations.

    The footer (or any UI) can re-open the banner by calling
    `window.FlikConsent.reopen()` which fires `flik:cookies-reopen`.
--}}
<div
    x-data="flikCookieBanner()"
    x-init="init()"
    x-cloak
    x-show="visible"
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="opacity-0 translate-y-6"
    x-transition:enter-end="opacity-100 translate-y-0"
    x-transition:leave="transition ease-in duration-200"
    x-transition:leave-start="opacity-100 translate-y-0"
    x-transition:leave-end="opacity-0 translate-y-6"
    role="dialog"
    aria-modal="false"
    aria-labelledby="flik-cookie-title"
    aria-describedby="flik-cookie-desc"
    class="fixed inset-x-0 bottom-0 z-[60] px-3 sm:px-6 pb-3 sm:pb-5 pointer-events-none">

    <div class="mx-auto max-w-5xl pointer-events-auto rounded-2xl shadow-2xl backdrop-blur"
         style="background: linear-gradient(180deg, rgba(20,20,20,0.97) 0%, rgba(10,10,10,0.97) 100%); border: 1px solid rgba(197,165,90,0.35)">

        <div class="p-4 sm:p-6">
            <div class="flex flex-col lg:flex-row gap-5 lg:gap-8">
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="inline-flex h-7 w-7 items-center justify-center rounded-full"
                              style="background: rgba(197,165,90,0.15)">
                            <span style="color:#C5A55A">
                                <x-icon name="info" :size="16" />
                            </span>
                        </span>
                        <h3 id="flik-cookie-title" class="font-heading text-base sm:text-lg font-semibold text-white">
                            Kami menggunakan cookie
                        </h3>
                    </div>
                    <p id="flik-cookie-desc" class="text-xs sm:text-sm text-gray-400 leading-relaxed">
                        FLiK menggunakan cookie untuk menjalankan layanan, mengukur performa, dan mempersonalisasi pengalaman menonton Anda.
                        Anda dapat memilih kategori yang diizinkan. Pengaturan ini dapat diubah kapan saja melalui tautan
                        <strong class="text-gray-300">Cookie Settings</strong> di footer. Selengkapnya: lihat
                        <a href="{{ route('legal.privacy') }}" class="underline" style="color:#C5A55A">Kebijakan Privasi</a>.
                    </p>

                    {{-- Category toggles ──────────────────────────────── --}}
                    <div class="mt-4 grid sm:grid-cols-3 gap-3">
                        {{-- Necessary (always on, disabled toggle) --}}
                        <div class="rounded-lg p-3" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.06)">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-semibold text-white">Wajib</span>
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 text-[10px] font-semibold rounded uppercase tracking-wider"
                                      style="background: rgba(197,165,90,0.15); color:#C5A55A">
                                    Aktif
                                </span>
                            </div>
                            <p class="mt-1.5 text-[11px] text-gray-500 leading-snug">
                                Sesi login, keamanan, preferensi dasar. Tidak bisa dimatikan.
                            </p>
                        </div>

                        {{-- Analytics --}}
                        <label class="cursor-pointer rounded-lg p-3 transition-colors hover:bg-white/5"
                               style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.06)">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-semibold text-white">Analitik</span>
                                <span class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors"
                                      :style="prefs.analytics ? 'background:#C5A55A' : 'background:#3a3a3a'">
                                    <input type="checkbox" class="sr-only" x-model="prefs.analytics">
                                    <span class="inline-block h-3.5 w-3.5 transform rounded-full bg-white transition-transform"
                                          :class="prefs.analytics ? 'translate-x-[18px]' : 'translate-x-0.5'"></span>
                                </span>
                            </div>
                            <p class="mt-1.5 text-[11px] text-gray-500 leading-snug">
                                Statistik penggunaan agregat untuk perbaikan produk.
                            </p>
                        </label>

                        {{-- Marketing --}}
                        <label class="cursor-pointer rounded-lg p-3 transition-colors hover:bg-white/5"
                               style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.06)">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-semibold text-white">Pemasaran</span>
                                <span class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors"
                                      :style="prefs.marketing ? 'background:#C5A55A' : 'background:#3a3a3a'">
                                    <input type="checkbox" class="sr-only" x-model="prefs.marketing">
                                    <span class="inline-block h-3.5 w-3.5 transform rounded-full bg-white transition-transform"
                                          :class="prefs.marketing ? 'translate-x-[18px]' : 'translate-x-0.5'"></span>
                                </span>
                            </div>
                            <p class="mt-1.5 text-[11px] text-gray-500 leading-snug">
                                Rekomendasi yang dipersonalisasi & kampanye relevan.
                            </p>
                        </label>
                    </div>
                </div>

                {{-- Action buttons ──────────────────────────────── --}}
                <div class="flex flex-col gap-2 lg:w-56 lg:flex-shrink-0 lg:justify-end">
                    <button type="button"
                            @click="acceptAll()"
                            class="w-full px-4 py-2.5 rounded-lg text-sm font-semibold text-black transition-opacity hover:opacity-90"
                            style="background: linear-gradient(135deg, #C5A55A, #E8D5A3)">
                        Terima Semua
                    </button>
                    <button type="button"
                            @click="savePrefs()"
                            class="w-full px-4 py-2.5 rounded-lg text-sm font-semibold text-white transition-colors hover:bg-white/10"
                            style="border: 1px solid rgba(197,165,90,0.4)">
                        Simpan Preferensi
                    </button>
                    <button type="button"
                            @click="rejectAll()"
                            class="w-full px-4 py-2 rounded-lg text-xs font-medium text-gray-400 transition-colors hover:text-white">
                        Tolak Opsional
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
    // Alpine factory — registered globally so x-data can reference it by name.
    // Uses window.FlikConsent (registered by resources/js/cookie-consent.js)
    // for all persistence + cross-script event dispatch.
    function flikCookieBanner() {
        return {
            visible: false,
            prefs: { analytics: false, marketing: false },

            init() {
                const api = window.FlikConsent;
                if (!api) {
                    // Bundle failed to load — fail open with banner hidden so we
                    // don't block the page. Page will still work without consent persistence.
                    return;
                }
                const stored = api.get();
                if (stored) {
                    this.prefs.analytics = Boolean(stored.analytics);
                    this.prefs.marketing = Boolean(stored.marketing);
                    this.visible = false;
                } else {
                    this.visible = true;
                }
                // Allow footer link to re-open the banner.
                window.addEventListener(api.REOPEN_EVENT, () => {
                    const cur = api.get();
                    if (cur) {
                        this.prefs.analytics = Boolean(cur.analytics);
                        this.prefs.marketing = Boolean(cur.marketing);
                    }
                    this.visible = true;
                });
            },

            acceptAll() {
                this.prefs.analytics = true;
                this.prefs.marketing = true;
                window.FlikConsent && window.FlikConsent.acceptAll();
                this.visible = false;
            },

            rejectAll() {
                this.prefs.analytics = false;
                this.prefs.marketing = false;
                window.FlikConsent && window.FlikConsent.rejectAll();
                this.visible = false;
            },

            savePrefs() {
                window.FlikConsent && window.FlikConsent.save({
                    analytics: this.prefs.analytics,
                    marketing: this.prefs.marketing,
                });
                this.visible = false;
            },
        };
    }
</script>
@endpush
