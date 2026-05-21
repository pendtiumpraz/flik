<x-admin.layout title="Gift Subscriptions">

    {{-- Header --}}
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px">
        <div>
            <h2 style="font-size:22px;font-weight:700;color:#fff">Gift Subscriptions</h2>
            <p style="font-size:13px;color:#777;margin-top:2px">
                Inventaris hadiah berlangganan yang dibeli pengguna untuk orang lain.
            </p>
        </div>
        <div style="display:flex;gap:8px">
            <a href="{{ route('admin.referrals.index') }}" class="btn btn-ghost btn-sm">
                <x-icon name="user" :size="14" /> Referrals
            </a>
        </div>
    </div>

    {{-- Stat cards --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;margin-bottom:22px">
        <div class="stat-card">
            <div class="label">Total Gifts</div>
            <div class="value">{{ number_format($totals['all'] ?? 0) }}</div>
        </div>
        <div class="stat-card">
            <div class="label">Sudah Bayar (Belum Ditukar)</div>
            <div class="value" style="color:#3b82f6">{{ number_format($totals['paid'] ?? 0) }}</div>
        </div>
        <div class="stat-card">
            <div class="label">Sudah Ditukar</div>
            <div class="value" style="color:#22c55e">{{ number_format($totals['redeemed'] ?? 0) }}</div>
        </div>
        <div class="stat-card">
            <div class="label">Belum Bayar</div>
            <div class="value" style="color:#888">{{ number_format($totals['unpaid'] ?? 0) }}</div>
        </div>
        <div class="stat-card">
            <div class="label">Total Revenue</div>
            <div class="value" style="color:#C5A55A">Rp {{ number_format((int) ($totals['revenue'] ?? 0), 0, ',', '.') }}</div>
        </div>
    </div>

    {{-- Filters --}}
    <form method="GET" action="{{ url()->current() }}"
          style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:16px 20px;margin-bottom:20px">
        <div style="display:grid;grid-template-columns:1fr 200px auto;gap:12px;align-items:end">
            <div class="form-group" style="margin:0">
                <label>Cari</label>
                <input type="text" name="q" class="form-input" value="{{ request('q') }}"
                       placeholder="Kode, email pembeli/penerima, order id…">
            </div>
            <div class="form-group" style="margin:0">
                <label>Status</label>
                <select name="status" class="form-input">
                    <option value="">— Semua —</option>
                    <option value="unpaid"   @selected(request('status')==='unpaid')>Belum bayar</option>
                    <option value="paid"     @selected(request('status')==='paid')>Sudah bayar (belum ditukar)</option>
                    <option value="redeemed" @selected(request('status')==='redeemed')>Sudah ditukar</option>
                    <option value="expired"  @selected(request('status')==='expired')>Kedaluwarsa</option>
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
                    <th>Code</th>
                    <th>Plan</th>
                    <th>Pembeli</th>
                    <th>Penerima</th>
                    <th>Harga</th>
                    <th>Status</th>
                    <th>Ditukar oleh</th>
                    <th style="text-align:right">Order ID</th>
                </tr>
            </thead>
            <tbody>
                @forelse($gifts as $g)
                    @php
                        if (!$g->paid_at) {
                            $statusLabel = 'Unpaid';
                            $statusColor = '#888';
                        } elseif ($g->redeemed_at) {
                            $statusLabel = 'Redeemed';
                            $statusColor = '#22c55e';
                        } elseif ($g->expires_at && $g->expires_at->isPast()) {
                            $statusLabel = 'Expired';
                            $statusColor = '#ef4444';
                        } else {
                            $statusLabel = 'Paid';
                            $statusColor = '#3b82f6';
                        }
                    @endphp
                    <tr>
                        <td>
                            <code style="background:#0f0f0f;border:1px solid #2a2a2a;color:#C5A55A;padding:3px 8px;border-radius:4px;font-family:'Outfit',monospace;font-size:12px;letter-spacing:0.5px">
                                {{ $g->displayCode() }}
                            </code>
                        </td>
                        <td>
                            <span style="color:#fff;font-weight:500">{{ $g->plan->name ?? '—' }}</span>
                            <div style="font-size:11px;color:#777;margin-top:2px">{{ (int) ($g->duration_days ?? 0) }} hari</div>
                        </td>
                        <td>
                            <div style="color:#e5e5e5">{{ $g->purchaser->name ?? '—' }}</div>
                            <div style="font-size:11px;color:#777;margin-top:2px">{{ $g->purchaser_email }}</div>
                        </td>
                        <td>
                            <div style="color:#e5e5e5">{{ $g->recipient_name ?? '—' }}</div>
                            <div style="font-size:11px;color:#777;margin-top:2px">{{ $g->recipient_email ?? '—' }}</div>
                        </td>
                        <td style="color:#C5A55A;font-weight:500">
                            Rp {{ number_format((int) ($g->amount_paid_idr ?? 0), 0, ',', '.') }}
                        </td>
                        <td>
                            <span style="display:inline-block;padding:3px 8px;border-radius:12px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;background:{{ $statusColor }}22;color:{{ $statusColor }};border:1px solid {{ $statusColor }}55">
                                {{ $statusLabel }}
                            </span>
                            @if($g->paid_at)
                                <div style="font-size:11px;color:#777;margin-top:3px">{{ $g->paid_at->translatedFormat('d M Y') }}</div>
                            @endif
                        </td>
                        <td>
                            @if($g->redeemer)
                                <div style="color:#e5e5e5">{{ $g->redeemer->name }}</div>
                                <div style="font-size:11px;color:#777;margin-top:2px">{{ $g->redeemed_at?->translatedFormat('d M Y') }}</div>
                            @else
                                <span style="color:#555">—</span>
                            @endif
                        </td>
                        <td style="text-align:right">
                            <code style="font-family:'Outfit',monospace;font-size:11px;color:#777">{{ $g->midtrans_order_id ?? '—' }}</code>
                            {{-- Refund flow intentionally not exposed inline — issue refunds via the
                                 Midtrans dashboard and toggle subscription status via tinker.
                                 See docs/audit/11-payment.md (refund flow does not exist). --}}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" style="text-align:center;padding:32px;color:#666">
                            Tidak ada gift subscription yang cocok dengan filter.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($gifts->hasPages())
        <div style="margin-top:16px">
            {{ $gifts->links() }}
        </div>
    @endif

</x-admin.layout>
