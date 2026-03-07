<x-layout>
    <div class="min-h-screen bg-black pt-20 pb-16">
        <div class="container mx-auto px-4 md:px-16">

            <h1 class="font-heading text-2xl md:text-4xl font-bold mb-8" style="color:#C5A55A">🎮 Rewards Center</h1>

            <!-- Top Stats Bar -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
                <div class="p-4 rounded-xl text-center" style="background:#1a1a1a;border:1px solid #2a2a2a">
                    <div class="text-2xl font-bold font-heading" style="color:#C5A55A">Lv.{{ $level->level }}</div>
                    <div class="text-xs text-gray-500 mt-1">Level</div>
                </div>
                <div class="p-4 rounded-xl text-center" style="background:#1a1a1a;border:1px solid #2a2a2a">
                    <div class="text-2xl font-bold font-heading text-yellow-400">🪙 {{ number_format($coinBalance) }}</div>
                    <div class="text-xs text-gray-500 mt-1">Coins</div>
                </div>
                <div class="p-4 rounded-xl text-center" style="background:#1a1a1a;border:1px solid #2a2a2a">
                    <div class="text-2xl font-bold font-heading text-orange-400">🔥 {{ $level->watch_streak }}</div>
                    <div class="text-xs text-gray-500 mt-1">Day Streak</div>
                </div>
                <div class="p-4 rounded-xl text-center" style="background:#1a1a1a;border:1px solid #2a2a2a">
                    <div class="text-2xl font-bold font-heading text-purple-400">{{ count($unlockedIds) }}/{{ $allAchievements->count() }}</div>
                    <div class="text-xs text-gray-500 mt-1">Achievements</div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- Left: Daily Check-in + Achievements -->
                <div class="lg:col-span-2 space-y-8">

                    <!-- Daily Check-in -->
                    <div class="rounded-2xl overflow-hidden" style="background:linear-gradient(135deg,#1a1a1a 0%,#1f1a10 100%);border:1px solid #2a2520">
                        <div class="p-6">
                            <h2 class="font-heading text-lg font-bold text-white mb-4">📅 Daily Check-in</h2>
                            <div class="grid grid-cols-7 gap-2 mb-4">
                                @foreach($rewards as $day => $coins)
                                <div class="text-center p-3 rounded-xl transition-all {{ $day < $streakDay ? 'opacity-50' : ($day == $streakDay ? 'ring-2' : '') }}"
                                     style="background:{{ $day <= $level->watch_streak ? 'rgba(197,165,90,0.15)' : '#252525' }};{{ $day == $streakDay ? 'ring-color:#C5A55A' : '' }}">
                                    <div class="text-xs text-gray-500 mb-1">Day {{ $day }}</div>
                                    <div class="text-lg mb-1">{{ $day == 7 ? '🎁' : '🪙' }}</div>
                                    <div class="text-xs font-bold {{ $day <= $level->watch_streak ? '' : 'text-gray-400' }}" style="{{ $day <= $level->watch_streak ? 'color:#C5A55A' : '' }}">{{ $coins }}</div>
                                    @if($day <= $level->watch_streak && $day < $streakDay)
                                    <div class="text-xs text-green-500 mt-1">✓</div>
                                    @endif
                                </div>
                                @endforeach
                            </div>

                            @if($claimedToday)
                                <button disabled class="w-full py-3 rounded-xl text-sm font-semibold text-gray-500" style="background:#252525">
                                    ✓ Sudah Klaim Hari Ini — Kembali Besok!
                                </button>
                            @else
                                <form method="POST" action="{{ route('rewards.claimDaily') }}">
                                    @csrf
                                    <button type="submit" class="w-full py-3 rounded-xl text-sm font-bold text-black transition-all hover:scale-[1.02]" style="background:linear-gradient(90deg,#C5A55A,#E8D5A3)">
                                        🎁 Klaim Reward Day {{ $streakDay }} (+{{ $rewards[min($streakDay, 7)] }} coins)
                                    </button>
                                </form>
                            @endif
                        </div>
                    </div>

                    <!-- Achievements -->
                    <div class="rounded-2xl overflow-hidden" style="background:#1a1a1a;border:1px solid #2a2a2a">
                        <div class="p-6">
                            <h2 class="font-heading text-lg font-bold text-white mb-4">🏆 Achievements</h2>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                @foreach($allAchievements as $ach)
                                @php $unlocked = in_array($ach->id, $unlockedIds); @endphp
                                <div class="flex items-center gap-3 p-3 rounded-lg {{ $unlocked ? '' : 'opacity-40' }}" style="background:#252525;border:1px solid {{ $unlocked ? $ach->tier_color . '44' : '#333' }}">
                                    <div class="w-10 h-10 rounded-lg flex items-center justify-center text-xl" style="background:{{ $unlocked ? $ach->tier_color . '22' : '#333' }}">
                                        {{ $ach->icon }}
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="text-sm font-semibold {{ $unlocked ? 'text-white' : 'text-gray-500' }}">{{ $ach->name }}</div>
                                        <div class="text-xs {{ $unlocked ? 'text-gray-400' : 'text-gray-600' }}">{{ $ach->description }}</div>
                                    </div>
                                    <div class="text-right flex-shrink-0">
                                        <div class="text-xs font-bold" style="color:{{ $ach->tier_color }}">{{ ucfirst($ach->tier) }}</div>
                                        <div class="text-xs text-gray-600">🪙 {{ $ach->coin_reward }}</div>
                                    </div>
                                </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right: Leaderboard -->
                <div>
                    <div class="rounded-2xl overflow-hidden sticky top-24" style="background:#1a1a1a;border:1px solid #2a2a2a">
                        <div class="p-5" style="border-bottom:1px solid #2a2a2a">
                            <h2 class="font-heading text-lg font-bold text-white">🏅 Leaderboard</h2>
                        </div>
                        <div class="p-4 space-y-2">
                            @foreach($leaderboard as $idx => $entry)
                            <div class="flex items-center gap-3 p-3 rounded-lg {{ $entry->user_id === auth()->id() ? '' : '' }}" style="background:{{ $entry->user_id === auth()->id() ? 'rgba(197,165,90,0.1)' : '#252525' }};{{ $entry->user_id === auth()->id() ? 'border:1px solid rgba(197,165,90,0.3)' : '' }}">
                                <div class="w-7 text-center font-bold text-sm {{ $idx < 3 ? '' : 'text-gray-500' }}" style="{{ $idx < 3 ? 'color:#C5A55A' : '' }}">
                                    @if($idx === 0) 🥇
                                    @elseif($idx === 1) 🥈
                                    @elseif($idx === 2) 🥉
                                    @else {{ $idx + 1 }}
                                    @endif
                                </div>
                                <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold text-black" style="background:linear-gradient(135deg,#C5A55A,#E8D5A3)">
                                    {{ strtoupper(substr($entry->user->name ?? '?', 0, 1)) }}
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-medium text-white truncate">{{ $entry->user->name ?? 'Unknown' }}</div>
                                    <div class="text-xs text-gray-500">Level {{ $entry->level }}</div>
                                </div>
                                <div class="text-xs font-bold" style="color:#C5A55A">{{ number_format($entry->xp) }} XP</div>
                            </div>
                            @endforeach

                            @if($leaderboard->isEmpty())
                            <p class="text-center text-gray-600 py-8 text-sm">Belum ada data</p>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</x-layout>
