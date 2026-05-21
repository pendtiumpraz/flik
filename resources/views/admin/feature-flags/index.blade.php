{{--
    Feature Flags admin — list every flag with its strategy badge, master
    switch, and edit/delete affordances.

    Layout: <x-admin.layout> (gold theme + sidebar + Alpine).

    The strategy badges are colour-coded per type so an operator can scan
    the table for "what's percentage-rolled-out vs. role-gated vs. dark"
    without reading every row.
--}}
<x-admin.layout title="Feature Flags">

    {{-- ─── Top callout: helper-usage hint for engineers ───────── --}}
    <div style="background:rgba(197,165,90,0.08);border:1px solid rgba(197,165,90,0.25);border-radius:10px;padding:14px 18px;margin-bottom:20px;display:flex;align-items:flex-start;gap:14px">
        <div style="width:32px;height:32px;border-radius:8px;background:rgba(197,165,90,0.2);display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <svg width="18" height="18" fill="none" stroke="#C5A55A" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
        </div>
        <div style="flex:1;font-size:13px;color:#bbb;line-height:1.55">
            <strong style="color:#C5A55A">Try in code:</strong>
            wrap any feature behind <code style="background:#0f0f0f;padding:2px 7px;border-radius:5px;color:#C5A55A;font-family:'JetBrains Mono',Menlo,monospace;font-size:12px">feature('your.key')</code>
            in PHP, or
            <code style="background:#0f0f0f;padding:2px 7px;border-radius:5px;color:#C5A55A;font-family:'JetBrains Mono',Menlo,monospace;font-size:12px">@feature('your.key') ... @endfeature</code>
            in Blade. Both fail-closed (typo &rarr; <em>off</em>) so a missing flag never accidentally enables a feature.
        </div>
    </div>

    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden">
        <div style="padding:16px 20px;border-bottom:1px solid #2a2a2a;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
            <div>
                <h3 style="font-size:15px;font-weight:600">All Feature Flags ({{ $flags->count() }})</h3>
                <p style="font-size:12px;color:#777;margin-top:4px">Runtime toggles for gradual rollouts. Master switch beats strategy &mdash; an off flag is always off.</p>
            </div>
            <a href="{{ route('admin.feature-flags.create') }}" class="btn btn-gold">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                New Flag
            </a>
        </div>

        @if(session('error'))
            <div style="background:rgba(220,38,38,0.15);border-bottom:1px solid rgba(220,38,38,0.3);color:#ef4444;padding:12px 20px;font-size:14px">
                {{ session('error') }}
            </div>
        @endif

        @if($flags->isEmpty())
            <div style="padding:60px 20px;text-align:center;color:#666">
                <svg width="48" height="48" fill="none" stroke="#444" viewBox="0 0 24 24" style="margin:0 auto 16px"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                <div style="font-family:'Outfit';font-size:16px;font-weight:600;color:#888;margin-bottom:6px">No feature flags yet</div>
                <div style="font-size:13px;margin-bottom:18px">Create one to start gating a feature behind <code style="background:#0f0f0f;padding:2px 6px;border-radius:4px;color:#C5A55A">feature('key')</code>.</div>
                <a href="{{ route('admin.feature-flags.create') }}" class="btn btn-gold">+ Create your first flag</a>
            </div>
        @else
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Key</th>
                        <th>Name</th>
                        <th style="width:130px">Strategy</th>
                        <th style="width:90px;text-align:center">Enabled</th>
                        <th style="width:160px">Last Updated</th>
                        <th style="width:180px">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($flags as $flag)
                    @php
                        // Strategy → palette mapping. Anything else falls back to
                        // a neutral grey so a typo (e.g. a hand-crafted JSON row
                        // with an unknown strategy) still renders without breaking.
                        $palette = [
                            'off'        => ['bg' => 'rgba(120,120,120,0.18)', 'fg' => '#9ca3af'],
                            'on'         => ['bg' => 'rgba(34,197,94,0.18)',    'fg' => '#22c55e'],
                            'role'       => ['bg' => 'rgba(168,85,247,0.18)',   'fg' => '#c084fc'],
                            'percentage' => ['bg' => 'rgba(245,158,11,0.18)',   'fg' => '#f59e0b'],
                            'users'      => ['bg' => 'rgba(59,130,246,0.18)',   'fg' => '#60a5fa'],
                            'authed'     => ['bg' => 'rgba(197,165,90,0.18)',   'fg' => '#C5A55A'],
                            'guests'     => ['bg' => 'rgba(20,184,166,0.18)',   'fg' => '#5eead4'],
                        ][$flag->strategy] ?? ['bg' => 'rgba(120,120,120,0.18)', 'fg' => '#9ca3af'];
                    @endphp
                    <tr>
                        <td>
                            <code style="font-size:12px;color:#C5A55A;background:rgba(197,165,90,0.1);padding:3px 9px;border-radius:6px;font-family:'JetBrains Mono',Menlo,monospace">{{ $flag->key }}</code>
                        </td>
                        <td>
                            <div style="color:#fff;font-weight:500">{{ $flag->name }}</div>
                            @if($flag->description)
                                <div style="font-size:11px;color:#777;margin-top:3px;max-width:340px;line-height:1.4">{{ \Illuminate\Support\Str::limit($flag->description, 110) }}</div>
                            @endif
                        </td>
                        <td>
                            <span class="badge" style="background:{{ $palette['bg'] }};color:{{ $palette['fg'] }};text-transform:uppercase;letter-spacing:0.5px">{{ $flag->strategy }}</span>
                            @if($flag->strategy === 'percentage' && is_array($flag->strategy_config) && isset($flag->strategy_config['percentage']))
                                <div style="font-size:11px;color:#888;margin-top:3px">{{ $flag->strategy_config['percentage'] }}% of users</div>
                            @elseif($flag->strategy === 'role' && is_array($flag->strategy_config) && is_array($flag->strategy_config['roles'] ?? null))
                                <div style="font-size:11px;color:#888;margin-top:3px">{{ count($flag->strategy_config['roles']) }} role(s)</div>
                            @elseif($flag->strategy === 'users' && is_array($flag->strategy_config) && is_array($flag->strategy_config['user_ids'] ?? null))
                                <div style="font-size:11px;color:#888;margin-top:3px">{{ count($flag->strategy_config['user_ids']) }} user(s)</div>
                            @endif
                        </td>
                        <td style="text-align:center">
                            {{-- Inline POST toggle. Submits the whole edit payload so the
                                 controller's validation still runs — we keep the existing
                                 strategy + config intact and only flip is_enabled. --}}
                            <form method="POST" action="{{ route('admin.feature-flags.update', ['flag' => $flag->id]) }}" style="display:inline">
                                @csrf @method('PUT')
                                <input type="hidden" name="name" value="{{ $flag->name }}">
                                <input type="hidden" name="description" value="{{ $flag->description }}">
                                <input type="hidden" name="strategy" value="{{ $flag->strategy }}">
                                @if($flag->strategy === 'role' && is_array($flag->strategy_config['roles'] ?? null))
                                    @foreach($flag->strategy_config['roles'] as $r)
                                        <input type="hidden" name="roles[]" value="{{ $r }}">
                                    @endforeach
                                @endif
                                @if($flag->strategy === 'percentage' && isset($flag->strategy_config['percentage']))
                                    <input type="hidden" name="percentage" value="{{ (int) $flag->strategy_config['percentage'] }}">
                                @endif
                                @if($flag->strategy === 'users' && is_array($flag->strategy_config['user_ids'] ?? null))
                                    <input type="hidden" name="user_ids" value="{{ implode(',', $flag->strategy_config['user_ids']) }}">
                                @endif
                                <input type="hidden" name="is_enabled" value="{{ $flag->is_enabled ? 0 : 1 }}">
                                <label class="toggle" title="Click to {{ $flag->is_enabled ? 'disable' : 'enable' }}">
                                    <input type="checkbox" {{ $flag->is_enabled ? 'checked' : '' }}
                                           onchange="this.form.submit()">
                                    <span class="slider"></span>
                                </label>
                            </form>
                        </td>
                        <td style="color:#888;font-size:12px">
                            {{ optional($flag->updated_at)->diffForHumans() ?? '—' }}
                            @if($flag->rollout_started_at)
                                <div style="font-size:10px;color:#555;margin-top:2px">rollout since {{ $flag->rollout_started_at->diffForHumans() }}</div>
                            @endif
                        </td>
                        <td>
                            <div style="display:flex;gap:6px;flex-wrap:wrap">
                                <a href="{{ route('admin.feature-flags.edit', ['flag' => $flag->id]) }}" class="btn btn-ghost btn-sm">Edit</a>
                                <form method="POST" action="{{ route('admin.feature-flags.destroy', ['flag' => $flag->id]) }}"
                                      onsubmit="return confirm('Delete flag &quot;{{ addslashes($flag->name) }}&quot;? This cannot be undone. Code calling feature(\'{{ $flag->key }}\') will return false until the flag is recreated.')"
                                      style="display:inline">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>

</x-admin.layout>
