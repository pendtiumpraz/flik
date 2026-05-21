<x-admin.layout title="A/B Tests">
    <div class="space-y-6">
        <header class="flex items-center justify-between">
            <div>
                <h1 class="text-3xl font-bold" style="color:#C5A55A">A/B Experiments</h1>
                <p class="text-sm text-gray-400 mt-1">Sticky-assignment framework with conversion tracking.</p>
            </div>
            <a href="{{ route('admin.ab-tests.create') }}"
               class="px-4 py-2 rounded-md bg-[#C5A55A] text-black font-semibold hover:bg-[#d4b76a] transition">
                + New Experiment
            </a>
        </header>

        @if (session('success'))
            <div class="rounded-md border border-emerald-500/40 bg-emerald-500/10 px-4 py-2 text-emerald-300 text-sm">
                {{ session('success') }}
            </div>
        @endif

        <div class="rounded-lg border border-white/10 bg-zinc-900/40 overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-zinc-900/80 text-gray-300">
                    <tr>
                        <th class="text-left p-3">Name</th>
                        <th class="text-left p-3">Status</th>
                        <th class="text-left p-3">Variants</th>
                        <th class="text-left p-3">Started</th>
                        <th class="p-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    @forelse ($experiments as $exp)
                        <tr class="hover:bg-white/5">
                            <td class="p-3">
                                <a href="{{ route('admin.ab-tests.show', $exp) }}" class="font-medium text-white hover:text-[#C5A55A]">{{ $exp->name }}</a>
                                <div class="text-xs text-gray-500">{{ $exp->slug }}</div>
                            </td>
                            <td class="p-3">
                                <span class="px-2 py-0.5 rounded text-xs uppercase tracking-wide
                                    @if (in_array($exp->status, [\App\Models\AbExperiment::STATUS_RUNNING, \App\Models\AbExperiment::STATUS_ACTIVE], true)) bg-emerald-500/20 text-emerald-300
                                    @elseif ($exp->status === \App\Models\AbExperiment::STATUS_PAUSED) bg-yellow-500/20 text-yellow-300
                                    @elseif ($exp->status === \App\Models\AbExperiment::STATUS_COMPLETED) bg-zinc-500/20 text-gray-300
                                    @else bg-blue-500/20 text-blue-300 @endif">
                                    {{ $exp->status }}
                                </span>
                            </td>
                            <td class="p-3 text-gray-400">{{ count($exp->variantKeys()) }}</td>
                            <td class="p-3 text-gray-400">{{ $exp->started_at?->diffForHumans() ?? '—' }}</td>
                            <td class="p-3 text-right">
                                <a href="{{ route('admin.ab-tests.show', $exp) }}" class="text-[#C5A55A] hover:underline">Open</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="p-8 text-center text-gray-500">No experiments yet. Create one to get started.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $experiments->links() }}
    </div>
</x-admin.layout>
