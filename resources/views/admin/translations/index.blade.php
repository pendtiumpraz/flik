<x-admin.layout title="Translation Dashboard">

    <!-- ── Cache KPI cards ───────────────────────────────────── -->
    <div class="grid-stats" style="margin-bottom:24px">
        <div class="stat-card">
            <div style="display:flex;justify-content:space-between;align-items:start">
                <div>
                    <div class="label">Cache Entries</div>
                    <div class="value">{{ number_format($cacheStats['total']) }}</div>
                    @if($cacheStats['oldest'])
                        <div style="font-size:11px;color:#666;margin-top:4px">
                            Since {{ $cacheStats['oldest']->toDateString() }}
                        </div>
                    @endif
                </div>
                <div class="icon" style="background:rgba(197,165,90,0.15);color:#C5A55A">
                    <x-icon name="sparkles" size="20" />
                </div>
            </div>
        </div>

        <div class="stat-card">
            <div style="display:flex;justify-content:space-between;align-items:start">
                <div>
                    <div class="label">Hits (24h)</div>
                    <div class="value">{{ number_format($cacheStats['hits_24h']) }}</div>
                    <div style="font-size:11px;color:#666;margin-top:4px">Reads against existing cache rows</div>
                </div>
                <div class="icon" style="background:rgba(34,197,94,0.15);color:#22c55e">
                    <x-icon name="lightning" size="20" />
                </div>
            </div>
        </div>

        <div class="stat-card">
            <div style="display:flex;justify-content:space-between;align-items:start">
                <div>
                    <div class="label">Fresh Translations (24h)</div>
                    <div class="value">{{ number_format($cacheStats['fresh_24h']) }}</div>
                    <div style="font-size:11px;color:#666;margin-top:4px">Cache misses → new AI calls</div>
                </div>
                <div class="icon" style="background:rgba(59,130,246,0.15);color:#3b82f6">
                    <x-icon name="clock" size="20" />
                </div>
            </div>
        </div>

        <div class="stat-card">
            <div style="display:flex;justify-content:space-between;align-items:start">
                <div>
                    <div class="label">Hit Rate (24h)</div>
                    <div class="value">{{ number_format($cacheStats['hit_rate_pct'], 1) }}%</div>
                    <div style="font-size:11px;color:#666;margin-top:4px">Higher is cheaper</div>
                </div>
                <div class="icon" style="background:rgba(168,85,247,0.15);color:#a855f7">
                    <x-icon name="star" size="20" />
                </div>
            </div>
        </div>
    </div>

    <!-- ── Static UI coverage per locale ─────────────────────── -->
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:20px 24px;margin-bottom:24px">
        <h2 style="font-size:16px;margin-bottom:4px">UI String Coverage</h2>
        <p style="font-size:12px;color:#777;margin-bottom:20px">
            Counts the union of keys defined across <code style="color:#C5A55A">lang/&lt;code&gt;.json</code>.
            Each locale's coverage shows how many of those keys it has filled in.
        </p>

        <table style="width:100%;border-collapse:collapse">
            <thead>
                <tr style="text-align:left;border-bottom:1px solid #2a2a2a">
                    <th style="padding:10px 8px;font-size:12px;color:#888;font-weight:500">Locale</th>
                    <th style="padding:10px 8px;font-size:12px;color:#888;font-weight:500">Coverage</th>
                    <th style="padding:10px 8px;font-size:12px;color:#888;font-weight:500;text-align:right">Present</th>
                    <th style="padding:10px 8px;font-size:12px;color:#888;font-weight:500;text-align:right">Missing</th>
                </tr>
            </thead>
            <tbody>
                @foreach($coverage as $row)
                    <tr style="border-bottom:1px solid #222">
                        <td style="padding:14px 8px">
                            <div style="display:flex;align-items:center;gap:8px">
                                <span style="font-size:18px">{{ $row['flag'] }}</span>
                                <div>
                                    <div style="font-weight:500">{{ $row['name'] }}</div>
                                    <div style="font-size:11px;color:#666">
                                        <code>{{ $row['code'] }}</code>
                                        @if($row['rtl'])
                                            <span style="margin-left:6px;padding:1px 6px;background:rgba(197,165,90,0.15);color:#C5A55A;border-radius:4px;font-size:9px">RTL</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td style="padding:14px 8px;min-width:240px">
                            @php
                                $pct = (float) $row['percent'];
                                $barColor = $pct >= 90 ? '#22c55e' : ($pct >= 60 ? '#C5A55A' : '#ef4444');
                            @endphp
                            <div style="background:#0f0f0f;height:8px;border-radius:4px;overflow:hidden">
                                <div style="background:{{ $barColor }};width:{{ $pct }}%;height:100%"></div>
                            </div>
                            <div style="font-size:11px;color:#888;margin-top:4px">{{ number_format($pct, 1) }}%</div>
                        </td>
                        <td style="padding:14px 8px;text-align:right;color:#22c55e">{{ number_format($row['present']) }}</td>
                        <td style="padding:14px 8px;text-align:right;color:{{ $row['missing'] > 0 ? '#ef4444' : '#666' }}">
                            {{ number_format($row['missing']) }}
                        </td>
                    </tr>
                    @if(! empty($row['missing_keys']))
                        <tr>
                            <td colspan="4" style="padding:0 8px 14px">
                                <details>
                                    <summary style="cursor:pointer;color:#888;font-size:12px">
                                        Show missing keys ({{ count($row['missing_keys']) }}@if($row['missing'] > count($row['missing_keys'])) of {{ $row['missing'] }} shown @endif)
                                    </summary>
                                    <div style="margin-top:8px;display:flex;flex-wrap:wrap;gap:6px">
                                        @foreach($row['missing_keys'] as $key)
                                            <code style="background:#0f0f0f;border:1px solid #2a2a2a;padding:3px 8px;border-radius:4px;font-size:11px;color:#ccc">{{ $key }}</code>
                                        @endforeach
                                    </div>
                                </details>
                            </td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>
    </div>

    <!-- ── Cache breakdown ───────────────────────────────────── -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px">
        <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:20px 24px">
            <h2 style="font-size:16px;margin-bottom:16px">Translations by Pair</h2>
            @if(empty($byPair))
                <p style="color:#666;font-size:13px">No cached translations yet.</p>
            @else
                <table style="width:100%;border-collapse:collapse">
                    <thead>
                        <tr style="text-align:left;border-bottom:1px solid #2a2a2a">
                            <th style="padding:8px;font-size:11px;color:#888;font-weight:500">Source</th>
                            <th style="padding:8px;font-size:11px;color:#888;font-weight:500">Target</th>
                            <th style="padding:8px;font-size:11px;color:#888;font-weight:500;text-align:right">Cached</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($byPair as $pair)
                            <tr style="border-bottom:1px solid #222">
                                <td style="padding:10px 8px"><code>{{ $pair['source'] }}</code></td>
                                <td style="padding:10px 8px"><code>{{ $pair['target'] }}</code></td>
                                <td style="padding:10px 8px;text-align:right">{{ number_format($pair['count']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:20px 24px">
            <h2 style="font-size:16px;margin-bottom:16px">Translations by Provider</h2>
            @if(empty($byProvider))
                <p style="color:#666;font-size:13px">No cached translations yet.</p>
            @else
                <table style="width:100%;border-collapse:collapse">
                    <thead>
                        <tr style="text-align:left;border-bottom:1px solid #2a2a2a">
                            <th style="padding:8px;font-size:11px;color:#888;font-weight:500">Provider</th>
                            <th style="padding:8px;font-size:11px;color:#888;font-weight:500;text-align:right">Cached</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($byProvider as $row)
                            <tr style="border-bottom:1px solid #222">
                                <td style="padding:10px 8px"><code>{{ $row['provider'] }}</code></td>
                                <td style="padding:10px 8px;text-align:right">{{ number_format($row['count']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

</x-admin.layout>
