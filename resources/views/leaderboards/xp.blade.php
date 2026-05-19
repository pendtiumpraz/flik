<x-layout>
    <div class="min-h-screen bg-black pt-20 pb-16">
        <div class="container mx-auto px-4 md:px-16 max-w-4xl">

            <div class="mb-6 flex flex-wrap gap-2">
                <a href="{{ route('leaderboards.streaks') }}"
                   class="px-4 py-2 rounded-lg text-sm font-semibold text-gray-300 hover:text-[#C5A55A] transition-colors"
                   style="background: rgba(255,255,255,0.03); border: 1px solid rgba(197,165,90,0.2)">🔥 Streaks</a>
                <a href="{{ route('leaderboards.xp') }}"
                   class="px-4 py-2 rounded-lg text-sm font-semibold text-black"
                   style="background: linear-gradient(135deg, #C5A55A, #E8D5A3)">⭐ XP</a>
                <a href="{{ route('leaderboards.watches') }}"
                   class="px-4 py-2 rounded-lg text-sm font-semibold text-gray-300 hover:text-[#C5A55A] transition-colors"
                   style="background: rgba(255,255,255,0.03); border: 1px solid rgba(197,165,90,0.2)">🎬 Watches</a>
            </div>

            <h1 class="font-heading text-2xl md:text-3xl font-bold text-white mb-2">XP Leaderboard</h1>
            <p class="text-sm text-gray-400 mb-6">Top 50 user dengan level + XP tertinggi.</p>

            @if($myRank !== null)
                <div class="mb-6 p-4 rounded-xl flex items-center gap-6"
                     style="background: linear-gradient(135deg, #1a1410, #2a1f15); border: 1px solid rgba(197,165,90,0.3)">
                    <div class="text-3xl">⭐</div>
                    <div class="flex-1">
                        <div class="text-[10px] uppercase tracking-widest font-semibold" style="color:#C5A55A">Your Rank</div>
                        <div class="font-heading text-xl font-bold text-white">
                            #{{ $myRank }}
                            <span class="text-sm font-normal text-gray-400 ml-2">
                                — Level {{ $myLevel->level ?? 1 }} ({{ $myLevel->xp ?? 0 }} XP)
                            </span>
                        </div>
                    </div>
                </div>
            @endif

            @if($top->isEmpty())
                <div class="text-center py-20 rounded-xl" style="background: rgba(20,18,16,0.5); border: 1px solid rgba(197,165,90,0.15)">
                    <p class="text-sm text-gray-500">Belum ada user yang naik level.</p>
                </div>
            @else
                <div class="rounded-xl overflow-hidden" style="background:#1a1a1a;border:1px solid #2a2a2a">
                    @foreach($top as $i => $row)
                        @php
                            $rank = $i + 1;
                            $isMe = auth()->id() === $row->user_id;
                            $rankBg = match(true) {
                                $rank === 1 => 'linear-gradient(135deg, #FFD700, #FFA500)',
                                $rank === 2 => 'linear-gradient(135deg, #C0C0C0, #808080)',
                                $rank === 3 => 'linear-gradient(135deg, #CD7F32, #8B4513)',
                                default     => 'rgba(255,255,255,0.05)',
                            };
                            $rankColor = $rank <= 3 ? '#000' : '#888';
                        @endphp
                        <div class="flex items-center gap-4 px-4 py-3 {{ !$loop->last ? 'border-b border-gray-800/50' : '' }} {{ $isMe ? 'bg-[#C5A55A]/5' : '' }}">
                            <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold flex-shrink-0" style="background: {{ $rankBg }}; color: {{ $rankColor }}">{{ $rank }}</div>
                            @if($row->user && $row->user->avatar_url)
                                <img src="{{ $row->user->avatar_url }}" alt="" class="w-10 h-10 rounded-full object-cover flex-shrink-0" style="background:#333">
                            @else
                                <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold text-black flex-shrink-0" style="background: linear-gradient(135deg, #C5A55A, #E8D5A3)">
                                    {{ strtoupper(substr($row->user->name ?? '?', 0, 1)) }}
                                </div>
                            @endif
                            <div class="flex-1 min-w-0">
                                <div class="text-sm font-semibold text-white truncate">
                                    {{ $row->user->name ?? 'Anonymous' }}
                                    @if($isMe)<span class="ml-2 text-[10px] text-[#C5A55A]">YOU</span>@endif
                                </div>
                                @if($row->user && $row->user->username)
                                    <div class="text-[11px] text-gray-500 truncate">@{{ $row->user->username }}</div>
                                @endif
                            </div>
                            <div class="text-right">
                                <div class="text-lg font-bold text-white">⭐ Lv. {{ $row->level }}</div>
                                <div class="text-[10px] text-gray-500">{{ number_format($row->xp) }} XP</div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-layout>
