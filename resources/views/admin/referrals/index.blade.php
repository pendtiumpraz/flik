<x-admin.layout title="Referrals">

    {{-- Header --}}
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px">
        <div>
            <h2 style="font-size:22px;font-weight:700;color:#fff">Referrals</h2>
            <p style="font-size:13px;color:#777;margin-top:2px">
                Ledger konversi program ajak-teman. Status: pending → qualified → rewarded.
            </p>
        </div>
        <div style="display:flex;gap:8px">
            <a href="{{ route('admin.referrals.report') }}" class="btn btn-ghost btn-sm">
                <x-icon name="lightning" :size="14" /> Report
            </a>
            <a href="{{ route('admin.gifts.index') }}" class="btn btn-ghost btn-sm">
                <x-icon name="gift" :size="14" /> Gifts
            </a>
        </div>
    </div>

    {{-- Funnel summary --}}
    @php
        $total = (int) ($totals['total'] ?? 0);
        $pct = fn($n) => $total > 0 ? round(($n / $total) * 100, 1) : 0.0;
    @endphp

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;margin-bottom:22px">
        <div class="stat-card">
            <div class="label">Total Konversi</div>
            <div class="value">{{ number_format($total) }}</div>
        </div>
        <div class="stat-card">
            <div class="label">Pending</div>
            <div class="value" style="color:#888">{{ number_format($totals['pending'] ?? 0) }}</div>
            <div style="font-size:11px;color:#666;margin-top:4px">{{ $pct($totals['pending'] ?? 0) }}%</div>
        </div>
        <div class="stat-card">
            <div class="label">Qualified</div>
            <div class="value" style="color:#3b82f6">{{ number_format($totals['qualified'] ?? 0) }}</div>
            <div style="font-size:11px;color:#666;margin-top:4px">{{ $pct($totals['qualified'] ?? 0) }}%</div>
        </div>
        <div class="stat-card">
            <div class="label">Rewarded</div>
            <div class="value" style="color:#22c55e">{{ number_format($totals['rewarded'] ?? 0) }}</div>
            <div style="font-size:11px;color:#666;margin-top:4px">{{ $pct($totals['rewarded'] ?? 0) }}%</div>
        </div>
        <div class="stat-card">
            <div class="label">Expired</div>
            <div class="value" style="color:#ef4444">{{ number_format($totals['expired'] ?? 0) }}</div>
            <div style="font-size:11px;color:#666;margin-top:4px">{{ $pct($totals['expired'] ?? 0) }}%</div>
        </div>
    </div>

    {{-- Filters --}}
    <form method="GET" action="{{ url()->current() }}"
          style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:16px 20px;margin-bottom:20px">
        <div style="display:grid;grid-template-columns:1fr 200px auto;gap:12px;align-items:end">
            <div class="form-group" style="margin:0">
                <label>Cari (referrer atau referred)</label>
                <input type="text" name="q" class="form-input" value="{{ request('q') }}"
                       placeholder="Nama, email, atau referral_code…">
            </div>
            <div class="form-group" style="margin:0">
                <label>Status</label>
                <select name="status" class="form-input">
                    <option value="">— Semua —</option>
                    @foreach($statuses as $s)
                        <option value="{{ $s }}" @selected(request('status')===$s)>{{ ucfirst($s) }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn btn-gold btn-sm">
                <x-icon name="search" :size="14" /> Apply
            </button>
        </div>
    </form>

    {{-- Table --}}
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Referrer</th>
                    <th>Referred</th>
                    <th>Status</th>
                    <th>Qualified</th>
                    <th>Rewarded</th>
                    <th>Subscription</th>
                    <th style="text-align:right">Reward</th>
                </tr>
            </thead>
            <tbody>
                @forelse($conversions as $c)
                    @php
                        $label = method_exists($c, 'statusLabel') ? $c->statusLabel() : ucfirst((string) $c->status);
                        $color = method_exists($c, 'statusColor') ? $c->statusColor() : '#888';
                    @endphp
                    <tr>
                        <td>
                            <div style="color:#e5e5e5;font-weight:500">{{ $c->referrer->name ?? '—' }}</div>
                            <div style="font-size:11px;color:#777;margin-top:2px">
                                {{ $c->referrer->email ?? '—' }}
                                @if($c->referrer?->referral_code)
                                    · <code style="color:#C5A55A">{{ $c->referrer->referral_code }}</code>
                                @endif
                            </div>
                        </td>
                        <td>
                            <div style="color:#e5e5e5">{{ $c->referred->name ?? '—' }}</div>
                            <div style="font-size:11px;color:#777;margin-top:2px">{{ $c->referred->email ?? '—' }}</div>
                        </td>
                        <td>
                            <span style="display:inline-block;padding:3px 8px;border-radius:12px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;background:{{ $color }}22;color:{{ $color }};border:1px solid {{ $color }}55">
                                {{ $label }}
                            </span>
                            <div style="font-size:11px;color:#777;margin-top:3px">{{ $c->created_at?->translatedFormat('d M Y') }}</div>
                        </td>
                        <td style="font-size:12px;color:#aaa">
                            {{ $c->qualified_at?->translatedFormat('d M Y') ?? '—' }}
                        </td>
                        <td style="font-size:12px;color:#aaa">
                            {{ $c->rewarded_at?->translatedFormat('d M Y') ?? '—' }}
                        </td>
                        <td style="font-size:12px;color:#aaa">
                            @if($c->subscription)
                                #{{ $c->subscription->id }}
                                <span style="color:#777">·</span>
                                {{ $c->subscription->status }}
                                <div style="color:#C5A55A;font-size:11px;margin-top:2px">
                                    Rp {{ number_format((int) ($c->subscription->amount ?? 0), 0, ',', '.') }}
                                </div>
                            @else
                                <span style="color:#555">—</span>
                            @endif
                        </td>
                        <td style="text-align:right">
                            @php
                                $coins = (int) ($c->referrer_reward_coins ?? 0) + (int) ($c->referred_reward_coins ?? 0);
                                $xp    = (int) ($c->referrer_reward_xp ?? 0) + (int) ($c->referred_reward_xp ?? 0);
                            @endphp
                            @if($coins > 0)
                                <div style="color:#C5A55A;font-weight:500;font-size:12px">+{{ number_format($coins) }} koin</div>
                            @endif
                            @if($xp > 0)
                                <div style="color:#3b82f6;font-size:11px;margin-top:2px">+{{ number_format($xp) }} XP</div>
                            @endif
                            @if($coins === 0 && $xp === 0)
                                <span style="color:#555">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" style="text-align:center;padding:32px;color:#666">
                            Tidak ada konversi referral yang cocok dengan filter.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($conversions->hasPages())
        <div style="margin-top:16px">
            {{ $conversions->links() }}
        </div>
    @endif

</x-admin.layout>
