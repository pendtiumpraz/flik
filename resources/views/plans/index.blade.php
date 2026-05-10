<x-layout>
    <div class="min-h-screen bg-black pt-20 pb-16"
         x-data="{
             cycle: 'monthly',
             discount: 0.20,
             price(monthly) {
                 if (this.cycle === 'yearly') {
                     return Math.round(monthly * 12 * (1 - this.discount));
                 }
                 return monthly;
             },
             format(n) {
                 return new Intl.NumberFormat('id-ID').format(n);
             }
         }">
        <div class="container mx-auto px-4 md:px-8 lg:px-16 max-w-6xl">

            <!-- Heading -->
            <div class="text-center mb-8">
                <h1 class="font-heading text-3xl md:text-5xl font-bold text-white">Pilih Paket Langganan</h1>
                <p class="text-gray-400 mt-3 text-base md:text-lg">Nikmati film tanpa batas sesuai kebutuhanmu</p>
            </div>

            <!-- Billing Toggle -->
            <div class="flex justify-center mb-10">
                <div class="inline-flex items-center gap-1 p-1 rounded-lg"
                     style="background: rgba(255,255,255,0.04); border: 1px solid rgba(197,165,90,0.2)">
                    <button type="button" @click="cycle = 'monthly'"
                            :class="cycle === 'monthly' ? 'cycle-active' : 'cycle-inactive'"
                            class="cycle-btn">
                        Bulanan
                    </button>
                    <button type="button" @click="cycle = 'yearly'"
                            :class="cycle === 'yearly' ? 'cycle-active' : 'cycle-inactive'"
                            class="cycle-btn">
                        Tahunan
                        <span class="ml-1.5 px-1.5 py-0.5 text-[9px] font-bold rounded-full text-black" style="background: linear-gradient(135deg, #C5A55A, #E8D5A3)">-20%</span>
                    </button>
                </div>
            </div>

            <!-- Plans Grid — items-stretch ensures equal heights -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-5 md:gap-6 items-stretch">
                @foreach($plans as $plan)
                @php
                    $isFeatured = $plan->slug === 'premium';
                    $features = is_array($plan->features) ? $plan->features : json_decode($plan->features ?? '[]', true);
                @endphp

                <div class="relative rounded-2xl overflow-hidden transition-all hover:translate-y-[-4px] flex flex-col h-full"
                     style="background: linear-gradient(180deg, #1a1a1a 0%, #141210 100%); border: 1px solid {{ $isFeatured ? 'rgba(197,165,90,0.55)' : 'rgba(197,165,90,0.18)' }}; box-shadow: {{ $isFeatured ? '0 10px 30px -10px rgba(197,165,90,0.3)' : 'none' }}">

                    {{-- Ribbon: position ABSOLUTE so it doesn't push content down. All cards reserve same top padding. --}}
                    @if($isFeatured)
                    <div class="absolute top-0 inset-x-0 z-10 text-center py-1.5 text-[10px] font-bold uppercase tracking-wider text-black flex items-center justify-center gap-1.5"
                         style="background: linear-gradient(90deg, #C5A55A, #E8D5A3)">
                        <x-icon name="star-solid" :size="11" class="text-black" />
                        Most Popular
                    </div>
                    @endif

                    <div class="p-5 md:p-6 pt-9 md:pt-10 flex-1 flex flex-col">
                        <!-- Plan name -->
                        <h3 class="font-heading text-lg font-bold text-white">{{ $plan->name }}</h3>
                        <p class="text-[11px] uppercase tracking-wider text-gray-500 mt-0.5">
                            @if($plan->slug === 'free') Gratis selamanya
                            @elseif($plan->slug === 'basic') Untuk pemula
                            @elseif($plan->slug === 'premium') Paling diminati
                            @elseif($plan->slug === 'family') Untuk keluarga
                            @endif
                        </p>

                        <!-- Price -->
                        <div class="mt-5">
                            @if($plan->price == 0)
                                <div class="font-heading text-3xl font-bold text-white">Rp 0</div>
                                <div class="text-xs text-gray-500 mt-1">selamanya</div>
                            @else
                                <div class="flex items-baseline gap-1">
                                    <span class="text-sm text-gray-500">Rp</span>
                                    <span class="font-heading text-3xl font-bold" style="color: #C5A55A"
                                          x-text="format(price({{ (int) $plan->price }}))"></span>
                                </div>
                                <div class="text-xs text-gray-500 mt-1">
                                    <span x-show="cycle === 'monthly'">per bulan</span>
                                    <span x-show="cycle === 'yearly'" x-cloak>per tahun</span>
                                </div>
                                <!-- Yearly savings -->
                                <div x-show="cycle === 'yearly'" x-cloak class="text-[10px] mt-1.5 inline-block px-2 py-0.5 rounded" style="background: rgba(34,197,94,0.1); color: #22c55e">
                                    Hemat Rp <span x-text="format(({{ (int) $plan->price }} * 12) - price({{ (int) $plan->price }}))"></span>/tahun
                                </div>
                            @endif
                        </div>

                        <!-- Quick specs -->
                        <div class="mt-5 space-y-2 text-sm text-gray-300">
                            <div class="flex items-center gap-2">
                                <x-icon name="film" :size="14" class="text-[#C5A55A]" />
                                <span>{{ $plan->video_quality }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <x-icon name="user" :size="14" class="text-[#C5A55A]" />
                                <span>{{ $plan->max_screens }} perangkat</span>
                            </div>
                            <div class="flex items-center gap-2">
                                @if($plan->ads_free)
                                    <x-icon name="check" :size="14" class="text-[#C5A55A]" :stroke="2.5" />
                                    <span>Tanpa iklan</span>
                                @else
                                    <x-icon name="x" :size="14" class="text-gray-600" />
                                    <span class="text-gray-500">Dengan iklan</span>
                                @endif
                            </div>
                            <div class="flex items-center gap-2">
                                @if($plan->download_enabled)
                                    <x-icon name="download" :size="14" class="text-[#C5A55A]" />
                                    <span>Download offline</span>
                                @else
                                    <x-icon name="x" :size="14" class="text-gray-600" />
                                    <span class="text-gray-500">No download</span>
                                @endif
                            </div>
                        </div>

                        <!-- CTA -->
                        @auth
                            @if($plan->price == 0)
                                <button disabled class="w-full mt-6 py-2.5 rounded-md text-sm font-medium text-gray-500" style="background: rgba(255,255,255,0.04); border: 1px solid rgba(197,165,90,0.15)">
                                    Plan Saat Ini
                                </button>
                            @elseif(\App\Http\Controllers\PaymentController::isEnabled())
                                <a href="{{ route('payment.checkout', $plan) }}"
                                   class="block w-full mt-6 py-2.5 rounded-md text-sm font-bold text-center transition-all hover:opacity-95"
                                   style="{{ $isFeatured ? 'background: linear-gradient(135deg, #C5A55A, #E8D5A3); color: #000;' : 'background: rgba(255,255,255,0.04); color: #C5A55A; border: 1px solid rgba(197,165,90,0.4);' }}">
                                    Pilih {{ $plan->name }}
                                </a>
                            @else
                                <button disabled class="w-full mt-6 py-2.5 rounded-md text-sm font-medium text-gray-600 cursor-not-allowed inline-flex items-center justify-center gap-1.5" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(197,165,90,0.1)">
                                    <x-icon name="clock" :size="13" /> Coming Soon
                                </button>
                            @endif
                        @else
                            <a href="{{ route('register') }}"
                               class="block w-full mt-6 py-2.5 rounded-md text-sm font-bold text-center transition-all hover:opacity-95"
                               style="{{ $isFeatured ? 'background: linear-gradient(135deg, #C5A55A, #E8D5A3); color: #000;' : 'background: rgba(255,255,255,0.04); color: #C5A55A; border: 1px solid rgba(197,165,90,0.4);' }}">
                                Daftar
                            </a>
                        @endauth

                        <!-- Detailed feature list -->
                        @if(is_array($features) && count($features) > 0)
                        <div class="mt-5 pt-5 space-y-2 text-xs text-gray-400" style="border-top: 1px solid rgba(197,165,90,0.1)">
                            @foreach($features as $f)
                            <div class="flex items-start gap-2">
                                <x-icon name="check" :size="11" class="text-[#C5A55A] mt-0.5 flex-shrink-0" :stroke="2.5" />
                                <span class="leading-relaxed">{{ $f }}</span>
                            </div>
                            @endforeach
                        </div>
                        @endif
                    </div>
                </div>
                @endforeach
            </div>

            <!-- Footnote -->
            <div class="text-center mt-10 text-xs text-gray-500">
                <p>Bisa cancel kapan saja. Pembayaran via Midtrans (kartu kredit, e-wallet, transfer bank).</p>
                <p class="mt-1">Annual plan = bayar 12 bulan sekaligus dengan diskon 20%.</p>
            </div>
        </div>
    </div>

    <style>
        .cycle-btn {
            display: inline-flex;
            align-items: center;
            padding: 6px 16px;
            font-size: 13px;
            font-weight: 600;
            border-radius: 6px;
            transition: all 200ms;
            border: none;
            cursor: pointer;
        }
        .cycle-active {
            background: linear-gradient(135deg, #C5A55A, #E8D5A3);
            color: #000;
        }
        .cycle-inactive {
            background: transparent;
            color: #888;
        }
        .cycle-inactive:hover { color: #C5A55A; }
    </style>
</x-layout>
