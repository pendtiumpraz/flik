@php
    // Home-page daily-watch streak widget.
    //
    // The widget is intentionally self-contained — it queries WatchStreak
    // directly so the home controller doesn't need to know it exists. This
    // means dropping the component anywhere (home, /movies, profile) Just
    // Works without touching the underlying controller.
    use App\Models\WatchStreak;
    use App\Services\Gamification\StreakService;

    $user = auth()->user();
    $streak = $user ? WatchStreak::query()->where('user_id', $user->id)->first() : null;

    $today = StreakService::today();
    $current = (int) ($streak->current_streak ?? 0);
    $longest = (int) ($streak->longest_streak ?? 0);
    $freeze = (int) ($streak->freeze_credits ?? 0);
    $watchedToday = $streak !== null && $streak->last_watch_date !== null
        ? $streak->last_watch_date->isSameDay($today)
        : false;
    $canBuyFreeze = $user !== null
        && (int) ($user->coin_balance ?? 0) >= StreakService::FREEZE_COIN_COST;
@endphp

@auth
<div class="mb-8 md:mb-10 rounded-2xl overflow-hidden"
     style="background: linear-gradient(135deg, #1a1410 0%, #2a1f15 50%, #1a1410 100%); border: 1px solid rgba(197,165,90,0.25)">
    <div class="p-5 md:p-6 flex flex-col md:flex-row md:items-center gap-5 md:gap-6">
        {{-- Flame + counter --}}
        <div class="flex items-center gap-4">
            <div class="relative">
                <div class="text-5xl md:text-6xl @if($current > 0) animate-pulse @endif"
                     style="filter: drop-shadow(0 0 12px rgba(245,158,11,0.55))">
                    @if($current > 0) 🔥 @else 🌑 @endif
                </div>
            </div>
            <div>
                <div class="text-[10px] uppercase tracking-widest font-semibold" style="color:#C5A55A">Watch Streak</div>
                <div class="font-heading text-2xl md:text-3xl font-bold text-white">{{ $current }} <span class="text-sm font-normal text-gray-400">hari</span></div>
                @if($longest > 0 && $longest > $current)
                    <div class="text-[11px] text-gray-500 mt-0.5">Terbaikmu: <span class="text-[#C5A55A] font-semibold">{{ $longest }} hari</span></div>
                @endif
            </div>
        </div>

        {{-- Message + actions --}}
        <div class="flex-1 flex flex-col md:flex-row md:items-center gap-4 md:gap-6 md:ml-4">
            <div class="flex-1 min-w-0">
                @if($current === 0)
                    <p class="text-sm text-gray-300">Mulai streak hari ini — tonton apa saja selama 1 menit dan streak kamu naik!</p>
                @elseif($watchedToday)
                    <p class="text-sm text-emerald-300 font-medium">Streak aman untuk hari ini. Sampai jumpa besok! 🌟</p>
                @else
                    <p class="text-sm text-amber-300 font-medium">Jangan putus streak — tonton sesuatu hari ini!</p>
                @endif

                {{-- Freeze credit display --}}
                <div class="mt-2 flex flex-wrap items-center gap-3 text-[11px]">
                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full"
                          style="background: rgba(96,165,250,0.15); color: #93C5FD">
                        🧊 Freeze: <strong class="font-semibold">{{ $freeze }}</strong>
                    </span>
                    @if($freeze > 0)
                        <span class="text-gray-500">Skip-day kamu aman</span>
                    @endif
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                {{-- Buy freeze (only when affordable and not maxed at 7) --}}
                @if($freeze < 7)
                    <form action="{{ route('streak.freeze') }}" method="POST" class="inline">
                        @csrf
                        <button type="submit"
                                @if(!$canBuyFreeze) disabled @endif
                                class="px-3 py-2 rounded-lg text-xs font-semibold transition-all
                                       @if($canBuyFreeze) text-black hover:opacity-90 @else opacity-40 cursor-not-allowed text-gray-400 @endif"
                                style="background: @if($canBuyFreeze) linear-gradient(135deg, #93C5FD, #60A5FA) @else rgba(255,255,255,0.05) @endif">
                            🧊 Beli Freeze ({{ StreakService::FREEZE_COIN_COST }} coins)
                        </button>
                    </form>
                @endif

                <a href="{{ route('leaderboards.streaks') }}"
                   class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-semibold transition-colors"
                   style="background: rgba(197,165,90,0.15); color: #C5A55A; border: 1px solid rgba(197,165,90,0.3)">
                    <x-icon name="trophy" :size="13" />
                    Leaderboard
                </a>
            </div>
        </div>
    </div>

    {{-- Flash errors (e.g. insufficient coins on freeze purchase) --}}
    @if($errors->has('freeze'))
        <div class="px-5 md:px-6 pb-4 text-xs text-red-400">{{ $errors->first('freeze') }}</div>
    @endif
    @if(session('success') && str_contains((string) session('success'), 'reeze'))
        <div class="px-5 md:px-6 pb-4 text-xs text-emerald-400">{{ session('success') }}</div>
    @endif
</div>
@endauth
