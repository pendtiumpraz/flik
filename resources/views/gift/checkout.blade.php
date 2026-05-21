<x-layout>
    {{-- Gift Snap-checkout page
         ────────────────────────
         Rendered by GiftSubscriptionController::purchase() after a
         GiftSubscription row + Midtrans Snap token have been minted.
         Mirrors resources/views/payment/checkout.blade.php's Snap
         embed pattern so script-tag drift between the two flows is
         minimal.

         The Snap script tag is environment-aware (sandbox subdomain
         in non-production). Pay button calls window.snap.pay() with
         the server-issued token; success/pending/error callbacks
         redirect to plans.index with a flash message — the actual
         subscription activation happens server-side in the webhook.
    --}}
    <div class="min-h-screen bg-black flex items-center justify-center py-20 px-4">
        <div class="w-full max-w-md">
            <div class="rounded-2xl overflow-hidden"
                 style="background:#1a1a1a;border:1px solid #2a2a2a">

                {{-- Header --}}
                <div class="p-6 text-center"
                     style="background:linear-gradient(135deg,rgba(197,165,90,0.15),rgba(197,165,90,0.05));border-bottom:1px solid #2a2a2a">
                    <div class="inline-flex items-center justify-center w-12 h-12 rounded-full mb-3"
                         style="background:rgba(197,165,90,0.15);border:1px solid rgba(197,165,90,0.3)">
                        <x-icon name="gift" :size="22" class="text-[#C5A55A]" />
                    </div>
                    <h1 class="font-heading text-2xl font-bold" style="color:#C5A55A">Bayar Hadiah</h1>
                    <p class="text-gray-400 text-sm mt-1">Paket {{ $plan->name }}</p>
                </div>

                {{-- Order summary --}}
                <div class="p-6 space-y-4">
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-400">Paket</span>
                        <span class="text-white font-semibold">FLiK {{ $plan->name }}</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-400">Durasi</span>
                        <span class="text-white">{{ (int) ($plan->duration_days ?? $gift->duration_days ?? 30) }} hari</span>
                    </div>
                    @if($gift->recipient_name)
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-400">Untuk</span>
                            <span class="text-white">{{ $gift->recipient_name }}</span>
                        </div>
                    @endif
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-400">Order ID</span>
                        <span class="text-gray-300 text-xs"
                              style="font-family:'Outfit',monospace">{{ $gift->midtrans_order_id }}</span>
                    </div>

                    <hr style="border-color:#2a2a2a">

                    <div class="flex justify-between items-baseline">
                        <span class="text-gray-300 text-sm font-semibold">Total</span>
                        <span class="font-bold text-2xl" style="color:#C5A55A">
                            Rp {{ number_format((int) $plan->price, 0, ',', '.') }}
                        </span>
                    </div>

                    <hr style="border-color:#2a2a2a">

                    <button id="pay-button" type="button"
                            class="w-full py-3.5 rounded-xl text-sm font-bold text-black transition-all hover:scale-[1.02]"
                            style="background:linear-gradient(90deg,#C5A55A,#E8D5A3)">
                        Bayar Sekarang
                    </button>

                    <p class="text-xs text-gray-500 text-center mt-1">
                        Kode hadiah akan dikirim ke email penerima setelah pembayaran sukses.
                    </p>

                    <a href="{{ route('plans.index') }}"
                       class="block text-center text-sm text-gray-500 hover:text-white transition-colors mt-3">
                        &larr; Kembali ke Plans
                    </a>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
    <script src="https://app.{{ config('services.midtrans.is_production') ? '' : 'sandbox.' }}midtrans.com/snap/snap.js"
            data-client-key="{{ $clientKey }}"></script>
    <script>
        // Snap.js launcher. The token was minted server-side at the
        // plan's full IDR price; we cannot mutate it client-side.
        document.addEventListener('DOMContentLoaded', function () {
            var btn = document.getElementById('pay-button');
            if (!btn) return;

            if (typeof window.snap === 'undefined') {
                // Snap script failed to load — degrade gracefully so the
                // button is not silently dead.
                btn.disabled = true;
                btn.textContent = 'Layanan pembayaran tidak tersedia';
                btn.style.opacity = '0.5';
                btn.style.cursor = 'not-allowed';
                return;
            }

            btn.addEventListener('click', function () {
                window.snap.pay(@json($snapToken), {
                    onSuccess: function () {
                        window.location.href = '{{ route('plans.index') }}?gift=success';
                    },
                    onPending: function () {
                        window.location.href = '{{ route('plans.index') }}?gift=pending';
                    },
                    onError: function () {
                        window.location.href = '{{ route('plans.index') }}?gift=error';
                    },
                    onClose: function () { /* user closed the modal — no-op */ }
                });
            });
        });
    </script>
    @endpush
</x-layout>
