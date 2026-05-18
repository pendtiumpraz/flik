<x-admin.layout title="Menu Matrix">

    {{-- ── Header / legend ─────────────────────────────────────────── --}}
    <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap;margin-bottom:18px">
        <div>
            <h2 style="font-size:22px;font-weight:700;color:#fff;margin-bottom:4px">
                Menu Matrix
            </h2>
            <p style="font-size:13px;color:#888;max-width:680px">
                Visual audit of which role can see which sidebar entry. Source of truth is
                <code style="background:#0f0f0f;border:1px solid #2a2a2a;border-radius:4px;padding:1px 6px;color:#C5A55A">
                    config/admin_menu.php
                </code>
                — the actual sidebar renders from the same file, so this table cannot drift
                from what users see. To change visibility, edit the role's permissions; to
                change the link itself, edit the config.
            </p>
        </div>
        <div style="display:flex;gap:8px;align-items:center;font-size:12px;color:#999">
            <span style="display:inline-flex;align-items:center;gap:6px">
                <span style="display:inline-block;width:14px;height:14px;border-radius:4px;background:rgba(34,197,94,0.18);border:1px solid rgba(34,197,94,0.35);color:#22c55e;font-weight:700;text-align:center;line-height:14px;font-size:11px">✓</span>
                visible
            </span>
            <span style="display:inline-flex;align-items:center;gap:6px">
                <span style="display:inline-block;width:14px;height:14px;border-radius:4px;background:#2a2a2a;color:#555;font-weight:700;text-align:center;line-height:14px;font-size:11px">✗</span>
                hidden
            </span>
        </div>
    </div>

    @if(! $permissionsTableExists)
        {{-- Hand-off notice: the Permission model + role_permission pivot is
             not in place yet, so the matrix is using the heuristic fallback
             from AuthServiceProvider's coarse Gates. The numbers will sharpen
             automatically once peer ROLE #1/#2 ships the seeded permissions. --}}
        <div style="background:rgba(197,165,90,0.08);border:1px solid rgba(197,165,90,0.35);border-radius:10px;padding:12px 16px;margin-bottom:18px;color:#E8D5A3;font-size:13px;line-height:1.5">
            <strong style="color:#C5A55A">Heads-up:</strong>
            the <code>permissions</code> table has not been migrated yet, so this matrix is
            showing the heuristic fallback derived from the coarse Gates registered in
            <code>AuthServiceProvider</code>. Once the seeded permission catalogue lands,
            this view will read directly from the <code>role_permission</code> pivot and
            reflect admin-defined access exactly.
        </div>
    @endif

    {{-- ── Category filter ─────────────────────────────────────────── --}}
    <form method="GET" action="{{ route('admin.menu-matrix.index') }}"
        style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:14px 18px;margin-bottom:18px">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            <label style="font-size:11px;text-transform:uppercase;letter-spacing:1.2px;color:#888;font-weight:600">
                Filter by category
            </label>
            <select name="category" class="form-input" style="max-width:240px;margin:0"
                onchange="this.form.submit()">
                <option value="">— All categories —</option>
                @foreach($categories as $cat)
                    <option value="{{ $cat }}" @selected($activeCategory === $cat)>
                        {{ ucfirst($cat) }}
                    </option>
                @endforeach
            </select>
            @if($activeCategory !== '')
                <a href="{{ route('admin.menu-matrix.index') }}" class="btn btn-ghost btn-sm">
                    Clear filter
                </a>
            @endif
            <span style="margin-left:auto;font-size:12px;color:#666">
                {{ count($matrix) }} {{ \Illuminate\Support\Str::plural('entry', count($matrix)) }} across
                {{ count($roles) }} {{ \Illuminate\Support\Str::plural('role', count($roles)) }}
            </span>
        </div>
    </form>

    {{-- ── Matrix table ────────────────────────────────────────────── --}}
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:auto">
        <table class="admin-table" style="min-width:100%;border-collapse:separate;border-spacing:0">
            <thead>
                <tr>
                    <th style="position:sticky;left:0;z-index:2;background:#1a1a1a;min-width:260px;border-right:1px solid #2a2a2a">
                        Menu entry
                    </th>
                    @foreach($roles as $slug => $label)
                        <th style="text-align:center;min-width:140px;vertical-align:bottom">
                            <div style="font-size:11px;color:#C5A55A;font-weight:700;text-transform:uppercase;letter-spacing:0.8px">
                                {{ $label }}
                            </div>
                            <div style="font-size:10px;color:#555;margin-top:2px;font-family:monospace;text-transform:none;letter-spacing:0">
                                {{ $slug }}
                            </div>
                            @if(\Illuminate\Support\Facades\Route::has('admin.roles.edit'))
                                <a href="{{ route('admin.roles.edit', $slug) }}"
                                   style="display:inline-block;margin-top:6px;font-size:11px;color:#888;text-decoration:none;border-bottom:1px dashed #444"
                                   title="Edit {{ $label }} role permissions">
                                    Edit role
                                </a>
                            @endif
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @php $lastSection = null; @endphp
                @forelse($matrix as $row)
                    @if($row['section_label'] !== $lastSection)
                        <tr>
                            <td colspan="{{ count($roles) + 1 }}"
                                style="background:#111;color:#C5A55A;font-size:10px;letter-spacing:1.5px;text-transform:uppercase;font-weight:700;padding:10px 16px;border-bottom:1px solid #2a2a2a">
                                {{ $row['section_label'] }}
                                <span style="color:#444;font-weight:500;letter-spacing:1px">
                                    · {{ ucfirst($row['category']) }}
                                </span>
                            </td>
                        </tr>
                        @php $lastSection = $row['section_label']; @endphp
                    @endif
                    <tr style="transition:background 0.15s">
                        <td style="position:sticky;left:0;background:#1a1a1a;border-right:1px solid #2a2a2a;min-width:260px">
                            <div style="display:flex;align-items:center;gap:10px">
                                @if(! empty($row['icon']))
                                    <x-icon :name="$row['icon']" size="16" />
                                @endif
                                <div>
                                    <div style="font-weight:500;color:#e5e5e5;font-size:13px">
                                        {{ $row['label'] }}
                                    </div>
                                    <div style="font-size:11px;color:#666;font-family:monospace;margin-top:2px">
                                        {{ $row['permission'] ?? 'no permission required' }}
                                        @if($row['route'] && ! \Illuminate\Support\Facades\Route::has($row['route']))
                                            <span style="color:#dc2626;margin-left:6px"
                                                title="Route {{ $row['route'] }} is not registered — link will be hidden from the sidebar.">
                                                · route missing
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </td>
                        @foreach($roles as $slug => $_label)
                            @php $allowed = (bool) ($row['access'][$slug] ?? false); @endphp
                            <td style="text-align:center">
                                @if($allowed)
                                    <span title="Visible to {{ $_label }}"
                                        style="display:inline-block;min-width:28px;padding:3px 0;border-radius:6px;background:rgba(34,197,94,0.18);border:1px solid rgba(34,197,94,0.35);color:#22c55e;font-weight:700;font-size:13px">
                                        ✓
                                    </span>
                                @else
                                    <span title="Hidden for {{ $_label }}"
                                        style="display:inline-block;min-width:28px;padding:3px 0;border-radius:6px;background:#2a2a2a;color:#555;font-weight:700;font-size:13px">
                                        ✗
                                    </span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($roles) + 1 }}"
                            style="text-align:center;color:#666;padding:40px;font-size:14px">
                            No menu entries match this filter.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <p style="margin-top:14px;font-size:12px;color:#555;line-height:1.6">
        <strong style="color:#888">Tip:</strong>
        Rows where the route is missing are hidden from the live sidebar even when the
        permission check passes — see the red "route missing" tag for any orphans. The
        <em>Super Admin</em> column will always be all ✓ because the
        <code>Gate::before</code> short-circuit in <code>AuthServiceProvider</code>
        bypasses every individual policy check.
    </p>

    {{-- Subtle row hover --}}
    <style>
        .admin-table tbody tr:hover td:not([colspan]) { background:#222; }
        .admin-table tbody tr:hover td[style*="position:sticky"] { background:#202020; }
    </style>

</x-admin.layout>
