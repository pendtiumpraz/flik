<x-admin.layout title="WAF Banned IPs">

    {{-- ── Status header ───────────────────────────────────────── --}}
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:18px 22px;margin-bottom:20px">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap">
            <div>
                <h2 style="font-size:18px;font-weight:600;color:#fff;margin-bottom:6px">
                    <x-icon name="shield" size="18" /> WAF-lite
                </h2>
                <div style="color:#888;font-size:13px">
                    Signature-based request firewall. Blocks path traversal, SQLi, XSS, RCE, LFI/RFI, webshells.
                </div>
            </div>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                <span style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;font-size:12px;letter-spacing:0.4px;border:1px solid {{ $enabled ? '#16a34a' : '#6b7280' }};background:{{ $enabled ? 'rgba(22,163,74,0.15)' : '#1f2937' }};color:{{ $enabled ? '#86efac' : '#9ca3af' }}">
                    <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:{{ $enabled ? '#22c55e' : '#6b7280' }}"></span>
                    {{ $enabled ? 'Enabled' : 'Disabled' }}
                </span>
                <span style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;font-size:12px;letter-spacing:0.4px;border:1px solid {{ $mode === 'block' ? '#ef4444' : '#f59e0b' }};background:{{ $mode === 'block' ? 'rgba(239,68,68,0.12)' : 'rgba(245,158,11,0.12)' }};color:{{ $mode === 'block' ? '#fca5a5' : '#fcd34d' }}">
                    Mode: {{ strtoupper($mode) }}
                </span>
                <span style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;font-size:12px;letter-spacing:0.4px;border:1px solid #3a3a3a;background:#0f0f0f;color:#bbb">
                    Threshold: {{ $threshold }} hits / 5min &middot; Ban: {{ $banMinutes }}min
                </span>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div style="background:rgba(22,163,74,0.15);border:1px solid #16a34a;color:#86efac;padding:12px 16px;border-radius:8px;margin-bottom:16px">
            {{ session('success') }}
        </div>
    @endif
    @if(session('error'))
        <div style="background:rgba(239,68,68,0.15);border:1px solid #ef4444;color:#fca5a5;padding:12px 16px;border-radius:8px;margin-bottom:16px">
            {{ session('error') }}
        </div>
    @endif

    {{-- ── Currently banned IPs ────────────────────────────────── --}}
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden;margin-bottom:24px">
        <div style="padding:16px 20px;border-bottom:1px solid #2a2a2a;display:flex;justify-content:space-between;align-items:center">
            <h3 style="font-size:15px;font-weight:600">
                Currently Banned IPs
                <span style="font-size:12px;color:#777;font-weight:400;margin-left:6px">
                    ({{ count($banned) }} active)
                </span>
            </h3>
            {{-- Manual unban (paste an IP that doesn't show below — useful
                 if the list is sourced from audit-log fallback and has
                 stale entries already evicted from cache). --}}
            <form method="POST" action="{{ route('admin.security.waf.unban') }}" style="display:flex;gap:8px;align-items:center">
                @csrf
                <input type="text" name="ip" placeholder="Enter IP to unban"
                    class="form-input"
                    style="height:32px;font-size:12px;padding:4px 10px;width:180px"
                    pattern="[0-9a-fA-F\.:]+"
                    title="IPv4 or IPv6 address">
                <button type="submit" class="btn btn-ghost btn-sm">
                    <x-icon name="check" size="14" /> Unban
                </button>
            </form>
        </div>

        <div style="overflow-x:auto">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th style="width:200px">IP Address</th>
                        <th style="width:180px">Banned At</th>
                        <th style="width:140px">Expires In</th>
                        <th style="width:120px;text-align:right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($banned as $row)
                        <tr>
                            <td style="font-family:monospace;color:#fca5a5;font-weight:600">
                                <x-icon name="shield" size="12" /> {{ $row['ip'] }}
                            </td>
                            <td style="color:#bbb;font-size:12px">
                                {{ $row['banned_at']
                                    ? \Illuminate\Support\Carbon::parse($row['banned_at'])->diffForHumans()
                                    : '—' }}
                            </td>
                            <td style="color:#bbb;font-size:12px">
                                @if($row['expires_in'] !== null)
                                    {{ floor($row['expires_in'] / 60) }}m {{ $row['expires_in'] % 60 }}s
                                @else
                                    <span style="color:#666" title="TTL unavailable on non-Redis cache drivers">unknown</span>
                                @endif
                            </td>
                            <td style="text-align:right">
                                <form method="POST" action="{{ route('admin.security.waf.unban') }}" style="display:inline">
                                    @csrf
                                    <input type="hidden" name="ip" value="{{ $row['ip'] }}">
                                    <button type="submit" class="btn btn-ghost btn-sm"
                                        onclick="return confirm('Unban {{ $row['ip'] }}?')">
                                        Unban
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" style="text-align:center;padding:32px;color:#666">
                                <x-icon name="shield" size="20" />
                                <div style="margin-top:8px">No IPs are currently banned.</div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ── Recent ban escalations ──────────────────────────────── --}}
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden;margin-bottom:24px">
        <div style="padding:16px 20px;border-bottom:1px solid #2a2a2a">
            <h3 style="font-size:15px;font-weight:600">
                Recent Ban Escalations
                <span style="font-size:12px;color:#777;font-weight:400;margin-left:6px">
                    (last 20)
                </span>
            </h3>
        </div>
        <div style="overflow-x:auto">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th style="width:160px">When</th>
                        <th style="width:160px">IP</th>
                        <th>Hits / Threshold</th>
                        <th>Ban Duration</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recentBans as $ban)
                        <tr>
                            <td style="color:#bbb;font-size:12px">
                                {{ $ban->created_at?->diffForHumans() }}
                                <div style="color:#666;font-size:10px">
                                    {{ $ban->created_at?->format('Y-m-d H:i:s') }}
                                </div>
                            </td>
                            <td style="font-family:monospace;color:#fca5a5">{{ $ban->client_ip ?? '—' }}</td>
                            <td>
                                {{ data_get($ban->meta, 'hits', '?') }} / {{ data_get($ban->meta, 'threshold', '?') }}
                            </td>
                            <td>{{ data_get($ban->meta, 'ban_minutes', '?') }} min</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" style="text-align:center;color:#666;padding:24px">No ban events yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ── Recent block events ─────────────────────────────────── --}}
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden">
        <div style="padding:16px 20px;border-bottom:1px solid #2a2a2a">
            <h3 style="font-size:15px;font-weight:600">
                Recent Block Events
                <span style="font-size:12px;color:#777;font-weight:400;margin-left:6px">
                    (last 50)
                </span>
            </h3>
        </div>
        <div style="overflow-x:auto">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th style="width:140px">When</th>
                        <th style="width:130px">IP</th>
                        <th style="width:160px">Pattern</th>
                        <th style="width:100px">Location</th>
                        <th>Sample / Path</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recentBlocks as $hit)
                        <tr>
                            <td style="color:#bbb;font-size:12px">
                                {{ $hit->created_at?->diffForHumans() }}
                            </td>
                            <td style="font-family:monospace;color:#fca5a5;font-size:12px">{{ $hit->client_ip ?? '—' }}</td>
                            <td>
                                <span class="badge" style="font-family:'Inter',monospace;background:#7f1d1d;color:#fecaca;border:1px solid #b91c1c;padding:2px 6px;border-radius:4px;font-size:10px">
                                    {{ data_get($hit->meta, 'matched_pattern', '?') }}
                                </span>
                            </td>
                            <td style="color:#bbb;font-size:12px">{{ data_get($hit->meta, 'location', '?') }}</td>
                            <td style="color:#888;font-size:11px;font-family:monospace;max-width:400px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                                title="{{ data_get($hit->meta, 'path', '') }} — {{ data_get($hit->meta, 'sample', '') }}">
                                <div style="color:#aaa">{{ data_get($hit->meta, 'path', '—') }}</div>
                                <div style="color:#666">{{ data_get($hit->meta, 'sample', '') }}</div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" style="text-align:center;color:#666;padding:24px">No block events recorded.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</x-admin.layout>
