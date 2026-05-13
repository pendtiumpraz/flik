<x-admin.layout title="Audit Logs">

    {{-- ── Filters ─────────────────────────────────────────────── --}}
    <form method="GET" action="{{ url()->current() }}"
        style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:16px 20px;margin-bottom:20px">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
            <h3 style="font-size:14px;font-weight:600;color:#C5A55A;letter-spacing:1px;text-transform:uppercase">
                <x-icon name="search" size="14" /> Filters
            </h3>
            <div style="display:flex;gap:8px;align-items:center">
                {{-- Security-only chip. The hidden input makes the chip a
                     normal form value so it round-trips with pagination
                     (the form submits as GET; chip state is in the URL). --}}
                <label
                    title="Show only rows flagged as security events (logins, 2FA, password lifecycle, DRM denials, admin actions, GDPR…)"
                    style="display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;cursor:pointer;font-size:12px;letter-spacing:0.4px;border:1px solid {{ ($filters['security_only'] ?? false) ? '#ef4444' : '#3a3a3a' }};background:{{ ($filters['security_only'] ?? false) ? 'rgba(239,68,68,0.15)' : '#0f0f0f' }};color:{{ ($filters['security_only'] ?? false) ? '#fca5a5' : '#bbb' }};user-select:none">
                    <input type="checkbox" name="security_only" value="1"
                        @checked($filters['security_only'] ?? false)
                        onchange="this.form.submit()"
                        style="margin:0;accent-color:#ef4444;width:14px;height:14px">
                    <x-icon name="shield" size="14" />
                    Security only
                </label>
                <button type="submit" class="btn btn-gold btn-sm">
                    <x-icon name="search" size="14" /> Apply
                </button>
                <a href="{{ url()->current() }}" class="btn btn-ghost btn-sm">Reset</a>
                <button type="submit" name="export" value="1" class="btn btn-ghost btn-sm"
                    title="Download up to 10,000 matching rows as CSV">
                    <x-icon name="download" size="14" /> Export CSV
                </button>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px">
            <div class="form-group" style="margin:0">
                <label>User</label>
                <select name="user_id" class="form-input">
                    <option value="">— Any user —</option>
                    @foreach($users as $u)
                        <option value="{{ $u->id }}" @selected($filters['user_id'] === $u->id)>
                            {{ $u->name }} ({{ $u->email }})
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="form-group" style="margin:0">
                <label>Action prefix</label>
                <input type="text" name="action" class="form-input"
                    list="action-prefix-list"
                    value="{{ $filters['action'] }}"
                    placeholder="e.g. movie. or subscription.created">
                <datalist id="action-prefix-list">
                    @foreach($actionPrefixes as $p)
                        <option value="{{ $p }}."></option>
                    @endforeach
                </datalist>
            </div>

            <div class="form-group" style="margin:0">
                <label>Subject type</label>
                <select name="subject_type" class="form-input">
                    <option value="">— Any subject —</option>
                    @foreach($subjectTypes as $t)
                        <option value="{{ $t }}" @selected($filters['subject_type'] === $t)>
                            {{ class_basename($t) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="form-group" style="margin:0">
                <label>From</label>
                <input type="date" name="date_from" class="form-input"
                    value="{{ $filters['date_from'] }}">
            </div>

            <div class="form-group" style="margin:0">
                <label>To</label>
                <input type="date" name="date_to" class="form-input"
                    value="{{ $filters['date_to'] }}">
            </div>
        </div>
    </form>

    {{-- ── Table ───────────────────────────────────────────────── --}}
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden">
        <div style="padding:16px 20px;border-bottom:1px solid #2a2a2a;display:flex;justify-content:space-between;align-items:center">
            <h3 style="font-size:15px;font-weight:600">
                Audit Trail
                <span style="font-size:12px;color:#777;font-weight:400;margin-left:6px">
                    ({{ number_format($logs->total()) }} entries)
                </span>
            </h3>
            <span style="font-size:11px;color:#555;letter-spacing:1px;text-transform:uppercase">
                {{ $logs->perPage() }} / page
            </span>
        </div>

        <div style="overflow-x:auto">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th style="width:140px">When</th>
                        <th style="width:180px">User</th>
                        <th>Action</th>
                        <th>Subject</th>
                        <th style="width:120px">IP</th>
                        <th style="width:90px">Meta</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                        @php
                            // is_security may be missing on rows written before
                            // the migration ran — fall back to the SecurityEvents
                            // taxonomy for legacy rows (severity != 'low' implies
                            // a recognised security event name).
                            $isSec = (bool) ($log->is_security ?? false);
                            if (! $isSec && class_exists(\App\Support\SecurityEvents::class)) {
                                $isSec = \App\Support\SecurityEvents::severity($log->action) !== 'low'
                                    && in_array($log->action, \App\Support\SecurityEvents::all(), true);
                            }
                            $severity = class_exists(\App\Support\SecurityEvents::class)
                                ? \App\Support\SecurityEvents::severity($log->action)
                                : 'low';
                            $rowBg = match (true) {
                                $isSec && $severity === 'critical' => 'rgba(220,38,38,0.18)', // red-600 @ 18%
                                $isSec && $severity === 'high'     => 'rgba(239,68,68,0.10)', // red-500 @ 10%
                                $isSec && $severity === 'medium'   => 'rgba(239,68,68,0.06)', // red-500 @ 6%
                                $isSec                              => 'rgba(239,68,68,0.04)', // subtle red tint
                                default                             => 'transparent',
                            };
                            $borderLeft = $isSec ? '3px solid #ef4444' : '3px solid transparent';
                            $sevBadgeBg = match ($severity) {
                                'critical' => '#7f1d1d', // red-900
                                'high'     => '#b91c1c', // red-700
                                'medium'   => '#a16207', // yellow-700
                                default    => '#374151', // gray-700
                            };
                        @endphp
                        <tr style="background:{{ $rowBg }};border-left:{{ $borderLeft }}"
                            @if($isSec) title="Security event ({{ $severity }})" @endif>
                            <td style="color:#888;font-size:12px;white-space:nowrap">
                                <div style="color:#e5e5e5">{{ $log->created_at?->format('d M Y') }}</div>
                                <div style="color:#666">{{ $log->created_at?->format('H:i:s') }}</div>
                                @if($isSec)
                                    <div style="margin-top:4px">
                                        <span style="display:inline-block;padding:1px 6px;border-radius:4px;font-size:9px;letter-spacing:0.6px;text-transform:uppercase;color:#fff;background:{{ $sevBadgeBg }}">
                                            {{ $severity }}
                                        </span>
                                    </div>
                                @endif
                            </td>
                            <td>
                                @if($log->user)
                                    <div style="display:flex;align-items:center;gap:10px">
                                        <div style="width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#000;background:linear-gradient(135deg,#C5A55A,#E8D5A3)">
                                            {{ strtoupper(substr($log->user->name, 0, 1)) }}
                                        </div>
                                        <div style="min-width:0">
                                            <div style="font-weight:500;color:#fff;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:140px">
                                                {{ $log->user->name }}
                                            </div>
                                            <div style="font-size:11px;color:#666;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:140px">
                                                {{ $log->user->email }}
                                            </div>
                                        </div>
                                    </div>
                                @else
                                    <span style="color:#555;font-style:italic;font-size:12px">system</span>
                                @endif
                            </td>
                            <td>
                                @if($isSec)
                                    <span class="badge" style="font-family:'Inter',monospace;background:#7f1d1d;color:#fecaca;border:1px solid #b91c1c;padding:3px 8px;border-radius:4px;font-size:11px">
                                        <x-icon name="shield" size="11" /> {{ $log->action }}
                                    </span>
                                @else
                                    <span class="badge badge-gold" style="font-family:'Inter',monospace">
                                        {{ $log->action }}
                                    </span>
                                @endif
                            </td>
                            <td style="color:#aaa;font-size:13px">
                                @if($log->subject_type)
                                    <span style="color:#3b82f6">{{ class_basename($log->subject_type) }}</span>
                                    @if($log->subject_id)
                                        <span style="color:#555">#</span>{{ $log->subject_id }}
                                    @endif
                                @else
                                    <span style="color:#444">—</span>
                                @endif
                            </td>
                            <td style="color:#666;font-size:12px;font-family:monospace">
                                {{ $log->client_ip ?? '—' }}
                            </td>
                            <td>
                                @if($log->meta)
                                    <details>
                                        <summary style="cursor:pointer;color:#C5A55A;font-size:12px;list-style:none">
                                            <x-icon name="eye" size="14" /> view
                                        </summary>
                                        <pre style="margin-top:6px;background:#0a0a0a;border:1px solid #2a2a2a;border-radius:6px;padding:8px;font-size:11px;color:#aaa;max-width:340px;white-space:pre-wrap;word-break:break-all">{{ json_encode($log->meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                    </details>
                                @else
                                    <span style="color:#444">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" style="text-align:center;color:#555;padding:48px">
                                No audit logs match these filters.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ── Pagination ──────────────────────────────────────────── --}}
    @if($logs->hasPages())
        <div style="margin-top:20px;display:flex;justify-content:center;align-items:center;gap:8px">
            @if($logs->onFirstPage())
                <span class="btn btn-ghost btn-sm" style="opacity:0.3">← Prev</span>
            @else
                <a href="{{ $logs->previousPageUrl() }}" class="btn btn-ghost btn-sm">← Prev</a>
            @endif

            <span style="padding:6px 12px;font-size:13px;color:#888">
                Page {{ $logs->currentPage() }} of {{ $logs->lastPage() }}
            </span>

            @if($logs->hasMorePages())
                <a href="{{ $logs->nextPageUrl() }}" class="btn btn-ghost btn-sm">Next →</a>
            @else
                <span class="btn btn-ghost btn-sm" style="opacity:0.3">Next →</span>
            @endif
        </div>
    @endif

</x-admin.layout>
