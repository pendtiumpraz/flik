<x-admin.layout title="Promo Code Report">

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">
        <div>
            <h2 style="font-size:22px;font-weight:700;color:#fff">Promo Code Report</h2>
            <p style="font-size:13px;color:#777;margin-top:2px">Performance & redemption analytics.</p>
        </div>
        <a href="{{ route('admin.promo-codes.index') }}" class="btn btn-ghost btn-sm">
            ← Back to Codes
        </a>
    </div>

    {{-- Top-line KPIs ──────────────────────────────────────────────── --}}
    <div class="grid-stats" style="margin-bottom:24px">
        <div class="stat-card">
            <div class="label">Total Redemptions</div>
            <div class="value">{{ number_format($totalRedemptions) }}</div>
        </div>
        <div class="stat-card">
            <div class="label">Total Discount Given</div>
            <div class="value" style="color:#C5A55A">Rp {{ number_format($totalDiscount, 0, ',', '.') }}</div>
        </div>
        <div class="stat-card">
            <div class="label">Paid Subs (30d)</div>
            <div class="value">{{ number_format($paid30) }}</div>
        </div>
        <div class="stat-card">
            <div class="label">Promo-attributed (30d)</div>
            <div class="value">
                {{ number_format($attributed30) }}
                <span style="font-size:14px;color:#22c55e;margin-left:6px">({{ $upliftPct }}%)</span>
            </div>
        </div>
    </div>

    {{-- 14-day sparkline ───────────────────────────────────────────── --}}
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:20px;margin-bottom:24px">
        <h3 style="font-size:14px;color:#C5A55A;text-transform:uppercase;letter-spacing:1px;margin-bottom:14px">
            Redemptions — Last 14 Days
        </h3>
        @php
            $maxCount = max(1, max(array_column($sparkline, 'count')));
        @endphp
        <div style="display:flex;align-items:end;gap:6px;height:120px">
            @foreach($sparkline as $point)
                @php
                    $height = ($point['count'] / $maxCount) * 100;
                @endphp
                <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:4px"
                    title="{{ $point['day'] }} — {{ $point['count'] }} redemptions, Rp {{ number_format($point['total'], 0, ',', '.') }} discount">
                    <div style="width:100%;background:linear-gradient(180deg,#E8D5A3,#C5A55A);border-radius:3px 3px 0 0;min-height:2px;height:{{ $height }}%"></div>
                    <div style="font-size:10px;color:#666">{{ \Illuminate\Support\Carbon::parse($point['day'])->format('d/m') }}</div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Top Codes Table ────────────────────────────────────────────── --}}
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden">
        <div style="padding:16px 20px;border-bottom:1px solid #2a2a2a">
            <h3 style="font-size:14px;color:#C5A55A;text-transform:uppercase;letter-spacing:1px">Top 10 Redeemed Codes</h3>
        </div>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Campaign</th>
                    <th>Discount</th>
                    <th style="text-align:right">Redemptions</th>
                    <th style="text-align:right">Total Discount</th>
                    <th style="text-align:right">Avg / Redemption</th>
                </tr>
            </thead>
            <tbody>
                @forelse($topCodes as $row)
                    @php
                        $count = (int) ($row->redemption_count ?? 0);
                        $total = (float) ($row->total_discount_idr ?? 0);
                        $avg = $count > 0 ? $total / $count : 0;
                    @endphp
                    <tr>
                        <td>
                            <code style="background:#0f0f0f;border:1px solid #2a2a2a;color:#C5A55A;padding:3px 8px;border-radius:4px;font-family:'Outfit',monospace;font-size:13px">{{ $row->code }}</code>
                        </td>
                        <td style="color:#e5e5e5">{{ $row->name }}</td>
                        <td><span class="badge badge-gold">{{ $row->discount_label }}</span></td>
                        <td style="text-align:right;color:#e5e5e5">{{ number_format($count) }}</td>
                        <td style="text-align:right;color:#C5A55A">Rp {{ number_format($total, 0, ',', '.') }}</td>
                        <td style="text-align:right;color:#999">Rp {{ number_format($avg, 0, ',', '.') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" style="text-align:center;color:#666;padding:40px 0">
                            Belum ada redemption tercatat.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-admin.layout>
