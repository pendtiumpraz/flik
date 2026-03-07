<x-layout>
    <div class="min-h-screen bg-black flex items-center justify-center py-20 px-4">
        <div class="w-full max-w-md">
            <div class="rounded-2xl overflow-hidden" style="background:#1a1a1a;border:1px solid #2a2a2a">
                <div class="p-6 text-center" style="background:linear-gradient(135deg,rgba(197,165,90,0.15),rgba(197,165,90,0.05));border-bottom:1px solid #2a2a2a">
                    <h1 class="font-heading text-2xl font-bold" style="color:#C5A55A">💳 Checkout</h1>
                    <p class="text-gray-400 text-sm mt-1">Paket {{ $plan->name }}</p>
                </div>

                <div class="p-6 space-y-4">
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-400">Paket</span>
                        <span class="text-white font-semibold">{{ $plan->name }}</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-400">Durasi</span>
                        <span class="text-white">{{ $plan->duration_days }} hari</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-400">Harga</span>
                        <span class="text-white font-bold text-lg" style="color:#C5A55A">Rp {{ number_format($plan->price) }}</span>
                    </div>
                    <hr style="border-color:#2a2a2a">

                    <button id="pay-button" class="w-full py-3 rounded-xl text-sm font-bold text-black transition-all hover:scale-[1.02]"
                        style="background:linear-gradient(90deg,#C5A55A,#E8D5A3)">
                        🔒 Bayar Sekarang
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
        document.getElementById('pay-button').addEventListener('click', function () {
            this.disabled = true;
            this.textContent = '⏳ Memproses...';

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
                    document.getElementById('pay-button').disabled = false;
                    document.getElementById('pay-button').textContent = '🔒 Bayar Sekarang';
                }
            });
        });
    </script>
    @endpush
</x-layout>
