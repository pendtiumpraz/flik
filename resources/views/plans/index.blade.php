<x-layout>
    <div class="min-h-screen bg-black pt-20 pb-16">
        <div class="container mx-auto px-4 md:px-16">

            <div class="text-center mb-12">
                <h1 class="font-heading text-3xl md:text-5xl font-bold text-white">Pilih Paket Langganan</h1>
                <p class="text-gray-400 mt-3 text-lg">Nikmati film tanpa batas sesuai kebutuhanmu</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 max-w-6xl mx-auto">
                @foreach($plans as $plan)
                <div class="relative rounded-2xl overflow-hidden transition-all hover:scale-105 {{ $plan->slug === 'premium' ? 'ring-2' : '' }}"
                     style="background:#1a1a1a;border:1px solid {{ $plan->slug === 'premium' ? '#C5A55A' : '#2a2a2a' }}; {{ $plan->slug === 'premium' ? 'ring-color:#C5A55A;' : '' }}">

                    @if($plan->slug === 'premium')
                    <div class="text-center py-1 text-xs font-bold text-black" style="background:linear-gradient(90deg,#C5A55A,#E8D5A3)">
                        ⭐ MOST POPULAR
                    </div>
                    @endif

                    <div class="p-6">
                        <h3 class="font-heading text-xl font-bold text-white">{{ $plan->name }}</h3>
                        <div class="mt-4">
                            <span class="text-3xl font-heading font-bold" style="color:#C5A55A">{{ $plan->formatted_price }}</span>
                            @if($plan->price > 0)
                                <span class="text-sm text-gray-500">/{{ $plan->billing_cycle === 'monthly' ? 'bulan' : 'tahun' }}</span>
                            @endif
                        </div>

                        <div class="mt-4 space-y-2 text-sm text-gray-400">
                            <div class="flex items-center gap-2">
                                <span style="color:#C5A55A">📺</span>
                                <span>{{ $plan->video_quality }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span style="color:#C5A55A">📱</span>
                                <span>{{ $plan->max_screens }} perangkat</span>
                            </div>
                            @if($plan->ads_free)
                            <div class="flex items-center gap-2">
                                <span style="color:#22c55e">✓</span>
                                <span>Tanpa iklan</span>
                            </div>
                            @endif
                            @if($plan->download_enabled)
                            <div class="flex items-center gap-2">
                                <span style="color:#22c55e">✓</span>
                                <span>Download offline</span>
                            </div>
                            @endif
                        </div>

                        @if($plan->features)
                        <div class="mt-4 pt-4 space-y-2 text-sm" style="border-top:1px solid #2a2a2a">
                            @foreach(json_decode($plan->features) as $feature)
                            <div class="flex items-start gap-2">
                                <span class="text-green-500 mt-0.5">✓</span>
                                <span class="text-gray-400">{{ $feature }}</span>
                            </div>
                            @endforeach
                        </div>
                        @endif

                        @if($plan->price == 0)
                            <a href="{{ route('payment.checkout', $plan) }}" class="block w-full mt-6 py-3 rounded-lg font-semibold text-sm text-center transition-colors text-white" style="background:#333">
                                Mulai Gratis
                            </a>
                        @elseif(\App\Http\Controllers\PaymentController::isEnabled())
                            <a href="{{ route('payment.checkout', $plan) }}" class="block w-full mt-6 py-3 rounded-lg font-semibold text-sm text-center transition-all hover:scale-[1.02] {{ $plan->slug === 'premium' ? 'text-black' : 'text-white' }}"
                               style="{{ $plan->slug === 'premium' ? 'background:linear-gradient(90deg,#C5A55A,#E8D5A3)' : 'background:#333' }}">
                                Pilih {{ $plan->name }}
                            </a>
                        @else
                            <button disabled class="w-full mt-6 py-3 rounded-lg font-semibold text-sm text-gray-600 cursor-not-allowed" style="background:#252525" title="Payment gateway belum dikonfigurasi">
                                🔒 Coming Soon
                            </button>
                        @endif
                    </div>
                </div>
                @endforeach
            </div>

            <div class="text-center mt-12 text-gray-600 text-sm">
                <p>Semua paket bisa dibatalkan kapan saja. Harga sudah termasuk pajak.</p>
            </div>
        </div>
    </div>
</x-layout>
