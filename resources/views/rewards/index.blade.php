<x-layout>
    <div class="min-h-screen bg-black pt-20 pb-16">
        <div class="container mx-auto px-4 md:px-8 lg:px-16 max-w-7xl">

            <!-- Page Header -->
            <div class="flex items-center gap-3 mb-6 md:mb-8">
                <div class="flex items-center justify-center w-10 h-10 md:w-12 md:h-12 rounded-xl" style="background: linear-gradient(135deg, rgba(197,165,90,0.18), rgba(197,165,90,0.06)); border: 1px solid rgba(197,165,90,0.25)">
                    <x-icon name="trophy" :size="22" class="text-[#C5A55A]" />
                </div>
                <div>
                    <h1 class="font-heading text-xl md:text-3xl font-bold text-white">Rewards Center</h1>
                    <p class="text-xs md:text-sm text-gray-500 mt-0.5">Watch · Earn · Unlock</p>
                </div>
            </div>

            <!-- Top Stats Bar -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 md:gap-4 mb-6 md:mb-10">
                <!-- Level -->
                <div class="relative p-4 md:p-5 rounded-xl overflow-hidden" style="background: linear-gradient(135deg, #1a1a1a 0%, #161310 100%); border: 1px solid rgba(197,165,90,0.18)">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-[11px] uppercase tracking-wider text-gray-500 font-medium">Level</div>
                            <div class="text-2xl md:text-3xl font-bold font-heading text-white mt-1">{{ $level->level }}</div>
                        </div>
                        <x-icon name="crown" :size="22" class="text-[#C5A55A] opacity-70" />
                    </div>
                    @php $xpToNext = ($level->level * 100); $xpProgress = min(100, ($level->xp % 100)); @endphp
                    <div class="mt-3 h-1 bg-gray-800 rounded-full overflow-hidden">
                        <div class="h-full rounded-full" style="width: {{ $xpProgress }}%; background: linear-gradient(90deg, #C5A55A, #E8D5A3)"></div>
                    </div>
                    <div class="text-[10px] text-gray-600 mt-1">{{ $xpProgress }}/100 XP</div>
                </div>

                <!-- Coins -->
                <div class="p-4 md:p-5 rounded-xl" style="background: linear-gradient(135deg, #1a1a1a 0%, #161310 100%); border: 1px solid rgba(197,165,90,0.18)">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-[11px] uppercase tracking-wider text-gray-500 font-medium">Coins</div>
                            <div class="text-2xl md:text-3xl font-bold font-heading text-white mt-1">{{ number_format($coinBalance) }}</div>
                        </div>
                        <x-icon name="coin" :size="22" class="text-[#C5A55A] opacity-70" />
                    </div>
                    <a href="{{ route('plans.index') }}" class="inline-flex items-center gap-1 mt-3 text-[11px] text-[#C5A55A]/80 hover:text-[#C5A55A]">
                        Get more <x-icon name="chevron-right" :size="10" />
                    </a>
                </div>

                <!-- Streak -->
                <div class="p-4 md:p-5 rounded-xl" style="background: linear-gradient(135deg, #1a1a1a 0%, #161310 100%); border: 1px solid rgba(197,165,90,0.18)">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-[11px] uppercase tracking-wider text-gray-500 font-medium">Day Streak</div>
                            <div class="text-2xl md:text-3xl font-bold font-heading text-white mt-1">{{ $level->watch_streak }}</div>
                        </div>
                        <x-icon name="fire" :size="22" class="text-[#C5A55A] opacity-70" />
                    </div>
                    <div class="text-[10px] text-gray-600 mt-3">Keep it going</div>
                </div>

                <!-- Achievements -->
                <div class="p-4 md:p-5 rounded-xl" style="background: linear-gradient(135deg, #1a1a1a 0%, #161310 100%); border: 1px solid rgba(197,165,90,0.18)">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-[11px] uppercase tracking-wider text-gray-500 font-medium">Unlocked</div>
                            <div class="text-2xl md:text-3xl font-bold font-heading text-white mt-1">{{ count($unlockedIds) }}<span class="text-base text-gray-600">/{{ $allAchievements->count() }}</span></div>
                        </div>
                        <x-icon name="medal" :size="22" class="text-[#C5A55A] opacity-70" />
                    </div>
                    @php $achPercent = $allAchievements->count() > 0 ? round((count($unlockedIds) / $allAchievements->count()) * 100) : 0; @endphp
                    <div class="mt-3 h-1 bg-gray-800 rounded-full overflow-hidden">
                        <div class="h-full rounded-full" style="width: {{ $achPercent }}%; background: linear-gradient(90deg, #C5A55A, #E8D5A3)"></div>
                    </div>
                    <div class="text-[10px] text-gray-600 mt-1">{{ $achPercent }}% complete</div>
                </div>
            </div>

            <!-- Main Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 md:gap-8">

                <!-- Left Column -->
                <div class="lg:col-span-2 space-y-6 md:space-y-8">

                    <!-- Daily Check-in -->
                    <div class="rounded-2xl overflow-hidden" style="background: linear-gradient(180deg, #1a1a1a 0%, #141210 100%); border: 1px solid rgba(197,165,90,0.18)">
                        <div class="p-5 md:p-6">
                            <div class="flex items-center justify-between mb-5">
                                <div class="flex items-center gap-2.5">
                                    <x-icon name="calendar" :size="18" class="text-[#C5A55A]" />
                                    <h2 class="font-heading text-base md:text-lg font-bold text-white">Daily Check-in</h2>
                                </div>
                                <span class="text-[10px] uppercase tracking-wider text-gray-500">Day {{ $streakDay }}/7</span>
                            </div>

                            <!-- Day Calendar — 7 days -->
                            <div class="grid grid-cols-7 gap-1.5 md:gap-2 mb-5">
                                @foreach($rewards as $day => $coins)
                                @php
                                    $isPast = $day < $streakDay;
                                    $isToday = $day == $streakDay;
                                    $isFuture = $day > $streakDay;
                                @endphp
                                <div class="relative text-center p-2 md:p-3 rounded-lg transition-all"
                                     style="
                                        background: {{ $isPast ? 'rgba(197,165,90,0.10)' : ($isToday ? 'rgba(197,165,90,0.18)' : 'rgba(255,255,255,0.02)') }};
                                        border: 1px solid {{ $isToday ? 'rgba(197,165,90,0.5)' : ($isPast ? 'rgba(197,165,90,0.2)' : 'rgba(255,255,255,0.04)') }};
                                     ">
                                    <div class="text-[9px] md:text-[10px] uppercase tracking-wider text-gray-500">D{{ $day }}</div>
                                    <div class="my-1 md:my-1.5 flex justify-center">
                                        @if($day == 7)
                                            <x-icon name="gift" :size="16" class="{{ $isPast || $isToday ? 'text-[#C5A55A]' : 'text-gray-600' }}" />
                                        @else
                                            <x-icon name="coin" :size="14" class="{{ $isPast || $isToday ? 'text-[#C5A55A]' : 'text-gray-600' }}" />
                                        @endif
                                    </div>
                                    <div class="text-[10px] md:text-xs font-bold {{ $isPast || $isToday ? 'text-[#C5A55A]' : 'text-gray-600' }}">{{ $coins }}</div>
                                    @if($isPast)
                                        <div class="absolute top-1 right-1">
                                            <x-icon name="check" :size="10" class="text-[#C5A55A]" :stroke="3" />
                                        </div>
                                    @endif
                                </div>
                                @endforeach
                            </div>

                            @if($claimedToday)
                                <button disabled class="w-full py-3 rounded-lg text-sm font-medium text-gray-500 flex items-center justify-center gap-2" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05)">
                                    <x-icon name="check" :size="16" />
                                    Sudah Klaim Hari Ini — Kembali Besok
                                </button>
                            @else
                                <form method="POST" action="{{ route('rewards.claimDaily') }}">
                                    @csrf
                                    <button type="submit" class="w-full py-3 rounded-lg text-sm font-bold text-black transition-all hover:opacity-95 hover:scale-[1.005] flex items-center justify-center gap-2" style="background: linear-gradient(90deg, #C5A55A, #E8D5A3)">
                                        <x-icon name="gift" :size="16" />
                                        Klaim Reward Day {{ $streakDay }} <span class="opacity-75">(+{{ $rewards[min($streakDay, 7)] }} coins)</span>
                                    </button>
                                </form>
                            @endif
                        </div>
                    </div>

                    <!-- Achievements -->
                    <div class="rounded-2xl overflow-hidden" style="background: linear-gradient(180deg, #1a1a1a 0%, #141210 100%); border: 1px solid rgba(197,165,90,0.18)">
                        <div class="p-5 md:p-6">
                            <div class="flex items-center justify-between mb-5">
                                <div class="flex items-center gap-2.5">
                                    <x-icon name="medal" :size="18" class="text-[#C5A55A]" />
                                    <h2 class="font-heading text-base md:text-lg font-bold text-white">Achievements</h2>
                                </div>
                                <span class="text-[10px] uppercase tracking-wider text-gray-500">{{ count($unlockedIds) }}/{{ $allAchievements->count() }}</span>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                                @foreach($allAchievements as $ach)
                                @php $unlocked = in_array($ach->id, $unlockedIds); @endphp
                                <div class="flex items-center gap-3 p-3 rounded-lg transition-all {{ $unlocked ? '' : 'opacity-50' }}"
                                     style="background: {{ $unlocked ? 'rgba(197,165,90,0.06)' : 'rgba(255,255,255,0.02)' }}; border: 1px solid {{ $unlocked ? 'rgba(197,165,90,0.2)' : 'rgba(255,255,255,0.04)' }}">
                                    <!-- Tier badge dot -->
                                    <div class="w-9 h-9 md:w-10 md:h-10 rounded-lg flex items-center justify-center flex-shrink-0" style="background: {{ $unlocked ? 'rgba(197,165,90,0.15)' : 'rgba(255,255,255,0.04)' }}">
                                        <x-icon name="trophy" :size="16" class="{{ $unlocked ? 'text-[#C5A55A]' : 'text-gray-600' }}" />
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="text-sm font-semibold {{ $unlocked ? 'text-white' : 'text-gray-500' }} truncate">{{ $ach->name }}</div>
                                        <div class="text-[11px] {{ $unlocked ? 'text-gray-400' : 'text-gray-600' }} truncate">{{ $ach->description }}</div>
                                    </div>
                                    <div class="text-right flex-shrink-0">
                                        <div class="text-[10px] font-semibold uppercase tracking-wider text-[#C5A55A]/80">{{ ucfirst($ach->tier) }}</div>
                                        <div class="flex items-center gap-1 justify-end mt-0.5 text-[11px] font-bold text-[#C5A55A]">
                                            <x-icon name="coin" :size="10" />
                                            {{ $ach->coin_reward }}
                                        </div>
                                    </div>
                                </div>
                                @endforeach
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Right Column: Leaderboard -->
                <div>
                    <div class="rounded-2xl overflow-hidden lg:sticky lg:top-24" style="background: linear-gradient(180deg, #1a1a1a 0%, #141210 100%); border: 1px solid rgba(197,165,90,0.18)">
                        <div class="p-5 md:p-6 border-b" style="border-color: rgba(197,165,90,0.12)">
                            <div class="flex items-center gap-2.5">
                                <x-icon name="lightning" :size="18" class="text-[#C5A55A]" />
                                <h2 class="font-heading text-base md:text-lg font-bold text-white">Leaderboard</h2>
                            </div>
                            <p class="text-[11px] text-gray-500 mt-1">Top viewers this season</p>
                        </div>
                        <div class="p-3 md:p-4 space-y-1.5">
                            @forelse($leaderboard as $idx => $entry)
                            @php $isMe = $entry->user_id === auth()->id(); @endphp
                            <div class="flex items-center gap-3 p-2.5 md:p-3 rounded-lg transition-colors"
                                 style="background: {{ $isMe ? 'rgba(197,165,90,0.10)' : 'rgba(255,255,255,0.02)' }}; border: 1px solid {{ $isMe ? 'rgba(197,165,90,0.3)' : 'transparent' }}">
                                <!-- Rank -->
                                <div class="w-6 text-center font-bold text-sm flex-shrink-0">
                                    @if($idx === 0)
                                        <span class="text-[#C5A55A]">1</span>
                                    @elseif($idx === 1)
                                        <span style="color: #B8B8B8">2</span>
                                    @elseif($idx === 2)
                                        <span style="color: #CD7F32">3</span>
                                    @else
                                        <span class="text-gray-600">{{ $idx + 1 }}</span>
                                    @endif
                                </div>
                                <!-- Avatar -->
                                <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold text-black flex-shrink-0" style="background: linear-gradient(135deg, #C5A55A, #E8D5A3)">
                                    {{ strtoupper(substr($entry->user->name ?? '?', 0, 1)) }}
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-medium text-white truncate">{{ $entry->user->name ?? 'Unknown' }}{{ $isMe ? ' (you)' : '' }}</div>
                                    <div class="text-[11px] text-gray-500">Level {{ $entry->level }}</div>
                                </div>
                                <div class="text-[11px] font-bold text-[#C5A55A] flex-shrink-0">{{ number_format($entry->xp) }} XP</div>
                            </div>
                            @empty
                            <p class="text-center text-gray-600 py-8 text-sm">Belum ada data</p>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</x-layout>
