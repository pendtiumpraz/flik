@php
    $rp = static fn (float|int|null $v): string => 'Rp ' . number_format((float) ($v ?? 0), 0, ',', '.');

    // Largest user-count is the denominator for the inline bars.
    $maxUsers   = max(array_column($countries, 'users'), 1);
    $maxWatches = max(array_column($topByWatches, 'watches'), 1);
    $maxRevenue = max(array_column($topByRevenue, 'revenue'), 1);
@endphp

<x-admin.layout title="Geo Distribution">

    {{-- Header ----------------------------------------------------- --}}
    <div style="display:flex;justify-content:space-between;align-items:start;gap:16px;flex-wrap:wrap;margin-bottom:24px">
        <div>
            <h2 style="font-size:22px;font-weight:600;display:flex;align-items:center;gap:8px">
                <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#3b82f6"></span>
                Geo Distribution
            </h2>
            <p style="color:#777;font-size:13px;margin-top:4px">
                Where users hit FLiK from — derived from DRM session IPs (with cached country codes) and audit log IPs, resolved via GeoLite2.
                Cached for 6 hours. <span style="color:#555">Computed {{ \Illuminate\Support\Carbon::parse($computedAt)->diffForHumans() }}.</span>
            </p>
        </div>
        <div style="display:flex;gap:10px;align-items:center">
            <a href="?refresh=1" class="btn btn-ghost btn-sm">↻ Refresh</a>
        </div>
    </div>

    {{-- KPI cards -------------------------------------------------- --}}
    <div class="grid-stats" style="margin-bottom:24px">
        <div class="stat-card">
            <div style="display:flex;justify-content:space-between;align-items:start">
                <div>
                    <div class="label">Countries</div>
                    <div class="value">{{ number_format($stats['total_countries']) }}</div>
                </div>
                <div class="icon" style="background:rgba(197,165,90,0.15);color:#C5A55A">🌐</div>
            </div>
        </div>
        <div class="stat-card">
            <div style="display:flex;justify-content:space-between;align-items:start">
                <div>
                    <div class="label">Geo-Resolved Users</div>
                    <div class="value">{{ number_format($stats['resolved_users']) }}</div>
                </div>
                <div class="icon" style="background:rgba(59,130,246,0.15);color:#3b82f6">👤</div>
            </div>
        </div>
        <div class="stat-card">
            <div style="display:flex;justify-content:space-between;align-items:start">
                <div>
                    <div class="label">Total Watches</div>
                    <div class="value">{{ number_format($stats['total_watches']) }}</div>
                </div>
                <div class="icon" style="background:rgba(34,197,94,0.15);color:#22c55e">▶</div>
            </div>
        </div>
        <div class="stat-card">
            <div style="display:flex;justify-content:space-between;align-items:start">
                <div>
                    <div class="label">Tracked Revenue</div>
                    <div class="value">{{ $rp($stats['total_revenue']) }}</div>
                </div>
                <div class="icon" style="background:rgba(197,165,90,0.15);color:#C5A55A">$</div>
            </div>
        </div>
    </div>

    {{-- Empty state ------------------------------------------------- --}}
    @if(empty($countries))
        <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:48px 24px;text-align:center;color:#777">
            <div style="font-size:48px;margin-bottom:8px">🌐</div>
            <h3 style="font-size:16px;font-weight:600;color:#aaa;margin-bottom:6px">No geo data yet</h3>
            <p style="font-size:13px;max-width:480px;margin:0 auto">
                Geo distribution sources from <code style="color:#C5A55A">drm_sessions.client_ip</code> and
                <code style="color:#C5A55A">audit_logs.client_ip</code>. Once playback / audit events accumulate,
                this dashboard will populate.
            </p>
        </div>
    @else

        {{-- Top-3 leaderboards row ---------------------------------- --}}
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;margin-bottom:24px">

            {{-- Top by user count --}}
            <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:18px 22px">
                <h3 style="font-size:14px;font-weight:600;margin-bottom:14px;color:#C5A55A">Top by Users</h3>
                @if(empty($topByUsers))
                    <p style="font-size:12px;color:#666">No data.</p>
                @else
                    <div style="display:flex;flex-direction:column;gap:8px">
                        @foreach($topByUsers as $row)
                            @php $pct = ($row['users'] / max($topByUsers[0]['users'], 1)) * 100; @endphp
                            <div>
                                <div style="display:flex;justify-content:space-between;align-items:center;font-size:12px">
                                    <span style="color:#ddd">
                                        <span style="font-size:14px;margin-right:4px">{{ $row['flag'] }}</span>
                                        {{ $row['name'] }}
                                        <span style="color:#555;font-size:10px">({{ $row['code'] }})</span>
                                    </span>
                                    <span style="color:#fff;font-weight:600">{{ number_format($row['users']) }}</span>
                                </div>
                                <div style="background:#0f0f0f;height:4px;border-radius:2px;margin-top:4px;overflow:hidden">
                                    <div style="width:{{ $pct }}%;height:100%;background:linear-gradient(90deg,#C5A55A,#E8D5A3)"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Top by watch count --}}
            <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:18px 22px">
                <h3 style="font-size:14px;font-weight:600;margin-bottom:14px;color:#22c55e">Top by Watches</h3>
                @if(empty($topByWatches))
                    <p style="font-size:12px;color:#666">No watch data tied to geo-resolved users yet.</p>
                @else
                    <div style="display:flex;flex-direction:column;gap:8px">
                        @foreach($topByWatches as $row)
                            @php $pct = ($row['watches'] / max($topByWatches[0]['watches'], 1)) * 100; @endphp
                            <div>
                                <div style="display:flex;justify-content:space-between;align-items:center;font-size:12px">
                                    <span style="color:#ddd">
                                        <span style="font-size:14px;margin-right:4px">{{ $row['flag'] }}</span>
                                        {{ $row['name'] }}
                                    </span>
                                    <span style="color:#fff;font-weight:600">{{ number_format($row['watches']) }}</span>
                                </div>
                                <div style="background:#0f0f0f;height:4px;border-radius:2px;margin-top:4px;overflow:hidden">
                                    <div style="width:{{ $pct }}%;height:100%;background:linear-gradient(90deg,#22c55e,#4ade80)"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Top by revenue --}}
            <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:18px 22px">
                <h3 style="font-size:14px;font-weight:600;margin-bottom:14px;color:#3b82f6">Top by Revenue</h3>
                @if(empty($topByRevenue))
                    <p style="font-size:12px;color:#666">No revenue data tied to geo-resolved users yet.</p>
                @else
                    <div style="display:flex;flex-direction:column;gap:8px">
                        @foreach($topByRevenue as $row)
                            @php $pct = ($row['revenue'] / max($topByRevenue[0]['revenue'], 1)) * 100; @endphp
                            <div>
                                <div style="display:flex;justify-content:space-between;align-items:center;font-size:12px">
                                    <span style="color:#ddd">
                                        <span style="font-size:14px;margin-right:4px">{{ $row['flag'] }}</span>
                                        {{ $row['name'] }}
                                    </span>
                                    <span style="color:#fff;font-weight:600">{{ $rp($row['revenue']) }}</span>
                                </div>
                                <div style="background:#0f0f0f;height:4px;border-radius:2px;margin-top:4px;overflow:hidden">
                                    <div style="width:{{ $pct }}%;height:100%;background:linear-gradient(90deg,#3b82f6,#60a5fa)"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        {{-- Full country table ------------------------------------- --}}
        <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:20px 24px;margin-bottom:24px">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
                <h3 style="font-size:15px;font-weight:600">All Countries</h3>
                <span style="font-size:11px;color:#666">Sorted by user count</span>
            </div>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Country</th>
                        <th>Code</th>
                        <th style="text-align:right">Users</th>
                        <th style="text-align:right">Watches</th>
                        <th style="text-align:right">Revenue</th>
                        <th>Share</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($countries as $row)
                        @php $share = ($row['users'] / max($maxUsers, 1)) * 100; @endphp
                        <tr>
                            <td>
                                <span style="font-size:18px;margin-right:6px">{{ $row['flag'] }}</span>
                                <span style="color:#fff">{{ $row['name'] }}</span>
                            </td>
                            <td><span class="badge badge-blue">{{ $row['code'] }}</span></td>
                            <td style="text-align:right;color:#fff">{{ number_format($row['users']) }}</td>
                            <td style="text-align:right;color:#aaa">{{ number_format($row['watches']) }}</td>
                            <td style="text-align:right;color:#C5A55A">{{ $rp($row['revenue']) }}</td>
                            <td style="min-width:120px">
                                <div style="background:#0f0f0f;height:6px;border-radius:3px;overflow:hidden">
                                    <div style="width:{{ $share }}%;height:100%;background:linear-gradient(90deg,#C5A55A,#E8D5A3)"></div>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

</x-admin.layout>
