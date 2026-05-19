<x-layout>
    <div class="min-h-screen bg-black flex items-center justify-center py-20 px-4">
        <div class="w-full max-w-md">
            <div class="rounded-2xl overflow-hidden" style="background:#1a1a1a;border:1px solid #2a2a2a">
                <div class="p-6 text-center" style="background:linear-gradient(135deg,rgba(197,165,90,0.15),rgba(197,165,90,0.05));border-bottom:1px solid #2a2a2a">
                    <h1 class="font-heading text-2xl font-bold" style="color:#C5A55A">Checkout</h1>
                    <p class="text-gray-400 text-sm mt-1">Paket {{ $plan->name }}</p>
                </div>

                <div class="p-6 space-y-4"
                    x-data="promoApply({
                        planId: {{ $plan->id }},
                        basePrice: {{ (int) $plan->price }},
                        initialCode: @js($promoCode?->code ?? ''),
                        initialDiscount: {{ (int) round($discount ?? 0) }},
                        initialFinal: {{ (int) ($finalAmount ?? (int) $plan->price) }},
                        validateUrl: @js(route('payment.validate-promo'))
                    })">

                    @if(session('promo_warning'))
                        <div style="background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.3);color:#fbbf24;padding:10px 14px;border-radius:8px;font-size:12px">
                            ⚠ {{ session('promo_warning') }}
                        </div>
                    @endif

                    <div class="flex justify-between text-sm">
                        <span class="text-gray-400">Paket</span>
                        <span class="text-white font-semibold">{{ $plan->name }}</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-400">Durasi</span>
                        <span class="text-white">{{ $plan->duration_days ?? 30 }} hari</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-400">Harga</span>
                        <span class="text-white" :class="{ 'line-through opacity-60': discount > 0 }">
                            Rp {{ number_format((int) $plan->price, 0, ',', '.') }}
                        </span>
                    </div>

                    <div x-show="discount > 0" x-cloak class="flex justify-between text-sm" style="color:#22c55e">
                        <span>
                            Diskon (<span x-text="appliedCode"></span>)
                        </span>
                        <span>− Rp <span x-text="format(discount)"></span></span>
                    </div>

                    <hr style="border-color:#2a2a2a">

                    <div class="flex justify-between items-baseline">
                        <span class="text-gray-300 text-sm font-semibold">Total</span>
                        <span class="font-bold text-2xl" style="color:#C5A55A">
                            Rp <span x-text="format(finalPrice)"></span>
                        </span>
                    </div>

                    <hr style="border-color:#2a2a2a">

                    {{-- ── Promo code input ─────────────────────────────── --}}
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wider text-gray-400 mb-2">
                            Kode Promo
                        </label>
                        <div class="flex gap-2">
                            <input
                                type="text"
                                x-model="code"
                                @input.debounce.500ms="validate()"
                                placeholder="WELCOME10"
                                maxlength="40"
                                style="flex:1;padding:10px 12px;background:#0f0f0f;border:1px solid #2a2a2a;border-radius:8px;color:#fff;font-family:'Outfit',monospace;text-transform:uppercase;letter-spacing:0.5px;font-size:14px"
                                :style="status === 'valid' ? 'border-color:#22c55e' : (status === 'invalid' ? 'border-color:#ef4444' : '')">
                            <button type="button" @click="apply()" :disabled="status !== 'valid' || code === appliedCode"
                                style="padding:10px 16px;background:#C5A55A;color:#000;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;transition:all 0.2s"
                                :style="(status !== 'valid' || code === appliedCode) ? 'opacity:0.4;cursor:not-allowed' : ''">
                                Apply
                            </button>
                        </div>
                        <p class="mt-2 text-xs"
                            x-show="message"
                            x-cloak
                            x-text="message"
                            :style="status === 'valid' ? 'color:#22c55e' : (status === 'invalid' ? 'color:#ef4444' : 'color:#777')"></p>
                    </div>

                    <button id="pay-button" class="w-full py-3 rounded-xl text-sm font-bold text-black transition-all hover:scale-[1.02]"
                        style="background:linear-gradient(90deg,#C5A55A,#E8D5A3)">
                        Bayar Sekarang
                    </button>

                    <a href="{{ route('plans.index') }}" class="block text-center text-sm text-gray-500 hover:text-white transition-colors mt-3">
                        ← Kembali ke Plans
                    </a>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
    <script src="https://app.{{ config('services.midtrans.is_production') ? '' : 'sandbox.' }}midtrans.com/snap/snap.js"
            data-client-key="{{ $clientKey }}"></script>
    <script>
        // Live promo code validation + apply.
        //
        // Two-phase UX:
        //   1. User types → debounced POST /checkout/validate-promo →
        //      shows green badge with discount preview (no server-side
        //      state change, no redemption recorded).
        //   2. User clicks "Apply" → navigates to /checkout/{plan}?promo_code=X
        //      which regenerates the Snap token at the discounted amount.
        //
        // We need the page round-trip on Apply because the snap token is
        // signed to a specific gross_amount; we cannot mutate it client-side.
        function promoApply(cfg) {
            return {
                code: cfg.initialCode || '',
                appliedCode: cfg.initialCode || '',
                discount: cfg.initialDiscount,
                finalPrice: cfg.initialFinal,
                basePrice: cfg.basePrice,
                status: cfg.initialCode ? 'valid' : 'idle', // idle | checking | valid | invalid
                message: cfg.initialCode ? '✓ Diskon aktif' : '',

                format(n) {
                    return new Intl.NumberFormat('id-ID').format(Math.max(0, n));
                },

                async validate() {
                    const trimmed = (this.code || '').trim().toUpperCase();
                    this.code = trimmed;

                    if (!trimmed) {
                        this.status = 'idle';
                        this.message = '';
                        // Don't reset discount here — user may be re-typing the
                        // same applied code. The Apply button is disabled when
                        // code === appliedCode so they can't double-apply.
                        return;
                    }

                    this.status = 'checking';
                    this.message = 'Memeriksa…';

                    try {
                        const res = await fetch(cfg.validateUrl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                                'Accept': 'application/json',
                            },
                            body: JSON.stringify({
                                plan_id: cfg.planId,
                                code: trimmed,
                            }),
                        });
                        const data = await res.json();
                        if (data.valid) {
                            this.status = 'valid';
                            this.message = '✓ ' + (data.label || 'Kode valid') +
                                ' — hemat Rp ' + this.format(data.discount_idr);
                        } else {
                            this.status = 'invalid';
                            this.message = data.reason || 'Kode tidak valid';
                        }
                    } catch (e) {
                        this.status = 'invalid';
                        this.message = 'Gagal memvalidasi kode';
                    }
                },

                apply() {
                    const trimmed = (this.code || '').trim().toUpperCase();
                    if (!trimmed) return;
                    // Round-trip with the code applied so the Snap token
                    // is re-generated at the discounted amount.
                    const url = new URL(window.location.href);
                    url.searchParams.set('promo_code', trimmed);
                    window.location.href = url.toString();
                },
            };
        }

        document.getElementById('pay-button').addEventListener('click', function () {
            this.disabled = true;
            this.textContent = 'Memproses...';

            snap.pay('{{ $snapToken }}', {
                onSuccess: function(result) {
                    window.location.href = '/payment/success?order_id=' + result.order_id;
                },
                onPending: function(result) {
                    window.location.href = '/payment/success?order_id=' + result.order_id + '&status=pending';
                },
                onError: function(result) {
                    alert('Pembayaran gagal. Silakan coba lagi.');
                    window.location.reload();
                },
                onClose: function() {
                    const btn = document.getElementById('pay-button');
                    btn.disabled = false;
                    btn.textContent = 'Bayar Sekarang';
                }
            });
        });
    </script>
    @endpush
</x-layout>
