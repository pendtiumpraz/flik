<x-admin.layout title="{{ $experiment->name }} — A/B">
    <div class="space-y-6">
        <header class="flex items-start justify-between gap-4 flex-wrap">
            <div>
                <a href="{{ route('admin.ab-tests.index') }}" class="text-sm text-gray-400 hover:text-[#C5A55A]">← All experiments</a>
                <h1 class="text-3xl font-bold mt-1" style="color:#C5A55A">{{ $experiment->name }}</h1>
                <p class="text-xs text-gray-500 mt-1">{{ $experiment->slug }} · status:
                    <span class="px-2 py-0.5 rounded text-xs uppercase
                        @if (in_array($experiment->status, [\App\Models\AbExperiment::STATUS_RUNNING, \App\Models\AbExperiment::STATUS_ACTIVE], true)) bg-emerald-500/20 text-emerald-300
                        @elseif ($experiment->status === \App\Models\AbExperiment::STATUS_PAUSED) bg-yellow-500/20 text-yellow-300
                        @elseif ($experiment->status === \App\Models\AbExperiment::STATUS_COMPLETED) bg-zinc-500/20 text-gray-300
                        @else bg-blue-500/20 text-blue-300 @endif">{{ $experiment->status }}</span>
                </p>
                @if ($experiment->hypothesis)
                    <p class="text-sm text-gray-300 mt-3 italic">"{{ $experiment->hypothesis }}"</p>
                @endif
            </div>
            <div class="flex gap-2 text-sm">
                @foreach ($actions as $action)
                    <form method="POST" action="{{ route('admin.ab-tests.act', [$experiment, $action]) }}">
                        @csrf
                        @if ($action === 'conclude')
                            <input name="winner_variant" placeholder="winner (optional)" class="px-2 py-1 mr-1 bg-zinc-900 border border-white/10 rounded text-white text-xs" />
                        @endif
                        <button class="px-3 py-1 rounded border border-white/15 text-gray-200 hover:border-[#C5A55A]/60 capitalize">{{ $action }}</button>
                    </form>
                @endforeach
            </div>
        </header>

        @if (session('success'))
            <div class="rounded-md border border-emerald-500/40 bg-emerald-500/10 px-4 py-2 text-emerald-300 text-sm">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="rounded-md border border-red-500/40 bg-red-500/10 px-4 py-2 text-red-300 text-sm">{{ session('error') }}</div>
        @endif

        @if ($leader)
            <div class="rounded-lg border border-emerald-500/30 bg-emerald-500/5 p-3 text-sm">
                <span class="text-emerald-300 font-semibold">▲ Current leader:</span>
                <span class="text-white">{{ $leader['variant'] }}</span> —
                {{-- conversion_rate is already a percentage (0..100) per AbTestFramework::report() — do NOT multiply by 100 again. --}}
                <span class="text-gray-300">{{ number_format($leader['conversion_rate'] ?? 0, 2) }}% conversion ({{ $leader['converted'] ?? $leader['conversions'] ?? 0 }} / {{ $leader['assigned'] ?? 0 }})</span>
            </div>
        @endif

        <div class="rounded-lg border border-white/10 bg-zinc-900/40 overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-zinc-900/80 text-gray-300">
                    <tr>
                        <th class="text-left p-3">Variant</th>
                        <th class="text-right p-3">Weight</th>
                        <th class="text-right p-3">Assigned</th>
                        <th class="text-right p-3">Conversions</th>
                        <th class="text-right p-3">Rate</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    @foreach ($report['variants'] ?? [] as $v)
                        <tr>
                            <td class="p-3 text-white font-medium">{{ $v['variant'] }}</td>
                            <td class="p-3 text-right text-gray-400">
                                @if (isset($v['weight']) && $v['weight'] !== null)
                                    {{ number_format(((float) $v['weight']) * 100, 1) }}%
                                @else
                                    —
                                @endif
                            </td>
                            <td class="p-3 text-right text-gray-300">{{ number_format($v['assigned'] ?? 0) }}</td>
                            <td class="p-3 text-right text-gray-300">{{ number_format($v['converted'] ?? $v['conversions'] ?? 0) }}</td>
                            {{-- conversion_rate is already a percentage — single-format, no x100. --}}
                            <td class="p-3 text-right font-semibold text-[#C5A55A]">{{ number_format($v['conversion_rate'] ?? 0, 2) }}%</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if (!empty($report['significance']))
            <div class="rounded-lg border border-white/10 bg-zinc-900/40 p-4 text-sm text-gray-300">
                <p class="font-semibold text-white mb-1">Statistical significance</p>
                <pre class="text-xs text-gray-400 whitespace-pre-wrap">{{ json_encode($report['significance'], JSON_PRETTY_PRINT) }}</pre>
            </div>
        @endif
    </div>
</x-admin.layout>
