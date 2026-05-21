<x-admin.layout title="Referrals Report">

    {{-- Header --}}
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px">
        <div>
            <h2 style="font-size:22px;font-weight:700;color:#fff">Referrals Report</h2>
            <p style="font-size:13px;color:#777;margin-top:2px">
                Snapshot funnel program ajak-teman + papan peringkat referrer terbaik.
            </p>
        </div>
        <div style="display:flex;gap:8px">
            <a href="{{ route('admin.referrals.index') }}" class="btn btn-ghost btn-sm">
                <x-icon name="user" :size="14" /> Ledger
            </a>
        </div>
    </div>

    {{-- KPI cards --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;margin-bottom:22px">
        <div class="stat-card">
            <div class="label">Total Konversi</div>
            <div class="value">{{ number_format($totals['total'] ?? 0) }}</div>
        </div>
        <div class="stat-card">
            <div class="label">Qualify Rate</div>
            <div class="value" style="color:#3b82f6">{{ $conversionRate['qualify_rate'] ?? 0 }}%</div>
        </div>
        <div class="stat-card">
            <div class="label">Reward Rate</div>
            <div class="value" style="color:#22c55e">{{ $conversionRate['reward_rate'] ?? 0 }}%</div>
        </div>
        <div class="stat-card">
            <div class="label">Total Koin Dibayar</div>
            <div class="value" style="color:#C5A55A">{{ number_format($coinsPaid ?? 0) }}</div>
        </div>
        <div class="stat-card">
            <div class="label">Total XP Dibayar</div>
            <div class="value" style="color:#3b82f6">{{ number_format($xpPaid ?? 0) }}</div>
        </div>
    </div>

    {{-- Funnel snapshot --}}
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:20px 24px;margin-bottom:22px">
        <h3 style="font-size:15px;font-weight:600;color:#fff;margin-bottom:14px">Funnel snapshot</h3>
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;text-align:center">
            <div style="padding:12px;background:#0f0f0f;border-radius:8px">
                <div style="font-size:11px;color:#888;text-transform:uppercase;letter-spacing:0.5px">Pending</div>
                <div style="font-size:22px;font-weight:700;color:#888;margin-top:4px;font-family:'Outfit'">
                    {{ number_format($totals['pending'] ?? 0) }}
                </div>
            </div>
            <div style="padding:12px;background:#0f0f0f;border-radius:8px">
                <div style="font-size:11px;color:#3b82f6;text-transform:uppercase;letter-spacing:0.5px">Qualified</div>
                <div style="font-size:22px;font-weight:700;color:#3b82f6;margin-top:4px;font-family:'Outfit'">
                    {{ number_format($totals['qualified'] ?? 0) }}
                </div>
            </div>
            <div style="padding:12px;background:#0f0f0f;border-radius:8px">
                <div style="font-size:11px;color:#22c55e;text-transform:uppercase;letter-spacing:0.5px">Rewarded</div>
                <div style="font-size:22px;font-weight:700;color:#22c55e;margin-top:4px;font-family:'Outfit'">
                    {{ number_format($totals['rewarded'] ?? 0) }}
                </div>
            </div>
            <div style="padding:12px;background:#0f0f0f;border-radius:8px">
                <div style="font-size:11px;color:#ef4444;text-transform:uppercase;letter-spacing:0.5px">Expired</div>
                <div style="font-size:22px;font-weight:700;color:#ef4444;margin-top:4px;font-family:'Outfit'">
                    {{ number_format($totals['expired'] ?? 0) }}
                </div>
            </div>
        </div>
    </div>

    {{-- Top referrers leaderboard --}}
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden">
        <div style="padding:14px 20px;border-bottom:1px solid #2a2a2a;display:flex;align-items:center;justify-content:space-between">
            <h3 style="font-size:15px;font-weight:600;color:#fff">Top Referrers</h3>
            <span style="font-size:11px;color:#666">Top 10 berdasarkan konversi seumur hidup</span>
        </div>

        <table class="admin-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>User</th>
                    <th>Kode</th>
                    <th style="text-align:right">Konversi</th>
                    <th style="text-align:right">Koin Diperoleh</th>
                </tr>
            </thead>
            <tbody>
                @forelse($topReferrers as $i => $u)
                    <tr>
                        <td style="color:#666;font-weight:600">{{ $i + 1 }}</td>
                        <td>
                            <div style="color:#e5e5e5;font-weight:500">{{ $u->name ?? '—' }}</div>
                            <div style="font-size:11px;color:#777;margin-top:2px">{{ $u->email }}</div>
                        </td>
                        <td>
                            <code style="background:#0f0f0f;border:1px solid #2a2a2a;color:#C5A55A;padding:3px 8px;border-radius:4px;font-family:'Outfit',monospace;font-size:12px">
                                {{ $u->referral_code ?? '—' }}
                            </code>
                        </td>
                        <td style="text-align:right;color:#fff;font-weight:600">{{ number_format((int) $u->referrals_count) }}</td>
                        <td style="text-align:right;color:#C5A55A;font-weight:600">{{ number_format((int) $u->referrals_rewards_earned) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" style="text-align:center;padding:32px;color:#666">
                            Belum ada referrer dengan konversi.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

</x-admin.layout>
