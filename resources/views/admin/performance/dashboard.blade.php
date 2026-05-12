<x-admin.layout title="Performance Dashboard">

    @php
        /**
         * Health colour helpers — keep all colour mapping in one place so the
         * view stays declarative.
         *
         * @var array{
         *   window_hours:int, since:string,
         *   totals:array{calls:int, errors:int, cost_usd:float, avg_latency_ms:float|null, p95_latency_ms:int|null, error_rate_pct:float, health:string},
         *   per_task:list<array{task_type:string, calls:int, errors:int, error_rate_pct:float, avg_latency_ms:int|null, p95_latency_ms:int|null, cost_usd:float, health:string}>
         * } $ai
         */
        $healthBg = [
            'green'  => 'rgba(34,197,94,0.18)',
            'yellow' => 'rgba(234,179,8,0.18)',
            'red'    => 'rgba(220,38,38,0.18)',
        ];
        $healthFg = [
            'green'  => '#22c55e',
            'yellow' => '#eab308',
            'red'    => '#ef4444',
        ];
        $healthLabel = [
            'green'  => 'Healthy',
            'yellow' => 'Warn',
            'red'    => 'Critical',
        ];

        $cardStyle    = 'background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:20px 24px;margin-bottom:24px';
        $sectionH3    = 'font-size:15px;font-weight:600;margin-bottom:12px';
        $subtle       = 'font-size:11px;color:#666';
        $totals       = $ai['totals'];
        $queueMaxBar  = max(1, max(array_column($queue['per_queue'], 'pending')));
    @endphp

    {{-- ─── Header + Refresh button ─────────────────────────────── --}}
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px">
        <div>
            <h2 style="font-size:22px;font-weight:600">System Performance</h2>
            <div style="{{ $subtle }};margin-top:4px">
                Window: last {{ $windowHours }}h
                · Snapshot generated {{ \Carbon\Carbon::parse($generatedAt)->diffForHumans() }}
                · Cached 5 min
            </div>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <form method="GET" action="{{ route('admin.performance.index') }}" style="display:flex;gap:8px;align-items:center">
                <label for="hours" style="font-size:12px;color:#777">Window</label>
                <select name="hours" id="hours" class="form-input" style="width:auto;padding:6px 10px" onchange="this.form.submit()">
                    @foreach([1,6,24,72,168,720] as $h)
                        <option value="{{ $h }}" @selected($windowHours === $h)>
                            {{ $h < 24 ? $h . 'h' : ($h / 24) . 'd' }}
                        </option>
                    @endforeach
                </select>
            </form>
            <a href="{{ route('admin.performance.index', ['refresh' => 1, 'hours' => $windowHours]) }}" class="btn btn-gold">
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                Refresh
            </a>
        </div>
    </div>

    {{-- ─── KPI summary ─────────────────────────────────────────── --}}
    <div class="grid-stats" style="margin-bottom:24px">
        <div class="stat-card">
            <div class="label">AI calls ({{ $windowHours }}h)</div>
            <div class="value">{{ number_format($totals['calls']) }}</div>
            <div style="{{ $subtle }};margin-top:4px">
                {{ $totals['errors'] }} errors · {{ $totals['error_rate_pct'] }}% error rate
            </div>
        </div>

        <div class="stat-card">
            <div class="label">Avg / p95 latency</div>
            <div class="value" style="font-size:24px">
                {{ $totals['avg_latency_ms'] !== null ? number_format($totals['avg_latency_ms']) . 'ms' : '—' }}
                <span style="font-size:14px;color:#777"> / {{ $totals['p95_latency_ms'] !== null ? number_format($totals['p95_latency_ms']) . 'ms' : '—' }}</span>
            </div>
            <div style="margin-top:6px">
                <span class="badge" style="background:{{ $healthBg[$totals['health']] }};color:{{ $healthFg[$totals['health']] }}">
                    {{ $healthLabel[$totals['health']] }}
                </span>
            </div>
        </div>

        <div class="stat-card">
            <div class="label">AI cost ({{ $windowHours }}h)</div>
            <div class="value">${{ number_format($totals['cost_usd'], 4) }}</div>
            <div style="{{ $subtle }};margin-top:4px">Aggregated across all tasks</div>
        </div>

        <div class="stat-card">
            <div class="label">Queue health</div>
            <div class="value" style="font-size:24px">
                {{ number_format($queue['pending_total']) }}
                <span style="font-size:13px;color:#777">pending</span>
            </div>
            <div style="{{ $subtle }};margin-top:4px">
                {{ $queue['failed_total'] }} failed total · {{ $queue['failed_24h'] }} last 24h
            </div>
        </div>
    </div>

    {{-- ─── AI Service Latency table ─────────────────────────────── --}}
    <div style="{{ $cardStyle }}">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
            <h3 style="{{ $sectionH3 }};margin:0">AI Service Latency (per task)</h3>
            <span style="{{ $subtle }}">window: {{ $windowHours }}h</span>
        </div>

        @if (count($ai['per_task']) === 0)
            <div style="color:#777;font-size:13px;padding:12px 0">No AI calls recorded in this window.</div>
        @else
            <div style="overflow-x:auto">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Task</th>
                            <th style="text-align:right">Calls</th>
                            <th style="text-align:right">Avg latency</th>
                            <th style="text-align:right">p95 latency</th>
                            <th style="text-align:right">Errors</th>
                            <th style="text-align:right">Error rate</th>
                            <th style="text-align:right">Cost</th>
                            <th>Health</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($ai['per_task'] as $task)
                            <tr>
                                <td style="font-family:'JetBrains Mono','Fira Code',monospace;font-size:12px;color:#C5A55A">{{ $task['task_type'] }}</td>
                                <td style="text-align:right">{{ number_format($task['calls']) }}</td>
                                <td style="text-align:right">{{ $task['avg_latency_ms'] !== null ? number_format($task['avg_latency_ms']) . 'ms' : '—' }}</td>
                                <td style="text-align:right">{{ $task['p95_latency_ms'] !== null ? number_format($task['p95_latency_ms']) . 'ms' : '—' }}</td>
                                <td style="text-align:right">{{ $task['errors'] }}</td>
                                <td style="text-align:right">{{ $task['error_rate_pct'] }}%</td>
                                <td style="text-align:right">${{ number_format($task['cost_usd'], 4) }}</td>
                                <td>
                                    <span class="badge" style="background:{{ $healthBg[$task['health']] }};color:{{ $healthFg[$task['health']] }}">
                                        {{ $healthLabel[$task['health']] }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- ─── Queue Lag gauges + failures ─────────────────────────── --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:20px;margin-bottom:24px">
        <div style="{{ $cardStyle }};margin-bottom:0">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
                <h3 style="{{ $sectionH3 }};margin:0">Queue Lag</h3>
                <span style="{{ $subtle }}">driver: {{ $queue['driver'] }}</span>
            </div>

            @if (!empty($queue['note']))
                <div style="background:rgba(234,179,8,0.08);border:1px solid rgba(234,179,8,0.3);color:#eab308;padding:8px 12px;border-radius:8px;font-size:12px;margin-bottom:12px">
                    {{ $queue['note'] }}
                </div>
            @endif

            <div style="display:flex;flex-direction:column;gap:14px">
                @foreach ($queue['per_queue'] as $q)
                    @php
                        $pct = min(100, ($q['pending'] / $queueMaxBar) * 100);
                    @endphp
                    <div>
                        <div style="display:flex;justify-content:space-between;margin-bottom:6px">
                            <span style="font-size:13px;color:#ccc;font-weight:500">{{ $q['queue'] }}</span>
                            <span style="font-size:12px;color:{{ $healthFg[$q['health']] }};font-weight:600">
                                {{ number_format($q['pending']) }} pending
                            </span>
                        </div>
                        <div style="height:10px;background:#0f0f0f;border-radius:6px;overflow:hidden;border:1px solid #2a2a2a">
                            <div style="height:100%;width:{{ $pct }}%;background:{{ $healthFg[$q['health']] }};transition:width .3s"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div style="{{ $cardStyle }};margin-bottom:0">
            <h3 style="{{ $sectionH3 }}">Failed Jobs</h3>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                <div>
                    <div class="label">Failed (total)</div>
                    <div style="font-family:'Outfit';font-size:32px;font-weight:700;color:{{ $queue['failed_total'] > 0 ? '#ef4444' : '#22c55e' }};margin-top:4px">
                        {{ number_format($queue['failed_total']) }}
                    </div>
                </div>
                <div>
                    <div class="label">Failed (24h)</div>
                    <div style="font-family:'Outfit';font-size:32px;font-weight:700;color:{{ $queue['failed_24h'] > 0 ? '#eab308' : '#22c55e' }};margin-top:4px">
                        {{ number_format($queue['failed_24h']) }}
                    </div>
                </div>
            </div>
            <div style="{{ $subtle }};margin-top:14px;line-height:1.5">
                Inspect failures with <code style="background:#0f0f0f;padding:2px 6px;border-radius:4px;color:#C5A55A">php artisan queue:failed</code>.
                Re-queue with <code style="background:#0f0f0f;padding:2px 6px;border-radius:4px;color:#C5A55A">queue:retry all</code>.
            </div>
        </div>
    </div>

    {{-- ─── Cache stats + DB stats ──────────────────────────────── --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:20px;margin-bottom:24px">
        <div style="{{ $cardStyle }};margin-bottom:0">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
                <h3 style="{{ $sectionH3 }};margin:0">Cache</h3>
                <span class="badge" style="background:{{ $cacheStats['healthy'] ? $healthBg['green'] : $healthBg['red'] }};color:{{ $cacheStats['healthy'] ? $healthFg['green'] : $healthFg['red'] }}">
                    {{ $cacheStats['healthy'] ? 'Healthy' : 'Failing' }}
                </span>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px">
                <div>
                    <div class="label">Driver</div>
                    <div style="margin-top:4px;font-size:16px;color:#fff;font-weight:600">{{ $cacheStats['driver'] }}</div>
                </div>
                <div>
                    <div class="label">Avg roundtrip</div>
                    <div style="margin-top:4px;font-size:16px;color:#fff;font-weight:600">
                        {{ $cacheStats['avg_ms'] !== null ? number_format($cacheStats['avg_ms'], 2) . ' ms' : '—' }}
                    </div>
                </div>
                <div>
                    <div class="label">Min</div>
                    <div style="margin-top:4px;font-size:14px;color:#aaa">
                        {{ $cacheStats['min_ms'] !== null ? number_format($cacheStats['min_ms'], 2) . ' ms' : '—' }}
                    </div>
                </div>
                <div>
                    <div class="label">Max / p95</div>
                    <div style="margin-top:4px;font-size:14px;color:#aaa">
                        {{ $cacheStats['max_ms'] !== null ? number_format($cacheStats['max_ms'], 2) . ' ms' : '—' }}
                        / {{ $cacheStats['p95_ms'] !== null ? number_format($cacheStats['p95_ms'], 2) . ' ms' : '—' }}
                    </div>
                </div>
            </div>

            @if (!empty($cacheStats['samples']))
                <div style="{{ $subtle }};margin-bottom:6px">5 sample roundtrips (ms)</div>
                <div style="display:flex;gap:6px;align-items:flex-end;height:48px">
                    @php $maxSample = max($cacheStats['samples']) ?: 1; @endphp
                    @foreach ($cacheStats['samples'] as $s)
                        @php $h = max(4, ($s / $maxSample) * 100); @endphp
                        <div title="{{ number_format($s, 3) }} ms"
                             style="flex:1;height:{{ $h }}%;background:linear-gradient(180deg,#C5A55A 0%,rgba(197,165,90,0.4) 100%);border-radius:3px 3px 0 0"></div>
                    @endforeach
                </div>
            @endif

            @if ($cacheStats['error'])
                <div style="background:rgba(220,38,38,0.1);border:1px solid rgba(220,38,38,0.3);color:#ef4444;padding:8px 12px;border-radius:8px;font-size:12px;margin-top:12px;font-family:monospace">
                    {{ $cacheStats['error'] }}
                </div>
            @endif
        </div>

        <div style="{{ $cardStyle }};margin-bottom:0">
            <h3 style="{{ $sectionH3 }}">Database</h3>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px">
                <div>
                    <div class="label">Connection</div>
                    <div style="margin-top:4px;font-size:14px;color:#fff">{{ $db['connection'] }}<span style="color:#777"> ({{ $db['driver'] }})</span></div>
                </div>
                <div>
                    <div class="label">Version</div>
                    <div style="margin-top:4px;font-size:14px;color:#fff;font-family:monospace">
                        {{ $db['version'] ?? '—' }}
                    </div>
                </div>
                <div>
                    <div class="label">Database</div>
                    <div style="margin-top:4px;font-size:14px;color:#aaa">{{ $db['database'] ?? '—' }}</div>
                </div>
                <div>
                    <div class="label">Hot tables</div>
                    <div style="margin-top:4px;font-size:14px;color:#fff">{{ $db['table_count'] }} tracked</div>
                </div>
            </div>

            @if (!empty($db['tables']))
                <div style="overflow-x:auto;max-height:280px;overflow-y:auto;border:1px solid #2a2a2a;border-radius:8px">
                    <table class="admin-table">
                        <thead style="position:sticky;top:0;background:#1a1a1a">
                            <tr>
                                <th>Table</th>
                                <th style="text-align:right">Rows</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($db['tables'] as $t)
                                <tr>
                                    <td style="font-family:monospace;font-size:12px;color:#C5A55A">{{ $t['name'] }}</td>
                                    <td style="text-align:right">{{ number_format($t['rows']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($db['error'])
                <div style="background:rgba(220,38,38,0.1);border:1px solid rgba(220,38,38,0.3);color:#ef4444;padding:8px 12px;border-radius:8px;font-size:12px;margin-top:12px;font-family:monospace">
                    {{ $db['error'] }}
                </div>
            @endif
        </div>
    </div>

    {{-- ─── Slow queries ────────────────────────────────────────── --}}
    <div style="{{ $cardStyle }}">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
            <h3 style="{{ $sectionH3 }};margin:0">Slow Queries</h3>
            <span style="{{ $subtle }}">Telescope · laravel.log scan · 512KB tail</span>
        </div>

        @if (count($slowQueries) === 1 && str_contains($slowQueries[0], 'Telescope or query log not enabled'))
            <div style="color:#777;font-size:13px;padding:8px 0;line-height:1.6">
                Telescope or query log not enabled.
                Install <code style="background:#0f0f0f;padding:2px 6px;border-radius:4px;color:#C5A55A">laravel/telescope</code>
                for query inspection, or enable MySQL's slow query log
                and ensure it surfaces in <code style="background:#0f0f0f;padding:2px 6px;border-radius:4px;color:#C5A55A">storage/logs/laravel.log</code>.
            </div>
        @else
            <div style="display:flex;flex-direction:column;gap:6px;max-height:420px;overflow-y:auto">
                @foreach ($slowQueries as $row)
                    <div style="padding:10px 12px;background:#0f0f0f;border:1px solid #2a2a2a;border-radius:6px;font-family:monospace;font-size:11.5px;color:#bbb;word-break:break-word;line-height:1.5">
                        {{ $row }}
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <div style="{{ $subtle }};text-align:center;margin-top:12px">
        Generated at {{ \Carbon\Carbon::parse($generatedAt)->format('Y-m-d H:i:s T') }}
    </div>

</x-admin.layout>
