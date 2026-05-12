<x-admin.layout title="Pricing Optimization">

    @php
        $gold       = '#C5A55A';
        $global     = $report['global'] ?? ['new_users' => 0, 'paid_subs' => 0, 'conversion_pct' => 0];
        $plans      = $report['plans'] ?? [];
        $recs       = $report['recommendations'] ?? [];
        $aiError    = $report['ai_error'] ?? null;
        $window     = $report['window_days'] ?? 90;

        // Index recommendations by plan slug for quick lookup beside each plan row.
        $recBySlug = [];
        foreach ($recs as $r) {
            $recBySlug[$r['plan_slug']] = $r;
        }

        $confMeta = [
            'high'   => ['#22c55e', 'rgba(34,197,94,0.12)',  'Tinggi'],
            'medium' => ['#eab308', 'rgba(234,179,8,0.12)',  'Sedang'],
            'low'    => ['#94a3b8', 'rgba(148,163,184,0.12)', 'Rendah'],
        ];

        $totalPaidRevenue = array_sum(array_column($plans, 'paid_revenue_in_window'));
        $avgConversion = count($plans) > 0
            ? round(array_sum(array_column($plans, 'conversion_pct')) / count($plans), 2)
            : 0;
    @endphp

    {{-- ── Header ───────────────────────────────────────────── --}}
    <div style="display:flex;justify-content:space-between;align-items:start;gap:16px;flex-wrap:wrap;margin-bottom:24px">
        <div>
            <h2 style="font-size:22px;font-weight:600;display:flex;align-items:center;gap:8px">
                <x-icon name="cog" size="22" style="color:{{ $gold }}" />
                Pricing Optimization
            </h2>
            <p style="color:#777;font-size:13px;margin-top:6px;max-width:680px">
                Performa setiap paket langganan dalam {{ $window }} hari terakhir — konversi, churn dini, LTV — plus
                rekomendasi penyesuaian harga dari AI dalam Bahasa Indonesia.
            </p>
        </div>

        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
            @if($cachedAt)
                <div style="background:#1a1a1a;border:1px solid #2a2a2a;padding:8px 14px;border-radius:8px;font-size:12px;color:#aaa">
                    <x-icon name="clock" size="13" />
                    Cache: <strong style="color:#fff">{{ \Carbon\Carbon::parse($cachedAt)->diffForHumans() }}</strong>
                </div>
            @endif
            <a href="{{ url()->current() }}?refresh=1"
               class="btn btn-ghost btn-sm"
               onclick="this.innerText='Memproses...'; this.style.opacity='0.6'">
                <x-icon name="sparkles" size="14" /> Refresh
            </a>
        </div>
    </div>

    @if($aiError)
        <div style="background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.3);color:#fca5a5;padding:12px 16px;border-radius:8px;margin-bottom:20px;font-size:13px">
            <strong>AI tidak tersedia:</strong> {{ $aiError }} — tabel statistik tetap bisa dibaca.
        </div>
    @endif

    {{-- ── KPI cards ────────────────────────────────────────── --}}
    <div class="grid-stats" style="margin-bottom:24px">
        <div class="stat-card">
            <div style="display:flex;justify-content:space-between;align-items:start">
                <div>
                    <div class="label">Pengguna Baru ({{ $window }}d)</div>
                    <div class="value">{{ number_format($global['new_users']) }}</div>
                </div>
                <div class="icon" style="background:rgba(59,130,246,0.15);color:#3b82f6">
                    <x-icon name="user" size="20" />
                </div>
            </div>
        </div>

        <div class="stat-card">
            <div style="display:flex;justify-content:space-between;align-items:start">
                <div>
                    <div class="label">Langganan Berbayar</div>
                    <div class="value">{{ number_format($global['paid_subs']) }}</div>
                </div>
                <div class="icon" style="background:rgba(197,165,90,0.15);color:{{ $gold }}">
                    <x-icon name="coin" size="20" />
                </div>
            </div>
        </div>

        <div class="stat-card">
            <div style="display:flex;justify-content:space-between;align-items:start">
                <div>
                    <div class="label">Konversi Global</div>
                    <div class="value">{{ number_format($global['conversion_pct'], 2) }}%</div>
                    <div style="font-size:11px;color:#666;margin-top:4px">Berbayar / Pengguna baru</div>
                </div>
                <div class="icon" style="background:rgba(34,197,94,0.15);color:#22c55e">
                    <x-icon name="lightning" size="20" />
                </div>
            </div>
        </div>

        <div class="stat-card">
            <div style="display:flex;justify-content:space-between;align-items:start">
                <div>
                    <div class="label">Pendapatan ({{ $window }}d)</div>
                    <div class="value" style="font-size:24px">Rp {{ number_format($totalPaidRevenue, 0, ',', '.') }}</div>
                </div>
                <div class="icon" style="background:rgba(197,165,90,0.15);color:{{ $gold }}">
                    <x-icon name="gem" size="20" />
                </div>
            </div>
        </div>
    </div>

    {{-- ── Pricing table ────────────────────────────────────── --}}
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden;margin-bottom:24px">
        <div style="padding:16px 20px;border-bottom:1px solid #2a2a2a;display:flex;justify-content:space-between;align-items:center">
            <h3 style="font-size:15px;font-weight:600;display:flex;align-items:center;gap:8px">
                <x-icon name="sparkles" size="16" style="color:{{ $gold }}" />
                Performa & Rekomendasi per Paket
            </h3>
            <span style="font-size:11px;color:#666">{{ count($plans) }} paket</span>
        </div>

        <div style="overflow-x:auto">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Paket</th>
                        <th style="text-align:right">Harga Saat Ini</th>
                        <th style="text-align:right">Signups ({{ $window }}d)</th>
                        <th style="text-align:right">Konversi</th>
                        <th style="text-align:right">Churn 90d</th>
                        <th style="text-align:right">LTV</th>
                        <th style="text-align:right">Harga Direkomendasikan</th>
                        <th style="text-align:center">Confidence</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($plans as $p)
                        @php
                            $rec       = $recBySlug[$p['slug']] ?? null;
                            $delta     = $rec['delta_pct'] ?? 0;
                            $deltaCol  = $delta > 0.5  ? '#22c55e' : ($delta < -0.5 ? '#ef4444' : '#888');
                            $churnCol  = $p['churn_pct'] >= 30 ? '#ef4444' : ($p['churn_pct'] >= 15 ? '#eab308' : '#22c55e');
                            [$cColor, $cBg, $cLabel] = $confMeta[$rec['confidence'] ?? 'low'] ?? $confMeta['low'];
                        @endphp
                        <tr>
                            <td>
                                <div style="display:flex;align-items:center;gap:10px">
                                    @if(!$p['is_active'])
                                        <span style="width:8px;height:8px;border-radius:50%;background:#555"></span>
                                    @else
                                        <span style="width:8px;height:8px;border-radius:50%;background:{{ $gold }}"></span>
                                    @endif
                                    <div>
                                        <div style="font-weight:500;color:#fff">{{ $p['name'] }}</div>
                                        <div style="font-size:11px;color:#666">
                                            {{ strtolower($p['slug']) }} ·
                                            {{ $p['billing_cycle'] }} ·
                                            {{ $p['video_quality'] ?: '—' }}
                                        </div>
                                    </div>
                                    @if(!$p['is_active'])
                                        <span class="badge" style="background:rgba(107,114,128,0.2);color:#9ca3af">Inactive</span>
                                    @endif
                                </div>
                            </td>
                            <td style="text-align:right;font-weight:600;color:#fff">{{ $p['current_price_fmt'] }}</td>
                            <td style="text-align:right">
                                {{ number_format($p['subscriptions_in_window']) }}
                                <div style="font-size:10px;color:#666;margin-top:2px">{{ $p['paid_revenue_fmt'] }}</div>
                            </td>
                            <td style="text-align:right">{{ number_format($p['conversion_pct'], 2) }}%</td>
                            <td style="text-align:right;color:{{ $churnCol }}">
                                {{ number_format($p['churn_pct'], 2) }}%
                                <div style="font-size:10px;color:#666;margin-top:2px">n={{ $p['churn_sample_size'] }}</div>
                            </td>
                            <td style="text-align:right">
                                {{ $p['ltv_fmt'] }}
                                <div style="font-size:10px;color:#666;margin-top:2px">{{ $p['avg_lifetime_days'] }}d avg</div>
                            </td>
                            <td style="text-align:right">
                                @if($rec)
                                    <div style="font-weight:600;color:#fff">{{ $rec['recommended_price_fmt'] }}</div>
                                    <div style="font-size:11px;color:{{ $deltaCol }};margin-top:2px;font-weight:500">
                                        {{ $delta > 0 ? '+' : '' }}{{ number_format($delta, 1) }}%
                                    </div>
                                @else
                                    <span style="color:#555;font-size:12px">—</span>
                                @endif
                            </td>
                            <td style="text-align:center">
                                @if($rec)
                                    <span style="background:{{ $cBg }};color:{{ $cColor }};padding:3px 10px;border-radius:20px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.5px">
                                        {{ $cLabel }}
                                    </span>
                                @else
                                    <span style="color:#555;font-size:12px">—</span>
                                @endif
                            </td>
                        </tr>
                        @if($rec && !empty($rec['reason']))
                            <tr>
                                <td colspan="8" style="background:#0f0f0f;padding:0 16px 16px 16px">
                                    <div style="background:rgba(197,165,90,0.06);border:1px solid rgba(197,165,90,0.18);border-radius:8px;padding:12px 16px">
                                        <div style="font-size:10px;color:{{ $gold }};text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;font-weight:600;display:flex;align-items:center;gap:6px">
                                            <x-icon name="sparkles" size="11" /> Alasan AI · {{ $rec['plan_name'] }}
                                        </div>
                                        <p style="font-size:13px;color:#e5e5e5;line-height:1.55">{{ $rec['reason'] }}</p>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="8" style="text-align:center;color:#555;padding:32px">Belum ada paket langganan terdaftar.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ── Notes / methodology ─────────────────────────────── --}}
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:18px 22px;margin-bottom:24px">
        <h3 style="font-size:13px;font-weight:600;color:#aaa;text-transform:uppercase;letter-spacing:1px;margin-bottom:12px">Metodologi</h3>
        <ul style="list-style:none;padding:0;display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px;font-size:12px;color:#888;line-height:1.6">
            <li><strong style="color:{{ $gold }}">Konversi</strong> = signups paket / total signups berbayar dalam {{ $window }} hari (proxy karena tidak ada landing-page funnel).</li>
            <li><strong style="color:{{ $gold }}">Churn 90d</strong> = subscription yang dibatalkan dalam 90 hari setelah mulai, dibagi sample yang sudah berusia ≥ 90 hari atau sudah cancel.</li>
            <li><strong style="color:{{ $gold }}">LTV</strong> = avg(amount) × max(1, avg_lifetime_days / billing_cycle_days). Paket gratis → LTV = 0.</li>
            <li><strong style="color:{{ $gold }}">Confidence</strong> tinggi hanya jika sample langganan paket ≥ 30 — jika rendah, perlakukan rekomendasi sebagai hipotesis.</li>
        </ul>
    </div>

    <div style="font-size:11px;color:#555;text-align:center">
        Dibuat: {{ $report['generated_at'] ?? '—' }} · Cache 24 jam · Tambahkan <code style="color:{{ $gold }}">?refresh=1</code> untuk paksa hitung ulang.
    </div>

</x-admin.layout>
