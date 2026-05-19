<x-admin.layout title="Promo Codes">

    {{-- Header row: title + actions ────────────────────────────────────── --}}
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px">
        <div>
            <h2 style="font-size:22px;font-weight:700;color:#fff">Promo Codes</h2>
            <p style="font-size:13px;color:#777;margin-top:2px">Kelola kode diskon untuk subscription checkout.</p>
        </div>
        <div style="display:flex;gap:8px">
            <a href="{{ route('admin.promo-codes.report') }}" class="btn btn-ghost btn-sm">
                <x-icon name="lightning" size="14" /> Report
            </a>
            <a href="{{ route('admin.promo-codes.create') }}" class="btn btn-gold btn-sm">
                <x-icon name="plus" size="14" /> New Code
            </a>
            <button type="button" class="btn btn-ghost btn-sm" x-data @click="$dispatch('open-bulk')">
                <x-icon name="sparkles" size="14" /> Bulk Generate
            </button>
        </div>
    </div>

    {{-- Filters ──────────────────────────────────────────────────────── --}}
    <form method="GET" action="{{ url()->current() }}"
        style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:16px 20px;margin-bottom:20px">
        <div style="display:grid;grid-template-columns:1fr 200px auto;gap:12px;align-items:end">
            <div class="form-group" style="margin:0">
                <label>Search</label>
                <input type="text" name="q" class="form-input" value="{{ request('q') }}"
                    placeholder="Cari berdasarkan code atau nama campaign…">
            </div>
            <div class="form-group" style="margin:0">
                <label>Status</label>
                <select name="status" class="form-input">
                    <option value="">— Semua —</option>
                    <option value="active" @selected(request('status')==='active')>Active</option>
                    <option value="inactive" @selected(request('status')==='inactive')>Inactive</option>
                    <option value="expired" @selected(request('status')==='expired')>Expired</option>
                </select>
            </div>
            <button type="submit" class="btn btn-gold btn-sm">
                <x-icon name="search" size="14" /> Apply
            </button>
        </div>
    </form>

    {{-- Table ────────────────────────────────────────────────────────── --}}
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden"
         x-data="promoIndex()">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Campaign</th>
                    <th>Discount</th>
                    <th>Usage</th>
                    <th>Window</th>
                    <th>Status</th>
                    <th style="text-align:right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($codes as $code)
                    @php
                        $used = $code->redemptions_count ?? 0;
                        $cap  = $code->max_uses;
                        $pct  = $cap ? min(100, ($used / max(1, $cap)) * 100) : null;
                        $expired = $code->expires_at && $code->expires_at->isPast();
                        $upcoming = $code->starts_at && $code->starts_at->isFuture();
                    @endphp
                    <tr>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px">
                                <code style="background:#0f0f0f;border:1px solid #2a2a2a;color:#C5A55A;padding:3px 8px;border-radius:4px;font-family:'Outfit',monospace;font-size:13px;letter-spacing:0.5px">
                                    {{ $code->code }}
                                </code>
                                <button type="button" class="btn btn-ghost btn-sm"
                                    style="padding:4px 6px"
                                    title="Copy to clipboard"
                                    @click="copy('{{ $code->code }}', $event)">
                                    <x-icon name="download" size="13" />
                                </button>
                            </div>
                        </td>
                        <td>
                            <div style="color:#e5e5e5">{{ $code->name }}</div>
                            @if($code->description)
                                <div style="font-size:11px;color:#666;margin-top:2px;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                    {{ $code->description }}
                                </div>
                            @endif
                        </td>
                        <td>
                            <span class="badge badge-gold">{{ $code->discount_label }}</span>
                            <div style="font-size:11px;color:#666;margin-top:3px;text-transform:capitalize">
                                {{ str_replace('_', ' ', $code->discount_type) }}
                            </div>
                        </td>
                        <td style="min-width:140px">
                            <div style="display:flex;justify-content:space-between;font-size:12px;color:#999;margin-bottom:4px">
                                <span>{{ $used }} / {{ $cap ?? '∞' }}</span>
                                @if($cap)
                                    <span style="color:#666">{{ round($pct) }}%</span>
                                @endif
                            </div>
                            @if($cap)
                                <div style="height:6px;background:#0f0f0f;border-radius:999px;overflow:hidden">
                                    <div style="height:100%;width:{{ $pct }}%;background:linear-gradient(90deg,#C5A55A,#E8D5A3);transition:width 0.3s"></div>
                                </div>
                            @else
                                <div style="height:6px;background:#0f0f0f;border-radius:999px;overflow:hidden">
                                    <div style="height:100%;width:100%;background:repeating-linear-gradient(45deg,#2a2a2a,#2a2a2a 4px,#1a1a1a 4px,#1a1a1a 8px)"></div>
                                </div>
                            @endif
                        </td>
                        <td style="font-size:12px;color:#999">
                            @if($code->starts_at)
                                <div>{{ $code->starts_at->format('d M Y') }}</div>
                            @else
                                <div style="color:#555">—</div>
                            @endif
                            <div style="color:#666">
                                → {{ $code->expires_at ? $code->expires_at->format('d M Y') : 'no expiry' }}
                            </div>
                        </td>
                        <td>
                            @if(! $code->is_active)
                                <span class="badge" style="background:rgba(160,160,160,0.15);color:#999">Inactive</span>
                            @elseif($expired)
                                <span class="badge" style="background:rgba(239,68,68,0.15);color:#fca5a5">Expired</span>
                            @elseif($upcoming)
                                <span class="badge badge-blue">Scheduled</span>
                            @else
                                <span class="badge badge-green">Active</span>
                            @endif
                        </td>
                        <td style="text-align:right">
                            <a href="{{ route('admin.promo-codes.edit', $code) }}" class="btn btn-ghost btn-sm" title="Edit">
                                <x-icon name="cog" size="13" />
                            </a>
                            <form action="{{ route('admin.promo-codes.destroy', $code) }}" method="POST" style="display:inline"
                                onsubmit="return confirm('Hapus promo {{ $code->code }}? Riwayat redemption akan ikut terhapus.')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-sm" title="Delete">
                                    <x-icon name="x" size="13" />
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" style="text-align:center;color:#666;padding:40px 0">
                            <x-icon name="gift" size="32" class="text-gray-600" />
                            <div style="margin-top:8px">Belum ada promo code. Buat yang pertama lewat tombol di atas.</div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if($codes->hasPages())
            <div style="padding:14px 20px;border-top:1px solid #2a2a2a">
                {{ $codes->links() }}
            </div>
        @endif

        {{-- Bulk Generate modal — Alpine, dispatched from header button --}}
        <div x-show="bulkOpen" x-cloak @keydown.escape.window="bulkOpen=false"
            @open-bulk.window="bulkOpen=true"
            style="position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:60;display:flex;align-items:center;justify-content:center;padding:20px">
            <div @click.outside="bulkOpen=false"
                style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;width:100%;max-width:560px;padding:24px;max-height:90vh;overflow-y:auto">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
                    <h3 style="font-size:18px;color:#C5A55A">Bulk Generate Promo Codes</h3>
                    <button type="button" @click="bulkOpen=false" style="background:none;border:none;color:#777;cursor:pointer;font-size:20px">×</button>
                </div>

                <form action="{{ route('admin.promo-codes.bulk-generate') }}" method="POST">
                    @csrf
                    <div class="form-group">
                        <label>Quantity *</label>
                        <input type="number" name="count" min="1" max="500" value="10" class="form-input" required>
                        <small style="color:#666;font-size:11px">Maks 500 per batch.</small>
                    </div>
                    <div class="form-group">
                        <label>Prefix (optional)</label>
                        <input type="text" name="prefix" maxlength="10" pattern="[A-Za-z0-9]*" class="form-input"
                            placeholder="e.g. INFLU → INFLU-XXXXXXXX">
                    </div>
                    <div class="form-group">
                        <label>Campaign Name *</label>
                        <input type="text" name="name" maxlength="120" class="form-input" required
                            placeholder="e.g. Q3 Influencer Push 2026">
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                        <div class="form-group">
                            <label>Discount Type *</label>
                            <select name="discount_type" class="form-input" required>
                                <option value="percentage">Percentage</option>
                                <option value="fixed">Fixed (IDR)</option>
                                <option value="free_trial_days">Free Trial Days</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Value *</label>
                            <input type="number" step="0.01" name="discount_value" min="0" class="form-input" required>
                        </div>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                        <div class="form-group">
                            <label>Starts at</label>
                            <input type="datetime-local" name="starts_at" class="form-input">
                        </div>
                        <div class="form-group">
                            <label>Expires at</label>
                            <input type="datetime-local" name="expires_at" class="form-input">
                        </div>
                    </div>
                    <div class="form-group">
                        <label style="display:flex;gap:8px;align-items:center;cursor:pointer">
                            <input type="checkbox" name="is_first_time_only" value="1">
                            <span>First-time customers only</span>
                        </label>
                    </div>

                    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:20px">
                        <button type="button" @click="bulkOpen=false" class="btn btn-ghost btn-sm">Cancel</button>
                        <button type="submit" class="btn btn-gold btn-sm">
                            <x-icon name="sparkles" size="14" /> Generate
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
        function promoIndex() {
            return {
                bulkOpen: false,
                copy(text, e) {
                    navigator.clipboard.writeText(text).then(() => {
                        const btn = e.currentTarget;
                        const original = btn.innerHTML;
                        btn.innerHTML = '<span style="color:#22c55e;font-size:11px">✓</span>';
                        setTimeout(() => btn.innerHTML = original, 1200);
                    });
                }
            }
        }
    </script>
    @endpush
</x-admin.layout>
