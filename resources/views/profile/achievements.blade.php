<x-layout>
    <div class="min-h-screen bg-black pt-20 pb-16">
        <div class="container mx-auto px-4 md:px-16 max-w-6xl">

            {{-- Header --}}
            <div class="mb-8">
                <div class="flex items-center gap-3 mb-2">
                    <a href="{{ route('profile.show') }}" class="text-xs text-gray-500 hover:text-[#C5A55A] transition-colors">← Profile</a>
                </div>
                <h1 class="font-heading text-2xl md:text-3xl font-bold text-white">Achievement Showcase</h1>
                <p class="text-sm text-gray-400 mt-1">
                    <span class="text-[#C5A55A] font-semibold">{{ $unlockedCount }}</span> dari
                    <span class="text-[#C5A55A] font-semibold">{{ $totalCount }}</span> achievement terbuka
                </p>

                {{-- Progress bar --}}
                <div class="mt-4 max-w-md">
                    @php $pct = $totalCount > 0 ? min(100, (int) round($unlockedCount / $totalCount * 100)) : 0; @endphp
                    <div class="flex justify-between text-[10px] text-gray-500 mb-1 uppercase tracking-wider">
                        <span>Progress</span>
                        <span>{{ $pct }}%</span>
                    </div>
                    <div class="w-full h-2 rounded-full bg-gray-800 overflow-hidden">
                        <div class="h-full rounded-full transition-all" style="width: {{ $pct }}%; background: linear-gradient(90deg, #C5A55A, #E8D5A3);"></div>
                    </div>
                </div>
            </div>

            {{-- Category filter (only when achievements have a category column) --}}
            @if($hasCategoryColumn && $categories->isNotEmpty())
            <div class="mb-6 flex flex-wrap gap-2">
                <a href="{{ route('profile.achievements') }}"
                   class="px-3 py-1.5 rounded-full text-xs font-semibold transition-colors
                          @if($activeCategory === '') text-black @else text-gray-300 hover:text-[#C5A55A] @endif"
                   style="@if($activeCategory === '') background: linear-gradient(135deg, #C5A55A, #E8D5A3); @else background: rgba(255,255,255,0.03); border: 1px solid rgba(197,165,90,0.2); @endif">
                    All
                </a>
                @foreach($categories as $cat)
                    <a href="{{ route('profile.achievements', ['category' => $cat]) }}"
                       class="px-3 py-1.5 rounded-full text-xs font-semibold transition-colors
                              @if($activeCategory === $cat) text-black @else text-gray-300 hover:text-[#C5A55A] @endif"
                       style="@if($activeCategory === $cat) background: linear-gradient(135deg, #C5A55A, #E8D5A3); @else background: rgba(255,255,255,0.03); border: 1px solid rgba(197,165,90,0.2); @endif">
                        {{ ucfirst($cat) }}
                    </a>
                @endforeach
            </div>
            @endif

            {{-- Grid --}}
            @if($achievements->isEmpty())
                <div class="text-center py-20 rounded-xl" style="background: rgba(20,18,16,0.5); border: 1px solid rgba(197,165,90,0.15)">
                    <p class="text-sm text-gray-500">Belum ada achievement tersedia.</p>
                </div>
            @else
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3 md:gap-4">
                    @foreach($achievements as $achievement)
                        @php
                            $unlockedAt = $unlockedMap->get($achievement->id);
                            $isUnlocked = $unlockedAt !== null;
                        @endphp
                        <div class="group relative rounded-xl p-4 text-center transition-all hover:scale-[1.02]"
                             style="background: {{ $isUnlocked ? '#1a1410' : '#0d0d0d' }};
                                    border: 1px solid {{ $isUnlocked ? $achievement->tier_color . '55' : '#1f1f1f' }};
                                    {{ !$isUnlocked ? 'filter: grayscale(0.85) opacity(0.55);' : '' }}">

                            {{-- Tier corner badge --}}
                            <div class="absolute top-2 right-2 text-[9px] uppercase tracking-wider font-bold px-1.5 py-0.5 rounded"
                                 style="background: {{ $achievement->tier_color }}22; color: {{ $achievement->tier_color }}">
                                {{ $achievement->tier }}
                            </div>

                            {{-- Icon / lock --}}
                            <div class="text-4xl md:text-5xl mb-3 mt-2">
                                @if($isUnlocked)
                                    {{ $achievement->icon ?? '🏆' }}
                                @else
                                    🔒
                                @endif
                            </div>

                            <div class="text-xs md:text-sm font-semibold {{ $isUnlocked ? 'text-white' : 'text-gray-500' }}">
                                {{ $achievement->name }}
                            </div>
                            <div class="text-[10px] mt-1 leading-snug {{ $isUnlocked ? 'text-gray-400' : 'text-gray-600' }}">
                                {{ $achievement->description }}
                            </div>

                            {{-- Rewards --}}
                            @if(($achievement->xp_reward > 0 || $achievement->coin_reward > 0))
                                <div class="mt-2 flex justify-center gap-2 text-[10px]">
                                    @if($achievement->xp_reward > 0)
                                        <span class="text-[#C5A55A]">+{{ $achievement->xp_reward }} XP</span>
                                    @endif
                                    @if($achievement->coin_reward > 0)
                                        <span class="text-yellow-400">+{{ $achievement->coin_reward }} 🪙</span>
                                    @endif
                                </div>
                            @endif

                            {{-- Unlocked-at tooltip --}}
                            @if($isUnlocked)
                                <div class="absolute inset-x-2 bottom-2 text-[9px] text-gray-500 opacity-0 group-hover:opacity-100 transition-opacity">
                                    Earned {{ \Carbon\Carbon::parse($unlockedAt)->diffForHumans() }}
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-layout>
