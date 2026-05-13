<x-layout title="Active Sessions — FLiK">
    <div class="min-h-screen bg-black pt-20 pb-16" x-data="{ confirmAll: false }">
        <div class="container mx-auto px-4 md:px-16 max-w-4xl">

            {{-- Page Header --}}
            <div class="mb-8">
                <div class="flex items-center gap-3 mb-3">
                    <a href="{{ route('profile.show') }}" class="text-gray-500 hover:text-[#C5A55A] transition-colors text-sm flex items-center gap-1">
                        <x-icon name="chevron-left" :size="14" />
                        Profile
                    </a>
                </div>
                <h1 class="font-heading text-3xl md:text-4xl font-bold text-white">Active Sessions</h1>
                <p class="text-gray-400 text-sm mt-2 max-w-2xl">
                    Daftar device yang saat ini login ke akun Anda. Cabut sesi apa pun yang tidak Anda kenali untuk menjaga keamanan akun.
                </p>
            </div>

            {{-- Driver hint when sessions table is empty (likely SESSION_DRIVER != database) --}}
            @if($sessions->isEmpty())
                <div class="rounded-xl p-8 text-center" style="background:#1a1a1a;border:1px solid #2a2a2a">
                    <div class="mx-auto mb-4 w-14 h-14 rounded-full flex items-center justify-center" style="background:rgba(197,165,90,0.12)">
                        <x-icon name="server" :size="28" class="text-[#C5A55A]" />
                    </div>
                    <h3 class="font-heading text-lg font-semibold text-white">Belum ada data sesi</h3>
                    <p class="text-gray-500 text-sm mt-2 max-w-md mx-auto">
                        Sesi aktif baru akan muncul setelah Anda login ulang dengan database session driver aktif. Coba logout lalu login kembali.
                    </p>
                </div>
            @else
                {{-- Sessions list --}}
                <div class="space-y-3">
                    @foreach($sessions as $session)
                        @php
                            $isCurrent = $session['is_current'];
                            $deviceIcon = match ($session['device']) {
                                'Mobile' => 'chat',
                                'Tablet' => 'film',
                                default  => 'server',
                            };
                        @endphp
                        <div class="rounded-xl p-5 flex flex-col sm:flex-row gap-4 sm:items-center transition-colors"
                             style="background:#1a1a1a;border:1px solid {{ $isCurrent ? 'rgba(197,165,90,0.4)' : '#2a2a2a' }}">

                            {{-- Device icon --}}
                            <div class="shrink-0 w-12 h-12 rounded-full flex items-center justify-center"
                                 style="background:rgba(197,165,90,0.12)">
                                <x-icon :name="$deviceIcon" :size="22" class="text-[#C5A55A]" />
                            </div>

                            {{-- Meta --}}
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="font-semibold text-white text-sm">
                                        {{ $session['browser'] }} on {{ $session['os'] }}
                                    </span>
                                    <span class="text-xs text-gray-500">·</span>
                                    <span class="text-xs text-gray-500">{{ $session['device'] }}</span>
                                    @if($isCurrent)
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider"
                                              style="background:rgba(34,197,94,0.15);color:#22c55e">
                                            <span class="w-1.5 h-1.5 rounded-full bg-green-500 animate-pulse"></span>
                                            Current
                                        </span>
                                    @endif
                                </div>
                                <div class="mt-1 text-xs text-gray-500 flex flex-wrap items-center gap-x-3 gap-y-1">
                                    <span class="inline-flex items-center gap-1">
                                        <x-icon name="server" :size="12" />
                                        IP: {{ $session['ip'] ?? '—' }}
                                    </span>
                                    <span class="inline-flex items-center gap-1">
                                        <x-icon name="clock" :size="12" />
                                        Last active {{ $session['last_activity']->diffForHumans() }}
                                    </span>
                                </div>
                                @if($session['user_agent'])
                                    <p class="mt-2 text-[11px] text-gray-600 truncate" title="{{ $session['user_agent'] }}">
                                        {{ $session['user_agent'] }}
                                    </p>
                                @endif
                            </div>

                            {{-- Revoke action --}}
                            <div class="shrink-0">
                                @if($isCurrent)
                                    <span class="text-xs text-gray-600">Sesi ini</span>
                                @else
                                    <form method="POST" action="{{ route('profile.sessions.destroy', ['id' => $session['id']]) }}"
                                          onsubmit="return confirm('Cabut sesi ini? Device tersebut akan langsung logout.');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit"
                                                class="px-4 py-2 rounded-lg text-xs font-semibold text-red-400 border border-red-500/30 hover:bg-red-500/10 transition-colors">
                                            Revoke
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- Bulk logout --}}
                @if($sessions->count() > 1)
                    <div class="mt-8 rounded-xl p-6" style="background:#1a1a1a;border:1px solid rgba(220,38,38,0.25)">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                            <div>
                                <h3 class="font-heading font-semibold text-white">Logout dari semua device lain</h3>
                                <p class="text-xs text-gray-500 mt-1">
                                    Mencabut {{ $sessions->count() - ($sessions->where('is_current', true)->count()) }} sesi lain. Sesi aktif Anda saat ini tetap login.
                                </p>
                            </div>
                            <button type="button" @click="confirmAll = true"
                                    class="px-5 py-2.5 rounded-lg text-sm font-semibold text-white shadow-lg whitespace-nowrap"
                                    style="background:linear-gradient(135deg,#dc2626,#b91c1c)">
                                Logout from all other devices
                            </button>
                        </div>
                    </div>
                @endif
            @endif

            {{-- ───────────────────────────────────────────────────────────────
                 Trusted Devices — backed by `known_devices`. Populated by
                 LoginAlertService on every successful login. "Mark trusted"
                 silences future new-device alerts; "Forget" removes the row
                 so the next login from that fingerprint re-alerts.
                 ────────────────────────────────────────────────────────── --}}
            @if(isset($knownDevices) && $knownDevices->isNotEmpty())
                <div class="mt-12 mb-4">
                    <h2 class="font-heading text-2xl font-bold text-white">Trusted Devices</h2>
                    <p class="text-gray-400 text-sm mt-2 max-w-2xl">
                        Device yang pernah login ke akun Anda. Tandai sebagai trusted untuk menonaktifkan notifikasi keamanan untuk device tersebut, atau hapus agar login berikutnya memicu peringatan baru.
                    </p>
                </div>
                <div class="space-y-3">
                    @foreach($knownDevices as $device)
                        <div class="rounded-xl p-5 flex flex-col sm:flex-row gap-4 sm:items-center transition-colors"
                             style="background:#1a1a1a;border:1px solid {{ $device->trusted ? 'rgba(197,165,90,0.4)' : '#2a2a2a' }}">
                            <div class="shrink-0 w-12 h-12 rounded-full flex items-center justify-center"
                                 style="background:{{ $device->trusted ? 'rgba(197,165,90,0.18)' : 'rgba(255,255,255,0.05)' }}">
                                <x-icon name="server" :size="22" class="{{ $device->trusted ? 'text-[#C5A55A]' : 'text-gray-500' }}" />
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="font-semibold text-white text-sm">{{ $device->display_name }}</span>
                                    @if($device->trusted)
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider"
                                              style="background:rgba(197,165,90,0.15);color:#C5A55A">
                                            Trusted
                                        </span>
                                    @endif
                                </div>
                                <div class="mt-1 text-xs text-gray-500 flex flex-wrap items-center gap-x-3 gap-y-1">
                                    <span class="inline-flex items-center gap-1">
                                        <x-icon name="server" :size="12" />
                                        IP: {{ $device->ip ?: '—' }}
                                    </span>
                                    @if($device->country)
                                        <span class="inline-flex items-center gap-1">{{ $device->country }}</span>
                                    @endif
                                    @if($device->last_seen_at)
                                        <span class="inline-flex items-center gap-1">
                                            <x-icon name="clock" :size="12" />
                                            Last seen {{ $device->last_seen_at->diffForHumans() }}
                                        </span>
                                    @endif
                                    @if($device->first_seen_at)
                                        <span class="text-gray-600">· first seen {{ $device->first_seen_at->diffForHumans() }}</span>
                                    @endif
                                </div>
                            </div>
                            <div class="shrink-0 flex items-center gap-2">
                                @if(! $device->trusted)
                                    <form method="POST" action="{{ route('profile.devices.trust', ['device' => $device->id]) }}">
                                        @csrf
                                        <button type="submit"
                                                class="px-3 py-2 rounded-lg text-xs font-semibold border transition-colors"
                                                style="border-color:rgba(197,165,90,0.4);color:#C5A55A;background:rgba(197,165,90,0.05)">
                                            Mark trusted
                                        </button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('profile.devices.forget', ['device' => $device->id]) }}"
                                      onsubmit="return confirm('Lupakan device ini? Login berikutnya dari device tersebut akan kembali memicu notifikasi keamanan.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit"
                                            class="px-3 py-2 rounded-lg text-xs font-semibold text-gray-400 border border-gray-700 hover:bg-gray-800 transition-colors">
                                        Forget
                                    </button>
                                </form>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Confirm modal --}}
            <div x-cloak x-show="confirmAll"
                 x-transition.opacity
                 class="fixed inset-0 z-[9998] bg-black/80 backdrop-blur-sm flex items-center justify-center p-4"
                 @keydown.escape.window="confirmAll = false">
                <div @click.away="confirmAll = false"
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="opacity-0 scale-95"
                     x-transition:enter-end="opacity-100 scale-100"
                     class="rounded-2xl max-w-md w-full p-6 shadow-2xl"
                     style="background:linear-gradient(180deg,#1a1a1a 0%,#141414 100%);border:1px solid rgba(220,38,38,0.4)">
                    <div class="flex items-start gap-4">
                        <div class="shrink-0 w-12 h-12 rounded-full flex items-center justify-center" style="background:rgba(220,38,38,0.15)">
                            <x-icon name="logout" :size="24" class="text-red-400" />
                        </div>
                        <div class="flex-1">
                            <h3 class="font-heading text-lg font-bold text-white">Logout from all other devices?</h3>
                            <p class="text-sm text-gray-400 mt-2">
                                Setiap device lain akan langsung logout dan harus login ulang. Sesi aktif Anda di device ini akan tetap berjalan.
                            </p>
                        </div>
                    </div>
                    <div class="mt-6 flex gap-3 justify-end">
                        <button type="button" @click="confirmAll = false"
                                class="px-4 py-2 rounded-lg text-sm font-semibold text-gray-300 border border-gray-700 hover:bg-gray-800 transition-colors">
                            Batal
                        </button>
                        <form method="POST" action="{{ route('profile.sessions.destroyAll') }}">
                            @csrf
                            <button type="submit"
                                    class="px-5 py-2 rounded-lg text-sm font-semibold text-white"
                                    style="background:linear-gradient(135deg,#dc2626,#b91c1c)">
                                Ya, logout semua
                            </button>
                        </form>
                    </div>
                </div>
            </div>

        </div>
    </div>
</x-layout>
