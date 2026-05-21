<x-layout>
    {{-- Refer-a-friend dashboard
         ──────────────────────────
         Hero: user's referral code + copy button + share triple
         (WhatsApp / Twitter / Email).
         Stats row: total / qualified / rewarded / coins earned.
         Table: every conversion this user has driven (max 100 rows).
    --}}
    @php
        $shareText = 'Yuk nonton bareng di FLiK! Daftar pakai link ini biar kita berdua dapat bonus: ' . $shareLink;
    @endphp

    <div class="min-h-screen bg-black py-20 px-4">
        <div class="mx-auto max-w-5xl">

            {{-- Header --}}
            <div class="mb-8">
                <h1 class="font-heading text-3xl md:text-4xl font-bold text-white">Refer-a-Friend</h1>
                <p class="text-gray-400 text-sm md:text-base mt-2">
                    Ajak teman menonton FLiK — kalian berdua dapat bonus koin saat mereka aktivasi paket pertama.
                </p>
            </div>

            {{-- Flash --}}
            @if(session('success'))
                <div class="mb-6 px-4 py-3 rounded-lg text-sm"
                     style="background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.3);color:#86efac">
                    {{ session('success') }}
                </div>
            @endif

            {{-- Hero card: code + share --}}
            <div class="rounded-2xl overflow-hidden mb-8"
                 style="background:linear-gradient(135deg,rgba(197,165,90,0.18),rgba(197,165,90,0.04));border:1px solid rgba(197,165,90,0.3)"
                 x-data="{ copied: false,
                           copyCode() {
                               navigator.clipboard.writeText('{{ $code }}').then(() => {
                                   this.copied = true;
                                   setTimeout(() => this.copied = false, 1800);
                               });
                           },
                           copyLink() {
                               navigator.clipboard.writeText(@js($shareLink)).then(() => {
                                   this.copied = true;
                                   setTimeout(() => this.copied = false, 1800);
                               });
                           }
                         }">
                <div class="p-6 md:p-8">
                    <p class="text-[10px] uppercase tracking-wider text-[#C5A55A]/80 font-semibold mb-2">Kode referral kamu</p>

                    <div class="flex flex-col md:flex-row items-stretch md:items-center gap-3">
                        <div class="flex-1 px-5 py-4 rounded-xl flex items-center justify-between gap-3"
                             style="background:#0f0f0f;border:1px solid #2a2a2a">
                            <code class="text-2xl md:text-3xl font-bold text-[#C5A55A] tracking-widest"
                                  style="font-family:'Outfit',monospace">{{ $code ?: '—' }}</code>
                            <button type="button" @click="copyCode()"
                                    class="text-xs font-semibold text-gray-400 hover:text-[#C5A55A] transition-colors flex items-center gap-1.5">
                                <x-icon name="download" :size="14" />
                                <span x-text="copied ? 'Tersalin!' : 'Salin'"></span>
                            </button>
                        </div>
                        <button type="button" @click="copyLink()"
                                class="px-5 py-4 rounded-xl text-sm font-bold text-black transition-all hover:scale-[1.02]"
                                style="background:linear-gradient(90deg,#C5A55A,#E8D5A3)">
                            Salin Link Ajakan
                        </button>
                    </div>

                    <p class="text-xs text-gray-500 mt-3 break-all">
                        Link share: <a href="{{ $shareLink }}" target="_blank" class="text-[#C5A55A] hover:underline">{{ $shareLink }}</a>
                    </p>

                    {{-- Share triple --}}
                    <div class="flex flex-wrap items-center gap-2 mt-5">
                        <span class="text-xs uppercase tracking-wider text-gray-500 font-semibold mr-1">Bagikan ke:</span>
                        <a href="https://wa.me/?text={{ rawurlencode($shareText) }}"
                           target="_blank" rel="noopener"
                           class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-semibold text-white transition-all hover:scale-[1.04]"
                           style="background:#25D366">
                            WhatsApp
                        </a>
                        <a href="https://twitter.com/intent/tweet?text={{ rawurlencode($shareText) }}"
                           target="_blank" rel="noopener"
                           class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-semibold text-white transition-all hover:scale-[1.04]"
                           style="background:#1DA1F2">
                            Twitter / X
                        </a>
                        <a href="mailto:?subject={{ rawurlencode('Yuk nonton bareng di FLiK!') }}&body={{ rawurlencode($shareText) }}"
                           class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-semibold text-gray-200 transition-all hover:scale-[1.04]"
                           style="background:#2a2a2a;border:1px solid #3a3a3a">
                            Email
                        </a>
                    </div>
                </div>
            </div>

            {{-- Stats row --}}
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 md:gap-4 mb-8">
                <div class="rounded-xl p-4 md:p-5" style="background:#1a1a1a;border:1px solid #2a2a2a">
                    <p class="text-[10px] uppercase tracking-wider text-gray-500 font-semibold">Total Ajak</p>
                    <p class="text-2xl md:text-3xl font-bold text-white mt-1" style="font-family:'Outfit',sans-serif">
                        {{ number_format($stats['total']) }}
                    </p>
                </div>
                <div class="rounded-xl p-4 md:p-5" style="background:#1a1a1a;border:1px solid #2a2a2a">
                    <p class="text-[10px] uppercase tracking-wider text-gray-500 font-semibold">Terkualifikasi</p>
                    <p class="text-2xl md:text-3xl font-bold text-blue-400 mt-1" style="font-family:'Outfit',sans-serif">
                        {{ number_format($stats['qualified']) }}
                    </p>
                </div>
                <div class="rounded-xl p-4 md:p-5" style="background:#1a1a1a;border:1px solid #2a2a2a">
                    <p class="text-[10px] uppercase tracking-wider text-gray-500 font-semibold">Diberi Hadiah</p>
                    <p class="text-2xl md:text-3xl font-bold text-green-400 mt-1" style="font-family:'Outfit',sans-serif">
                        {{ number_format($stats['rewarded']) }}
                    </p>
                </div>
                <div class="rounded-xl p-4 md:p-5" style="background:#1a1a1a;border:1px solid #2a2a2a">
                    <p class="text-[10px] uppercase tracking-wider text-gray-500 font-semibold">Koin Diperoleh</p>
                    <p class="text-2xl md:text-3xl font-bold text-[#C5A55A] mt-1" style="font-family:'Outfit',sans-serif">
                        {{ number_format($stats['coins_earned']) }}
                    </p>
                </div>
            </div>

            {{-- Ledger table --}}
            <div class="rounded-2xl overflow-hidden"
                 style="background:#1a1a1a;border:1px solid #2a2a2a">
                <div class="px-6 py-4 flex items-center justify-between"
                     style="border-bottom:1px solid #2a2a2a">
                    <h2 class="text-lg font-semibold text-white">Riwayat Ajakan</h2>
                    <span class="text-xs text-gray-500">{{ $conversions->count() }} dari maks 100</span>
                </div>

                @if($conversions->isEmpty())
                    <div class="px-6 py-12 text-center">
                        <div class="inline-flex items-center justify-center w-14 h-14 rounded-full mb-4"
                             style="background:#0f0f0f;border:1px solid #2a2a2a">
                            <x-icon name="user" :size="22" class="text-gray-500" />
                        </div>
                        <p class="text-gray-400 text-sm">Belum ada teman yang mendaftar pakai kode kamu.</p>
                        <p class="text-gray-500 text-xs mt-1">Bagikan link di atas untuk mulai menjaring bonus.</p>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead style="background:#0f0f0f">
                                <tr>
                                    <th class="px-6 py-3 text-left text-[10px] uppercase tracking-wider text-gray-500 font-semibold">Teman</th>
                                    <th class="px-6 py-3 text-left text-[10px] uppercase tracking-wider text-gray-500 font-semibold">Status</th>
                                    <th class="px-6 py-3 text-left text-[10px] uppercase tracking-wider text-gray-500 font-semibold">Tanggal</th>
                                    <th class="px-6 py-3 text-right text-[10px] uppercase tracking-wider text-gray-500 font-semibold">Hadiah</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($conversions as $c)
                                    @php
                                        $label = method_exists($c, 'statusLabel') ? $c->statusLabel() : ucfirst((string) $c->status);
                                        $color = method_exists($c, 'statusColor') ? $c->statusColor() : '#888';
                                    @endphp
                                    <tr style="border-top:1px solid #1f1f1f">
                                        <td class="px-6 py-3.5">
                                            <p class="text-white font-medium">
                                                {{ $c->referred->name ?? $c->referred->username ?? 'Anonim' }}
                                            </p>
                                            @if($c->referred?->email)
                                                <p class="text-xs text-gray-500 mt-0.5">{{ $c->referred->email }}</p>
                                            @endif
                                        </td>
                                        <td class="px-6 py-3.5">
                                            <span class="inline-block px-2.5 py-1 rounded-full text-[10px] font-semibold uppercase tracking-wider"
                                                  style="background:{{ $color }}22;color:{{ $color }};border:1px solid {{ $color }}55">
                                                {{ $label }}
                                            </span>
                                        </td>
                                        <td class="px-6 py-3.5 text-gray-400 text-xs">
                                            {{ $c->created_at?->translatedFormat('d M Y') }}
                                            <span class="text-gray-600">·</span>
                                            <span class="text-gray-500">{{ $c->created_at?->diffForHumans() }}</span>
                                        </td>
                                        <td class="px-6 py-3.5 text-right">
                                            @if(($c->referrer_reward_coins ?? 0) > 0)
                                                <span class="inline-flex items-center gap-1 text-[#C5A55A] font-semibold">
                                                    <x-icon name="coin" :size="14" />
                                                    {{ number_format((int) $c->referrer_reward_coins) }}
                                                </span>
                                            @else
                                                <span class="text-gray-600">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- Program rules --}}
            <div class="mt-6 px-5 py-4 rounded-xl text-xs text-gray-400"
                 style="background:#141414;border:1px solid #2a2a2a">
                <p class="font-semibold text-gray-300 mb-1">Cara kerja:</p>
                <ol class="list-decimal list-inside space-y-0.5 ml-1">
                    <li>Bagikan link / kode kamu ke teman.</li>
                    <li>Mereka daftar &amp; selesaikan onboarding (3 pertanyaan) — kamu dapat koin awal.</li>
                    <li>Mereka aktifkan paket pertama berbayar — kamu dapat bonus utama.</li>
                </ol>
            </div>

        </div>
    </div>
</x-layout>
