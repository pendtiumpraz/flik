<x-admin.layout title="Admin Notifications">

    {{-- ── Header summary row ───────────────────────────────────── --}}
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px">
        <div>
            <h2 style="font-size:22px;font-weight:700;color:#fff;letter-spacing:0.5px">
                Admin Notifications
            </h2>
            <p style="color:#888;font-size:13px;margin-top:4px">
                Realtime staff alerts —
                <strong style="color:#C5A55A">{{ $unreadCount }}</strong> unread for you
            </p>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <form method="POST" action="{{ route('admin.notifications.read-all') }}"
                  onsubmit="return confirm('Mark every visible notification as read?')">
                @csrf
                <button type="submit" class="btn btn-gold btn-sm"
                    {{ $unreadCount === 0 ? 'disabled' : '' }}>
                    Mark all as read
                </button>
            </form>
        </div>
    </div>

    {{-- ── Filter bar ───────────────────────────────────────────── --}}
    <form method="GET" action="{{ route('admin.notifications.index') }}"
        style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:16px 20px;margin-bottom:20px">
        <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:14px">
            <span style="font-size:11px;text-transform:uppercase;letter-spacing:1.2px;color:#666;font-weight:600;margin-right:4px">State</span>
            @foreach (['all' => 'All', 'unread' => 'Unread', 'read' => 'Read'] as $key => $label)
                @php $active = ($filters['state'] ?? 'all') === $key; @endphp
                <a href="{{ route('admin.notifications.index', array_merge(request()->query(), ['state' => $key])) }}"
                   style="padding:6px 12px;border-radius:999px;font-size:12px;letter-spacing:0.3px;text-decoration:none;border:1px solid {{ $active ? '#C5A55A' : '#333' }};background:{{ $active ? 'rgba(197,165,90,0.15)' : '#0f0f0f' }};color:{{ $active ? '#C5A55A' : '#bbb' }}">
                    {{ $label }}
                </a>
            @endforeach

            <span style="font-size:11px;text-transform:uppercase;letter-spacing:1.2px;color:#666;font-weight:600;margin:0 4px 0 16px">Severity</span>
            <a href="{{ route('admin.notifications.index', array_merge(request()->query(), ['severity' => null])) }}"
               style="padding:6px 12px;border-radius:999px;font-size:12px;text-decoration:none;border:1px solid {{ empty($filters['severity']) ? '#C5A55A' : '#333' }};background:{{ empty($filters['severity']) ? 'rgba(197,165,90,0.15)' : '#0f0f0f' }};color:{{ empty($filters['severity']) ? '#C5A55A' : '#bbb' }}">
                Any
            </a>
            @foreach ($severities as $sev)
                @php
                    $active = ($filters['severity'] ?? null) === $sev;
                    $colour = match ($sev) {
                        'critical' => '#ef4444',
                        'warning'  => '#f59e0b',
                        default    => '#3b82f6',
                    };
                @endphp
                <a href="{{ route('admin.notifications.index', array_merge(request()->query(), ['severity' => $sev])) }}"
                   style="padding:6px 12px;border-radius:999px;font-size:12px;text-decoration:none;text-transform:capitalize;border:1px solid {{ $active ? $colour : '#333' }};background:{{ $active ? 'rgba(255,255,255,0.04)' : '#0f0f0f' }};color:{{ $active ? $colour : '#bbb' }}">
                    {{ $sev }}
                </a>
            @endforeach
        </div>

        <div style="display:grid;grid-template-columns:minmax(220px,1fr) auto;gap:12px;align-items:end">
            <div class="form-group" style="margin:0">
                <label>Category</label>
                <select name="category" class="form-input" onchange="this.form.submit()">
                    <option value="">— Any category —</option>
                    @foreach ($categories as $cat)
                        <option value="{{ $cat }}" @selected(($filters['category'] ?? null) === $cat)>
                            {{ $cat }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div style="display:flex;gap:6px">
                {{-- Preserve state + severity in non-chip submits --}}
                <input type="hidden" name="state" value="{{ $filters['state'] ?? 'all' }}">
                @if (!empty($filters['severity']))
                    <input type="hidden" name="severity" value="{{ $filters['severity'] }}">
                @endif
                <button type="submit" class="btn btn-gold btn-sm">Apply</button>
                <a href="{{ route('admin.notifications.index') }}" class="btn btn-ghost btn-sm">Reset</a>
            </div>
        </div>
    </form>

    {{-- ── Notification list ────────────────────────────────────── --}}
    @if ($notifications->isEmpty())
        <div style="background:#1a1a1a;border:1px dashed #2a2a2a;border-radius:12px;padding:48px 24px;text-align:center;color:#666">
            <p style="font-size:14px">No notifications match these filters.</p>
        </div>
    @else
        <div style="display:flex;flex-direction:column;gap:8px">
            @foreach ($notifications as $n)
                @php
                    $isRead = $n->isReadBy(auth()->user());
                    $sevColor = $n->severityColor();
                @endphp
                <div style="background:{{ $isRead ? '#141414' : '#1a1a1a' }};border:1px solid {{ $isRead ? '#1f1f1f' : '#2a2a2a' }};border-left:3px solid {{ $sevColor }};border-radius:10px;padding:14px 18px;display:flex;gap:14px;align-items:flex-start;transition:border-color 0.15s">
                    {{-- Severity badge --}}
                    <div style="flex-shrink:0;width:36px;height:36px;border-radius:8px;background:rgba(255,255,255,0.04);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:{{ $sevColor }};text-transform:uppercase;letter-spacing:0.5px">
                        {{ substr($n->severity, 0, 1) }}
                    </div>

                    {{-- Body --}}
                    <div style="flex:1;min-width:0">
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px">
                            <strong style="font-size:14px;color:{{ $isRead ? '#bbb' : '#fff' }};font-weight:600">
                                {{ $n->title }}
                            </strong>
                            <span style="font-size:10px;text-transform:uppercase;letter-spacing:1px;padding:2px 8px;border-radius:999px;background:rgba(197,165,90,0.1);color:#C5A55A">
                                {{ $n->category }}
                            </span>
                            @unless ($isRead)
                                <span style="font-size:10px;padding:2px 8px;border-radius:999px;background:rgba(197,165,90,0.2);color:#C5A55A;font-weight:600;letter-spacing:0.5px">NEW</span>
                            @endunless
                        </div>
                        <p style="font-size:13px;color:#999;line-height:1.5;margin-bottom:8px">
                            {{ $n->message }}
                        </p>
                        <div style="display:flex;align-items:center;gap:12px;font-size:11px;color:#666">
                            <span title="{{ optional($n->created_at)->toDateTimeString() }}">
                                {{ optional($n->created_at)->diffForHumans() }}
                            </span>
                            <span>•</span>
                            <span>audience: <code style="color:#888">{{ $n->audience }}</code></span>
                            @if ($n->action_url)
                                <span>•</span>
                                <a href="{{ $n->action_url }}"
                                   style="color:#C5A55A;text-decoration:none;font-weight:500">
                                    View →
                                </a>
                            @endif
                        </div>
                    </div>

                    {{-- Mark-read button --}}
                    @unless ($isRead)
                        <form method="POST" action="{{ route('admin.notifications.read', $n) }}"
                              style="flex-shrink:0">
                            @csrf
                            <button type="submit" class="btn btn-ghost btn-sm"
                                title="Mark as read">
                                Mark read
                            </button>
                        </form>
                    @endunless
                </div>
            @endforeach
        </div>

        <div style="margin-top:20px">
            {{ $notifications->links() }}
        </div>
    @endif

</x-admin.layout>
