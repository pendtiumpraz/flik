@php
    /**
     * Locally-scoped helpers — formatting Rupiah amounts is the dominant
     * concern on this page, so keep it as a closure rather than reaching
     * for a global helper that may or may not exist.
     */
    $rp = static fn (float|int|null $v): string => 'Rp ' . number_format((float) ($v ?? 0), 0, ',', '.');

    $deltaSign = $stats['net_delta'] >= 0 ? '+' : '−';
    $deltaColor = $stats['net_delta'] >= 0 ? '#22c55e' : '#ef4444';
    $deltaIcon  = $stats['net_delta'] >= 0 ? '▲' : '▼';

    // Chart.js consumes JSON payloads — pre-encode now so the Blade output
    // stays clean and the JS block doesn't drown in @json calls.
    $trendLabels   = collect($trend)->pluck('label')->all();
    $trendCount    = collect($trend)->pluck('count')->all();
    $trendRevenue  = collect($trend)->pluck('revenue')->all();

    $planLabels = collect($perPlan)->pluck('name')->all();
    $planMrr    = collect($perPlan)->pluck('mrr')->all();
    $planColors = collect($perPlan)->pluck('color')->all();
@endphp

<x-admin.layout title="Revenue Dashboard">

    {{-- Chart.js (single CDN include, used for both charts) --}}
    <script src="https://cdn.jsdelivr.net/npm/chart.js" defer></script>

    {{-- Header ----------------------------------------------------- --}}
    <div style="display:flex;justify-content:space-between;align-items:start;gap:16px;flex-wrap:wrap;margin-bottom:24px">
        <div>
            <h2 style="font-size:22px;font-weight:600;display:flex;align-items:center;gap:8px">
                <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#C5A55A"></span>
                Revenue Dashboard
            </h2>
            <p style="color:#777;font-size:13px;margin-top:4px">
                MRR, ARR, churn, and 30-day trend across all paid plans. Cached for 1 hour.
                <span style="color:#555">Computed {{ \Illuminate\Support\Carbon::parse($computedAt)->diffForHumans() }}.</span>
            </p>
        </div>
        <div style="display:flex;gap:10px;align-items:center">
            <a href="?refresh=1" class="btn btn-ghost btn-sm" title="Recompute now">
                ↻ Refresh
            </a>
        </div>
    </div>

    {{-- KPI cards -------------------------------------------------- --}}
    <div class="grid-stats" style="margin-bottom:24px">
        <div class="stat-card">
            <div style="display:flex;justify-content:space-between;align-items:start">
                <div>
                    <div class="label">MRR</div>
                    <div class="value">{{ $rp($stats['mrr']) }}</div>
                    <div style="font-size:11px;color:#666;margin-top:4px">
                        {{ number_format($stats['active_subs']) }} active subscriptions
                    </div>
                </div>
                <div class="icon" style="background:rgba(197,165,90,0.15);color:#C5A55A">$</div>
            </div>
        </div>

        <div class="stat-card">
            <div style="display:flex;justify-content:space-between;align-items:start">
                <div>
                    <div class="label">ARR</div>
                    <div class="value">{{ $rp($stats['arr']) }}</div>
                    <div style="font-size:11px;color:#666;margin-top:4px">MRR × 12</div>
                </div>
                <div class="icon" style="background:rgba(34,197,94,0.15);color:#22c55e">∑</div>
            </div>
        </div>

        <div class="stat-card">
            <div style="display:flex;justify-content:space-between;align-items:start">
                <div>
                    <div class="label">Net Revenue (This Month)</div>
                    <div class="value">{{ $rp($stats['revenue_this_month']) }}</div>
                    <div style="font-size:11px;color:{{ $deltaColor }};margin-top:4px">
                        {{ $deltaIcon }} {{ $deltaSign }}{{ $rp(abs($stats['net_delta'])) }}
                        ({{ $deltaSign }}{{ number_format(abs($stats['net_delta_pct']), 1) }}%)
                        vs last month
                    </div>
                </div>
                <div class="icon" style="background:rgba(59,130,246,0.15);color:#3b82f6">↗</div>
            </div>
        </div>

        <div class="stat-card">
            <div style="display:flex;justify-content:space-between;align-items:start">
                <div>
                    <div class="label">Churn Revenue (This Month)</div>
                    <div class="value" style="color:#ef4444">{{ $rp($stats['churn_revenue']) }}</div>
                    <div style="font-size:11px;color:#666;margin-top:4px">
                        {{ number_format($stats['churn_count']) }} cancellations
                    </div>
                </div>
                <div class="icon" style="background:rgba(239,68,68,0.15);color:#ef4444">!</div>
            </div>
        </div>
    </div>

    {{-- Charts row ------------------------------------------------- --}}
    <div style="display:grid;grid-template-columns:2fr 1fr;gap:16px;margin-bottom:24px">
        <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:20px 24px;min-height:340px">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
                <h3 style="font-size:15px;font-weight:600">New Paid Subscriptions — Last 30 Days</h3>
                <span style="font-size:11px;color:#666">Count + Revenue</span>
            </div>
            <canvas id="trendChart" height="120"></canvas>
        </div>

        <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:20px 24px;min-height:340px">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
                <h3 style="font-size:15px;font-weight:600">MRR by Plan</h3>
                <span style="font-size:11px;color:#666">{{ number_format($stats['paid_plans_count']) }} paid plans</span>
            </div>
            <canvas id="planDonut" height="220"></canvas>
        </div>
    </div>

    {{-- LTV table -------------------------------------------------- --}}
    @if(!empty($ltvByPlan))
        <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:20px 24px;margin-bottom:24px">
            <h3 style="font-size:15px;font-weight:600;margin-bottom:14px">LTV by Plan</h3>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Plan</th>
                        <th style="text-align:right">Monthly Price</th>
                        <th style="text-align:right">Monthly Churn</th>
                        <th style="text-align:right">Estimated LTV</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($ltvByPlan as $row)
                        <tr>
                            <td>
                                <span class="badge badge-gold">{{ $row['name'] }}</span>
                            </td>
                            <td style="text-align:right;color:#fff">{{ $rp($row['price']) }}</td>
                            <td style="text-align:right;color:#aaa">
                                {{ number_format($row['churn_rate'], 2) }}%
                            </td>
                            <td style="text-align:right;color:#C5A55A;font-weight:600">
                                {{ $rp($row['ltv']) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p style="font-size:11px;color:#555;margin-top:10px">
                LTV = monthly price ÷ monthly churn rate. Churn derived from the last 90 days, with a 0.5 % floor to prevent infinity.
            </p>
        </div>
    @endif

    {{-- Latest subscriptions table -------------------------------- --}}
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:20px 24px;margin-bottom:24px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
            <h3 style="font-size:15px;font-weight:600">Latest 20 Subscriptions</h3>
            <span style="font-size:11px;color:#666">Excludes zero-value rows</span>
        </div>

        @if($latestSubscriptions->isEmpty())
            <p style="color:#666;font-size:13px;padding:24px 0;text-align:center">No paid subscriptions yet.</p>
        @else
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Plan</th>
                        <th>Status</th>
                        <th style="text-align:right">Amount</th>
                        <th>Paid At</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($latestSubscriptions as $sub)
                        <tr>
                            <td>
                                <div style="display:flex;flex-direction:column">
                                    <span style="color:#fff;font-weight:500">{{ $sub->user?->name ?? '—' }}</span>
                                    <span style="font-size:11px;color:#666">{{ $sub->user?->email ?? '' }}</span>
                                </div>
                            </td>
                            <td>
                                <span class="badge badge-gold">{{ $sub->plan?->name ?? '—' }}</span>
                            </td>
                            <td>
                                @php
                                    $statusColor = match($sub->status) {
                                        'active'    => 'badge-green',
                                        'cancelled' => 'badge badge-blue',
                                        'expired'   => 'badge badge-blue',
                                        default     => 'badge',
                                    };
                                @endphp
                                <span class="badge {{ $statusColor }}">{{ ucfirst($sub->status) }}</span>
                            </td>
                            <td style="text-align:right;color:#C5A55A">{{ $rp($sub->amount) }}</td>
                            <td style="font-size:12px;color:#aaa">
                                {{ optional($sub->paid_at ?? $sub->created_at)->format('d M Y H:i') }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- Chart.js bootstrap ---------------------------------------- --}}
    <script>
        // Chart.js loads via `defer`, so wait for the DOM + script.
        document.addEventListener('DOMContentLoaded', function () {
            // Poll briefly until Chart is defined — handles defer race.
            const ready = () => typeof window.Chart !== 'undefined';
            const start = Date.now();
            (function tick() {
                if (ready()) return boot();
                if (Date.now() - start > 4000) return; // give up after 4s
                setTimeout(tick, 50);
            })();

            function boot() {
                const trendCtx = document.getElementById('trendChart');
                if (trendCtx) {
                    new Chart(trendCtx, {
                        type: 'line',
                        data: {
                            labels: @json($trendLabels),
                            datasets: [
                                {
                                    label: 'New Subscriptions',
                                    data: @json($trendCount),
                                    borderColor: '#C5A55A',
                                    backgroundColor: 'rgba(197,165,90,0.15)',
                                    tension: 0.3,
                                    fill: true,
                                    yAxisID: 'y',
                                    pointRadius: 2,
                                },
                                {
                                    label: 'Revenue (Rp)',
                                    data: @json($trendRevenue),
                                    borderColor: '#3b82f6',
                                    backgroundColor: 'rgba(59,130,246,0.10)',
                                    tension: 0.3,
                                    fill: false,
                                    yAxisID: 'y1',
                                    pointRadius: 2,
                                }
                            ],
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            interaction: { mode: 'index', intersect: false },
                            plugins: {
                                legend: { labels: { color: '#999', font: { size: 11 } } },
                                tooltip: {
                                    backgroundColor: '#0f0f0f',
                                    borderColor: '#2a2a2a',
                                    borderWidth: 1,
                                    titleColor: '#fff',
                                    bodyColor: '#ddd',
                                }
                            },
                            scales: {
                                x: {
                                    ticks: { color: '#666', font: { size: 10 } },
                                    grid: { color: '#222' },
                                },
                                y: {
                                    position: 'left',
                                    beginAtZero: true,
                                    ticks: { color: '#666', font: { size: 10 } },
                                    grid: { color: '#222' },
                                    title: { display: true, text: 'Subs', color: '#777', font: { size: 11 } },
                                },
                                y1: {
                                    position: 'right',
                                    beginAtZero: true,
                                    ticks: {
                                        color: '#666',
                                        font: { size: 10 },
                                        callback: (v) => 'Rp' + Number(v).toLocaleString('id-ID'),
                                    },
                                    grid: { drawOnChartArea: false },
                                },
                            },
                        }
                    });
                }

                const donutCtx = document.getElementById('planDonut');
                if (donutCtx) {
                    new Chart(donutCtx, {
                        type: 'doughnut',
                        data: {
                            labels: @json($planLabels),
                            datasets: [{
                                data: @json($planMrr),
                                backgroundColor: @json($planColors),
                                borderColor: '#1a1a1a',
                                borderWidth: 2,
                            }],
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            cutout: '60%',
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: { color: '#999', font: { size: 11 }, boxWidth: 12 },
                                },
                                tooltip: {
                                    backgroundColor: '#0f0f0f',
                                    borderColor: '#2a2a2a',
                                    borderWidth: 1,
                                    titleColor: '#fff',
                                    bodyColor: '#ddd',
                                    callbacks: {
                                        label: function (ctx) {
                                            const v = ctx.raw || 0;
                                            return ctx.label + ': Rp' + Number(v).toLocaleString('id-ID');
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
            }
        });
    </script>

</x-admin.layout>
