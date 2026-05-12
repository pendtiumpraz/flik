<x-admin.layout title="Engagement Funnel">
    <div class="space-y-6">
        <header class="flex items-end justify-between gap-4 flex-wrap">
            <div>
                <h1 class="text-3xl font-bold" style="color:#C5A55A">Engagement Funnel</h1>
                <p class="text-sm text-gray-400 mt-1">Visitor → Subscriber conversion (last {{ $days }} days).</p>
            </div>
            <div class="flex items-center gap-2 text-sm">
                <span class="text-gray-400 mr-1">Window:</span>
                @foreach ($windowOptions as $opt)
                    <a href="?days={{ $opt }}"
                       class="px-3 py-1 rounded-md border transition {{ $days === $opt ? 'bg-[#C5A55A] text-black border-[#C5A55A]' : 'border-white/15 text-gray-300 hover:border-[#C5A55A]/60' }}">
                        {{ $opt }}d
                    </a>
                @endforeach
            </div>
        </header>

        @if (!empty($alerts))
            <div class="rounded-lg border border-red-500/40 bg-red-500/10 p-4 space-y-2">
                <p class="font-semibold text-red-300">⚠ Major drop-offs detected</p>
                <ul class="text-sm text-red-200 list-disc list-inside space-y-1">
                    @foreach ($alerts as $a)
                        <li><strong>{{ $a['label'] }}</strong> — {{ $a['drop'] }}% drop from <em>{{ $a['from'] }}</em> ({{ $a['from_count'] }} → {{ $a['to_count'] }})</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="space-y-3">
            @foreach ($funnel as $i => $row)
                @php
                    $pct = $topCount > 0 ? round(($row['count'] / max($topCount, 1)) * 100, 1) : 0;
                    $widthClass = max(10, $pct);
                @endphp
                <div class="rounded-lg border border-white/10 bg-zinc-900/40 p-4 hover:border-[#C5A55A]/50 transition">
                    <div class="flex items-center justify-between mb-2">
                        <span class="font-semibold text-white">{{ $i + 1 }}. {{ $row['label'] ?? $row['stage'] }}</span>
                        <span class="text-sm text-gray-300">
                            <strong class="text-[#C5A55A]">{{ number_format($row['count']) }}</strong>
                            @if ($i > 0)
                                <span class="text-gray-500">· {{ round($row['percent_from_previous'] ?? 0, 1) }}% step · {{ round($row['percent_from_top'] ?? $pct, 1) }}% top</span>
                            @endif
                        </span>
                    </div>
                    <div class="h-3 rounded-full bg-zinc-800 overflow-hidden">
                        <div class="h-full bg-gradient-to-r from-[#C5A55A] to-[#9b7e3a]"
                             style="width: {{ $widthClass }}%"></div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="rounded-lg border border-white/10 bg-zinc-900/40 p-4 text-sm text-gray-400">
            Top stage: <strong class="text-white">{{ number_format($topCount) }}</strong> ·
            Bottom stage: <strong class="text-white">{{ number_format($bottomCount) }}</strong> ·
            Overall conversion: <strong class="text-[#C5A55A]">{{ $topCount > 0 ? round(($bottomCount / $topCount) * 100, 2) : 0 }}%</strong>
        </div>
    </div>
</x-admin.layout>
