<x-admin.layout title="AI Usage & Cost">

    @php
        $maxDailyCost = max(array_column($daily, 'cost')) ?: 0.0001;
        $providerPalette = [
            'openai'     => '#10a37f',
            'anthropic'  => '#c5a55a',
            'deepseek'   => '#3b82f6',
            'google'     => '#ea4335',
            'groq'       => '#f97316',
            'mistral'    => '#a855f7',
            'openrouter' => '#22c55e',
            'custom'     => '#6b7280',
        ];
        $taskPalette = ['#C5A55A', '#3b82f6', '#22c55e', '#a855f7', '#f97316', '#ec4899', '#06b6d4', '#facc15', '#84cc16'];
        $providerCostSum = $perProvider->sum('cost_usd') ?: 0.0001;
        $taskCostSum = $perTask->sum('cost_usd') ?: 0.0001;
    @endphp

    <!-- ── KPI cards ──────────────────────────────────────────── -->
    <div class="grid-stats" style="margin-bottom:24px">
        <div class="stat-card">
            <div style="display:flex;justify-content:space-between;align-items:start">
                <div>
                    <div class="label">Spend (7 days)</div>
                    <div class="value">${{ number_format($stats['spend_7d'], 4) }}</div>
                </div>
                <div class="icon" style="background:rgba(197,165,90,0.15);color:#C5A55A">
                    <x-icon name="coin" size="20" />
                </div>
            </div>
        </div>

        <div class="stat-card">
            <div style="display:flex;justify-content:space-between;align-items:start">
                <div>
                    <div class="label">Spend (30 days)</div>
                    <div class="value">${{ number_format($stats['spend_30d'], 4) }}</div>
                    <div style="font-size:11px;color:#666;margin-top:4px">All-time: ${{ number_format($stats['all_time_spend'], 4) }}</div>
                </div>
                <div class="icon" style="background:rgba(34,197,94,0.15);color:#22c55e">
                    <x-icon name="lightning" size="20" />
                </div>
            </div>
        </div>

        <div class="stat-card">
            <div style="display:flex;justify-content:space-between;align-items:start">
                <div>
                    <div class="label">Tokens (30 days)</div>
                    <div class="value">{{ number_format($stats['total_tokens_30d']) }}</div>
                    <div style="font-size:11px;color:#666;margin-top:4px">All-time: {{ number_format($stats['all_time_tokens']) }}</div>
                </div>
                <div class="icon" style="background:rgba(59,130,246,0.15);color:#3b82f6">
                    <x-icon name="sparkles" size="20" />
                </div>
            </div>
        </div>

        <div class="stat-card">
            <div style="display:flex;justify-content:space-between;align-items:start">
                <div>
                    <div class="label">Calls (30 days)</div>
                    <div class="value">{{ number_format($stats['total_calls_30d']) }}</div>
                    <div style="font-size:11px;color:#666;margin-top:4px">
                        Success: <span style="color:#22c55e">{{ $stats['success_rate_30d'] }}%</span>
                        @if($stats['avg_latency_ms'] !== null)
                            · {{ number_format($stats['avg_latency_ms']) }}ms avg
                        @endif
                    </div>
                </div>
                <div class="icon" style="background:rgba(168,85,247,0.15);color:#a855f7">
                    <x-icon name="clock" size="20" />
                </div>
            </div>
        </div>
    </div>

    <!-- ── Daily spend bar chart (last 14 days) ───────────────── -->
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:20px 24px;margin-bottom:24px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
            <h3 style="font-size:15px;font-weight:600">Daily Spend (last 14 days)</h3>
            <span style="font-size:11px;color:#666">Peak: ${{ number_format($maxDailyCost, 4) }}</span>
        </div>
        <div style="display:flex;align-items:flex-end;gap:6px;height:140px;padding:0 2px">
            @foreach($daily as $d)
                @php $h = $maxDailyCost > 0 ? max(2, ($d['cost'] / $maxDailyCost) * 100) : 2; @endphp
                <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:4px;min-width:0">
                    <div style="flex:1;width:100%;display:flex;align-items:flex-end;justify-content:center">
                        <div title="${{ number_format($d['cost'], 4) }} · {{ number_format($d['tokens']) }} tokens"
                             style="width:100%;max-width:32px;height:{{ $h }}%;background:linear-gradient(180deg,#C5A55A 0%,rgba(197,165,90,0.4) 100%);border-radius:4px 4px 0 0;transition:opacity .2s;cursor:default"
                             onmouseover="this.style.opacity=0.7" onmouseout="this.style.opacity=1"></div>
                    </div>
                    <div style="font-size:10px;color:#666;white-space:nowrap;transform:rotate(-30deg);transform-origin:center top">{{ $d['label'] }}</div>
                </div>
            @endforeach
        </div>
    </div>

    <!-- ── Per-provider breakdown ─────────────────────────────── -->
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden;margin-bottom:24px">
        <div style="padding:16px 20px;border-bottom:1px solid #2a2a2a;display:flex;justify-content:space-between;align-items:center">
            <h3 style="font-size:15px;font-weight:600">By Provider (last 30 days)</h3>
            <a href="{{ route('admin.ai.index') }}" style="font-size:12px;color:#C5A55A;text-decoration:none">Manage Providers →</a>
        </div>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Provider</th>
                    <th>Model</th>
                    <th style="text-align:right">Calls</th>
                    <th style="text-align:right">Tokens</th>
                    <th style="text-align:right">Avg Latency</th>
                    <th style="text-align:right">Cost</th>
                    <th style="width:25%">Share</th>
                </tr>
            </thead>
            <tbody>
                @forelse($perProvider as $p)
                    @php
                        $color = $providerPalette[$p->provider] ?? '#C5A55A';
                        $share = $providerCostSum > 0 ? ((float) $p->cost_usd / $providerCostSum) * 100 : 0;
                        $totalTokens = (int) ($p->input_tokens + $p->output_tokens);
                    @endphp
                    <tr>
                        <td>
                            <div style="display:flex;align-items:center;gap:10px">
                                <span style="width:8px;height:8px;border-radius:50%;background:{{ $color }};flex-shrink:0"></span>
                                <div>
                                    <div style="font-weight:500;color:#fff">{{ $p->name }}</div>
                                    <div style="font-size:11px;color:#666;text-transform:uppercase;letter-spacing:.5px">{{ $p->provider }}</div>
                                </div>
                                @if($p->is_default)
                                    <span class="badge badge-gold">Default</span>
                                @elseif(!$p->is_active)
                                    <span class="badge" style="background:rgba(107,114,128,0.2);color:#9ca3af">Inactive</span>
                                @endif
                            </div>
                        </td>
                        <td style="color:#aaa;font-family:'JetBrains Mono',monospace;font-size:12px">{{ $p->model }}</td>
                        <td style="text-align:right">{{ number_format($p->call_count) }}</td>
                        <td style="text-align:right;color:#aaa">{{ number_format($totalTokens) }}</td>
                        <td style="text-align:right;color:#888">
                            {{ $p->avg_latency ? number_format((float) $p->avg_latency) . 'ms' : '—' }}
                        </td>
                        <td style="text-align:right;font-weight:600;color:#C5A55A">${{ number_format((float) $p->cost_usd, 4) }}</td>
                        <td>
                            <div style="background:#0f0f0f;border-radius:4px;height:8px;overflow:hidden">
                                <div style="height:100%;background:{{ $color }};width:{{ $share }}%;transition:width .3s"></div>
                            </div>
                            <div style="font-size:10px;color:#666;margin-top:3px">{{ number_format($share, 1) }}%</div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" style="text-align:center;color:#555;padding:32px">No usage recorded yet</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- ── Per-task breakdown ─────────────────────────────────── -->
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden;margin-bottom:24px">
        <div style="padding:16px 20px;border-bottom:1px solid #2a2a2a">
            <h3 style="font-size:15px;font-weight:600">By Task (last 30 days)</h3>
        </div>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Task</th>
                    <th style="text-align:right">Calls</th>
                    <th style="text-align:right">Success</th>
                    <th style="text-align:right">Tokens</th>
                    <th style="text-align:right">Avg Latency</th>
                    <th style="text-align:right">Cost</th>
                    <th style="width:20%">Share</th>
                </tr>
            </thead>
            <tbody>
                @forelse($perTask as $i => $t)
                    @php
                        $color = $taskPalette[$i % count($taskPalette)];
                        $share = $taskCostSum > 0 ? ((float) $t->cost_usd / $taskCostSum) * 100 : 0;
                        $successPct = $t->call_count > 0 ? round(((int) $t->success_count / (int) $t->call_count) * 100, 1) : 100;
                        $totalTokens = (int) ($t->input_tokens + $t->output_tokens);
                    @endphp
                    <tr>
                        <td>
                            <div style="display:flex;align-items:center;gap:10px">
                                <span style="width:8px;height:8px;border-radius:50%;background:{{ $color }};flex-shrink:0"></span>
                                <code style="font-family:'JetBrains Mono',monospace;font-size:12px;color:#e5e5e5;background:#0f0f0f;padding:3px 8px;border-radius:4px">{{ $t->task_type }}</code>
                            </div>
                        </td>
                        <td style="text-align:right">{{ number_format($t->call_count) }}</td>
                        <td style="text-align:right;color:{{ $successPct >= 95 ? '#22c55e' : ($successPct >= 80 ? '#facc15' : '#ef4444') }}">{{ $successPct }}%</td>
                        <td style="text-align:right;color:#aaa">{{ number_format($totalTokens) }}</td>
                        <td style="text-align:right;color:#888">
                            {{ $t->avg_latency ? number_format((float) $t->avg_latency) . 'ms' : '—' }}
                        </td>
                        <td style="text-align:right;font-weight:600;color:#C5A55A">${{ number_format((float) $t->cost_usd, 4) }}</td>
                        <td>
                            <div style="background:#0f0f0f;border-radius:4px;height:8px;overflow:hidden">
                                <div style="height:100%;background:{{ $color }};width:{{ $share }}%;transition:width .3s"></div>
                            </div>
                            <div style="font-size:10px;color:#666;margin-top:3px">{{ number_format($share, 1) }}%</div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" style="text-align:center;color:#555;padding:32px">No task data yet</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- ── Recent calls ───────────────────────────────────────── -->
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden">
        <div style="padding:16px 20px;border-bottom:1px solid #2a2a2a;display:flex;justify-content:space-between;align-items:center">
            <h3 style="font-size:15px;font-weight:600">Recent Calls</h3>
            <span style="font-size:11px;color:#666">Last 50</span>
        </div>
        <div style="overflow-x:auto">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Task</th>
                        <th>Provider · Model</th>
                        <th style="text-align:right">In / Out</th>
                        <th style="text-align:right">Latency</th>
                        <th style="text-align:right">Cost</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recent as $log)
                        <tr>
                            <td style="white-space:nowrap;color:#888;font-size:12px" title="{{ $log->created_at }}">
                                {{ $log->created_at->diffForHumans(null, true) }} ago
                            </td>
                            <td>
                                <code style="font-family:'JetBrains Mono',monospace;font-size:11px;color:#e5e5e5;background:#0f0f0f;padding:2px 6px;border-radius:4px">{{ $log->task_type }}</code>
                            </td>
                            <td style="font-size:12px">
                                @if($log->provider)
                                    <div style="color:#fff">{{ $log->provider->name }}</div>
                                    <div style="color:#666;font-family:'JetBrains Mono',monospace;font-size:11px">{{ $log->provider->model }}</div>
                                @else
                                    <span style="color:#666">(deleted)</span>
                                @endif
                            </td>
                            <td style="text-align:right;color:#aaa;font-size:12px;white-space:nowrap">
                                {{ number_format($log->input_tokens) }} / {{ number_format($log->output_tokens) }}
                            </td>
                            <td style="text-align:right;color:#888;font-size:12px">
                                {{ $log->latency_ms ? number_format($log->latency_ms) . 'ms' : '—' }}
                            </td>
                            <td style="text-align:right;font-weight:600;color:#C5A55A;font-size:13px">
                                ${{ number_format((float) $log->cost_usd, 6) }}
                            </td>
                            <td>
                                @if($log->cache_hit)
                                    <span class="badge badge-blue" title="Cache hit">Cached</span>
                                @elseif($log->success)
                                    <span class="badge badge-green">OK</span>
                                @else
                                    <span class="badge" style="background:rgba(239,68,68,0.2);color:#ef4444" title="{{ $log->error_message }}">Failed</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" style="text-align:center;color:#555;padding:32px">No AI calls logged yet. UsageTracker must be wired into AiClient.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</x-admin.layout>
