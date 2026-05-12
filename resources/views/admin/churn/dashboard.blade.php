@php
    // Risk-band colour palette — kept in PHP so we can reuse across stat cards,
    // table rows, and progress bars without sprinkling hex codes everywhere.
    $col = [
        'low'      => '#22c55e', // green   — chill
        'medium'   => '#eab308', // amber   — watch
        'high'     => '#fb923c', // orange  — act
        'critical' => '#ef4444', // red     — fire
        'gold'     => '#C5A55A', // brand
    ];

    $levelLabel = [
        'low'      => 'Low',
        'medium'   => 'Medium',
        'high'     => 'High',
        'critical' => 'Critical',
    ];

    // Build a "?level=..." aware URL helper for the filter chips.
    $filterUrl = function (?string $level): string {
        $base = url('/admin/churn');
        return $level === null ? $base : $base . '?level=' . $level;
    };

    // CSV export URL preserves the active filter.
    $exportUrl = url('/admin/churn') . '?export=csv'
        . ($activeLevel ? '&level=' . $activeLevel : '');
@endphp

<x-admin.layout title="Churn Dashboard">

    {{-- Header ---------------------------------------------------------- --}}
    <div style="display:flex;justify-content:space-between;align-items:start;gap:16px;flex-wrap:wrap;margin-bottom:24px">
        <div>
            <h2 style="font-size:22px;font-weight:600;display:flex;align-items:center;gap:8px">
                <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:{{ $col['critical'] }}"></span>
                Churn Risk
            </h2>
            <p style="color:#777;font-size:13px;margin-top:4px">
                AI-augmented churn predictor. Heuristic signals score every paid user, then DeepSeek/etc. proposes a personalised win-back action for the high &amp; critical bands.
                Refresh by running
                <code style="background:#0f0f0f;padding:1px 6px;border-radius:3px;color:{{ $col['gold'] }}">php artisan flik:churn:predict</code>.
            </p>
        </div>

        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
            @if($lastRun)
                <div style="background:#1a1a1a;border:1px solid #2a2a2a;padding:8px 14px;border-radius:8px;font-size:12px;color:#aaa">
                    Last run: <strong style="color:#fff">{{ $lastRun->diffForHumans() }}</strong>
                    <span style="color:#555">({{ $lastRun->format('Y-m-d H:i') }})</span>
                </div>
            @else
                <div style="background:rgba(234,179,8,0.10);border:1px solid rgba(234,179,8,0.35);padding:8px 14px;border-radius:8px;font-size:12px;color:{{ $col['medium'] }}">
                    Predictor has never run — execute <code style="color:{{ $col['gold'] }}">flik:churn:predict</code>.
                </div>
            @endif

            <a href="{{ $exportUrl }}" class="btn btn-gold btn-sm" title="Download visible rows as CSV">
                Export CSV
            </a>
        </div>
    </div>

    {{-- Stat cards ------------------------------------------------------ --}}
    <div class="grid-stats" style="margin-bottom:20px">
        <div class="stat-card">
            <div style="display:flex;justify-content:space-between;align-items:start">
                <div>
                    <div class="label">Total Scored</div>
                    <div class="value">{{ number_format($stats['total']) }}</div>
                    <div style="font-size:11px;color:#555;margin-top:2px">across all bands</div>
                </div>
                <div class="icon" style="background:rgba(197,165,90,0.15);color:{{ $col['gold'] }}">★</div>
            </div>
        </div>

        <div class="stat-card">
            <div style="display:flex;justify-content:space-between;align-items:start">
                <div>
                    <div class="label">At Risk (high+critical)</div>
                    <div class="value" style="color:{{ $col['critical'] }}">{{ number_format($stats['at_risk']) }}</div>
                    <div style="font-size:11px;color:#555;margin-top:2px">{{ $stats['at_risk_pct'] }}% of scored</div>
                </div>
                <div class="icon" style="background:rgba(239,68,68,0.15);color:{{ $col['critical'] }}">!</div>
            </div>
        </div>

        <div class="stat-card">
            <div style="display:flex;justify-content:space-between;align-items:start">
                <div>
                    <div class="label">Critical</div>
                    <div class="value" style="color:{{ $col['critical'] }}">{{ number_format($stats['critical']) }}</div>
                </div>
                <div class="icon" style="background:rgba(239,68,68,0.15);color:{{ $col['critical'] }}">▲</div>
            </div>
        </div>

        <div class="stat-card">
            <div style="display:flex;justify-content:space-between;align-items:start">
                <div>
                    <div class="label">High</div>
                    <div class="value" style="color:{{ $col['high'] }}">{{ number_format($stats['high']) }}</div>
                </div>
                <div class="icon" style="background:rgba(251,146,60,0.15);color:{{ $col['high'] }}">▲</div>
            </div>
        </div>

        <div class="stat-card">
            <div style="display:flex;justify-content:space-between;align-items:start">
                <div>
                    <div class="label">Medium</div>
                    <div class="value" style="color:{{ $col['medium'] }}">{{ number_format($stats['medium']) }}</div>
                </div>
                <div class="icon" style="background:rgba(234,179,8,0.15);color:{{ $col['medium'] }}">●</div>
            </div>
        </div>

        <div class="stat-card">
            <div style="display:flex;justify-content:space-between;align-items:start">
                <div>
                    <div class="label">Low</div>
                    <div class="value" style="color:{{ $col['low'] }}">{{ number_format($stats['low']) }}</div>
                </div>
                <div class="icon" style="background:rgba(34,197,94,0.15);color:{{ $col['low'] }}">●</div>
            </div>
        </div>

        <div class="stat-card">
            <div style="display:flex;justify-content:space-between;align-items:start">
                <div>
                    <div class="label">Avg Risk Score</div>
                    <div class="value" style="color:{{
                        $stats['avg_score'] === null ? '#666'
                        : ($stats['avg_score'] >= 0.6 ? $col['critical']
                        : ($stats['avg_score'] >= 0.3 ? $col['medium'] : $col['low']))
                    }}">
                        {{ $stats['avg_score'] === null ? '—' : number_format($stats['avg_score'], 3) }}
                    </div>
                    <div style="font-size:11px;color:#555;margin-top:2px">range 0.000 – 1.000</div>
                </div>
                <div class="icon" style="background:rgba(197,165,90,0.15);color:{{ $col['gold'] }}">σ</div>
            </div>
        </div>
    </div>

    {{-- Filter chips ---------------------------------------------------- --}}
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:14px 18px;margin-bottom:20px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <span style="font-size:12px;color:#666;text-transform:uppercase;letter-spacing:1px;font-weight:600;margin-right:6px">Filter</span>

        @php
            $allActive = $activeLevel === null;
        @endphp
        <a href="{{ $filterUrl(null) }}"
           class="btn btn-sm {{ $allActive ? 'btn-gold' : 'btn-ghost' }}"
           style="{{ $allActive ? '' : 'border-color:#2a2a2a' }}">
            All at-risk (default)
        </a>

        @foreach($levels as $lvl)
            @php
                $isActive = $activeLevel === $lvl;
                $bg       = $col[$lvl] ?? '#666';
            @endphp
            <a href="{{ $filterUrl($lvl) }}"
               class="btn btn-sm"
               style="
                   background: {{ $isActive ? $bg : 'transparent' }};
                   color: {{ $isActive ? '#000' : $bg }};
                   border: 1px solid {{ $bg }};
                   font-weight: 600;
               ">
                {{ $levelLabel[$lvl] }}
            </a>
        @endforeach

        <span style="margin-left:auto;font-size:11px;color:#666">
            Showing {{ $predictions->count() }} of max {{ $tableLimit }}
        </span>
    </div>

    {{-- At-risk table -------------------------------------------------- --}}
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden">
        <div style="padding:16px 20px;border-bottom:1px solid #2a2a2a;display:flex;justify-content:space-between;align-items:center">
            <h3 style="font-size:15px;font-weight:600">
                Top At-Risk Users
                @if($activeLevel)
                    — <span style="color:{{ $col[$activeLevel] }}">{{ $levelLabel[$activeLevel] }} only</span>
                @endif
            </h3>
            <div style="font-size:11px;color:#666">Sorted by risk_score · descending</div>
        </div>

        @if($predictions->isEmpty())
            <div style="padding:48px 20px;text-align:center;color:#666;font-size:14px">
                No predictions match this filter.
                @if($stats['total'] === 0)
                    <br><span style="font-size:12px;color:#555;margin-top:6px;display:inline-block">
                        Run <code style="background:#0f0f0f;padding:1px 6px;border-radius:3px;color:{{ $col['gold'] }}">php artisan flik:churn:predict</code> to populate this dashboard.
                    </span>
                @endif
            </div>
        @else
            <table class="admin-table">
                <thead>
                    <tr>
                        <th style="width:60px">#</th>
                        <th>User</th>
                        <th style="width:110px">Risk</th>
                        <th style="width:100px">Level</th>
                        <th>Signals</th>
                        <th>Suggested Action</th>
                        <th style="width:120px">Computed</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($predictions as $p)
                        @php
                            $signals = is_array($p->signals) ? $p->signals : [];
                            $level   = $p->risk_level;
                            $bandColor = $col[$level] ?? '#999';
                            $scorePct  = max(0, min(100, (int) round(((float) $p->risk_score) * 100)));

                            $daysSince  = $signals['days_since_last_watch']        ?? null;
                            $subStatus  = $signals['subscription_status']          ?? null;
                            $subExpDays = $signals['subscription_expires_in_days'] ?? null;
                            $thisWeek   = $signals['watch_count_this_week']        ?? null;
                            $lastWeek   = $signals['watch_count_last_week']        ?? null;
                            $unused     = $signals['watchlist_unused_count']       ?? null;
                            $watchTotal = $signals['watchlist_total']              ?? null;
                            $rRecent    = $signals['ratings_last_30_days']         ?? null;
                            $rAll       = $signals['ratings_all_time']             ?? null;
                        @endphp
                        <tr>
                            <td style="color:#555">{{ $loop->iteration }}</td>

                            <td>
                                <div style="font-weight:500;color:#fff">{{ $p->user?->name ?? 'Deleted user' }}</div>
                                <div style="font-size:11px;color:#666">{{ $p->user?->email ?? '#' . $p->user_id }}</div>
                            </td>

                            <td>
                                <div style="display:flex;align-items:center;gap:8px">
                                    <span style="font-family:'Outfit',sans-serif;font-weight:700;color:{{ $bandColor }};font-size:14px">
                                        {{ number_format((float) $p->risk_score, 3) }}
                                    </span>
                                </div>
                                <div style="height:4px;background:#0f0f0f;border-radius:999px;overflow:hidden;margin-top:4px">
                                    <div style="height:100%;width:{{ $scorePct }}%;background:{{ $bandColor }}"></div>
                                </div>
                            </td>

                            <td>
                                <span style="
                                    display:inline-block;
                                    padding:3px 10px;
                                    border-radius:999px;
                                    background:{{ $bandColor }}1f;
                                    color:{{ $bandColor }};
                                    font-size:11px;
                                    font-weight:700;
                                    text-transform:uppercase;
                                    letter-spacing:0.5px;
                                ">
                                    {{ $levelLabel[$level] ?? $level }}
                                </span>
                            </td>

                            <td style="font-size:11px;color:#aaa;line-height:1.7;min-width:220px">
                                @if($daysSince !== null)
                                    <div>
                                        <span style="color:#666">last watch:</span>
                                        <strong style="color:{{ $daysSince >= 14 ? $col['critical'] : ($daysSince >= 7 ? $col['high'] : '#ddd') }}">
                                            {{ $daysSince }}d ago
                                        </strong>
                                    </div>
                                @endif

                                @if($subStatus)
                                    <div>
                                        <span style="color:#666">sub:</span>
                                        @php
                                            $subColor = match($subStatus) {
                                                'active'           => $col['low'],
                                                'active_expiring'  => $col['medium'],
                                                'cancelled'        => $col['high'],
                                                'expired'          => $col['critical'],
                                                default            => '#aaa',
                                            };
                                        @endphp
                                        <strong style="color:{{ $subColor }}">{{ $subStatus }}</strong>
                                        @if($subExpDays !== null && $subExpDays >= 0)
                                            <span style="color:#666">(in {{ $subExpDays }}d)</span>
                                        @endif
                                    </div>
                                @endif

                                @if($thisWeek !== null && $lastWeek !== null)
                                    <div>
                                        <span style="color:#666">watches w/w:</span>
                                        <strong style="color:#ddd">{{ $thisWeek }}</strong>
                                        <span style="color:#666">vs</span>
                                        <span style="color:#999">{{ $lastWeek }}</span>
                                        @if($lastWeek > 0 && $thisWeek < $lastWeek)
                                            <span style="color:{{ $col['high'] }};font-weight:600">↓</span>
                                        @endif
                                    </div>
                                @endif

                                @if($watchTotal !== null && $watchTotal > 0)
                                    <div>
                                        <span style="color:#666">watchlist unused:</span>
                                        <strong style="color:#ddd">{{ $unused }}/{{ $watchTotal }}</strong>
                                    </div>
                                @endif

                                @if($rAll !== null && $rAll > 0)
                                    <div>
                                        <span style="color:#666">ratings 30d/all:</span>
                                        <strong style="color:#ddd">{{ $rRecent }}/{{ $rAll }}</strong>
                                    </div>
                                @endif
                            </td>

                            <td style="font-size:12px;color:#ddd;max-width:320px;line-height:1.5">
                                @if($p->suggested_action)
                                    <span style="color:{{ $col['gold'] }};font-size:10px;text-transform:uppercase;letter-spacing:1px;font-weight:600">AI</span>
                                    <div style="margin-top:2px">{{ $p->suggested_action }}</div>
                                @else
                                    <span style="color:#555;font-style:italic;font-size:11px">— (no action needed for this band)</span>
                                @endif
                            </td>

                            <td style="font-size:11px;color:#777">
                                {{ $p->computed_at ? $p->computed_at->diffForHumans() : '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- Footnote ------------------------------------------------------- --}}
    <div style="margin-top:24px;background:rgba(197,165,90,0.06);border:1px solid rgba(197,165,90,0.25);border-radius:10px;padding:14px 18px">
        <div style="color:{{ $col['gold'] }};font-weight:600;font-size:12px;margin-bottom:4px">
            How this works
        </div>
        <div style="color:#aaa;font-size:12px;line-height:1.6">
            The predictor combines five heuristic signals — days since last watch, week-over-week watch decline,
            subscription status, recent vs lifetime rating activity, and the unused-watchlist ratio — into a weighted score in
            <code style="background:#0f0f0f;padding:1px 6px;border-radius:3px;color:{{ $col['gold'] }}">[0, 1]</code>.
            Bands: <span style="color:{{ $col['low'] }}">low &lt;0.3</span> ·
            <span style="color:{{ $col['medium'] }}">medium &lt;0.6</span> ·
            <span style="color:{{ $col['high'] }}">high &lt;0.8</span> ·
            <span style="color:{{ $col['critical'] }}">critical ≥0.8</span>.
            For high+critical only, the configured AI provider proposes one personalised win-back action in Indonesian. Schedule
            <code style="background:#0f0f0f;padding:1px 6px;border-radius:3px;color:{{ $col['gold'] }}">flik:churn:predict</code>
            daily to keep this dashboard fresh.
        </div>
    </div>

</x-admin.layout>
