<x-admin.layout title="Comment Moderation">

    @if(session('error'))
        <div style="background:rgba(220,38,38,0.15);border:1px solid rgba(220,38,38,0.3);color:#ef4444;padding:12px 20px;border-radius:8px;margin-bottom:20px;font-size:14px">
            {{ session('error') }}
        </div>
    @endif

    {{-- Header --}}
    <div style="display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:24px;flex-wrap:wrap;gap:12px">
        <div>
            <h2 style="font-size:22px;font-weight:600">Comment Moderation Queue</h2>
            <p style="color:#777;font-size:13px;margin-top:4px">
                AI auto-classifies new comments. Review flagged items below, approve or reject as needed.
            </p>
        </div>
        <div style="display:flex;align-items:center;gap:8px;color:#C5A55A;font-size:13px">
            <x-icon name="sparkles" :size="16" />
            <span>Powered by FLiK AI Moderator</span>
        </div>
    </div>

    {{-- Stat cards --}}
    <div class="grid-stats" style="margin-bottom:20px">
        @php
            $statTiles = [
                ['key' => 'flagged',  'label' => 'Flagged',  'color' => '#ef4444', 'icon' => 'fire'],
                ['key' => 'pending',  'label' => 'Pending',  'color' => '#eab308', 'icon' => 'clock'],
                ['key' => 'rejected', 'label' => 'Rejected', 'color' => '#777',    'icon' => 'x'],
                ['key' => 'approved', 'label' => 'Approved', 'color' => '#22c55e', 'icon' => 'check'],
            ];
        @endphp
        @foreach($statTiles as $tile)
            <a href="{{ route('admin.comments.queue', ['status' => $tile['key']]) }}"
               class="stat-card"
               style="display:block;text-decoration:none;color:inherit;{{ $status === $tile['key'] ? 'border-color:#C5A55A' : '' }}">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:12px">
                    <div>
                        <div class="label">{{ $tile['label'] }}</div>
                        <div class="value" style="color:{{ $tile['color'] }}">{{ number_format($counts[$tile['key']]) }}</div>
                    </div>
                    <div class="icon" style="background:rgba({{ $tile['key'] === 'flagged' ? '239,68,68' : ($tile['key'] === 'pending' ? '234,179,8' : ($tile['key'] === 'rejected' ? '119,119,119' : '34,197,94')) }},0.15);color:{{ $tile['color'] }}">
                        <x-icon :name="$tile['icon']" :size="20" />
                    </div>
                </div>
            </a>
        @endforeach
    </div>

    {{-- Filter tabs --}}
    <div style="display:flex;gap:6px;margin-bottom:16px;flex-wrap:wrap">
        @foreach(['flagged', 'pending', 'rejected', 'approved', 'all'] as $tab)
            <a href="{{ route('admin.comments.queue', ['status' => $tab]) }}"
               class="btn {{ $status === $tab ? 'btn-gold' : 'btn-ghost' }} btn-sm"
               style="text-transform:capitalize">
                {{ $tab }}
            </a>
        @endforeach
    </div>

    {{-- Comments table --}}
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden">
        @if($comments->isEmpty())
            <div style="padding:48px 20px;text-align:center;color:#555">
                <div style="margin-bottom:8px;color:#C5A55A">
                    <x-icon name="check" :size="32" />
                </div>
                <p style="margin-bottom:4px;color:#aaa">No comments in this queue.</p>
                <p style="font-size:12px">Nothing to moderate right now — looking clean.</p>
            </div>
        @else
            <table class="admin-table">
                <thead>
                    <tr>
                        <th style="width:35%">Comment</th>
                        <th>User</th>
                        <th>Movie</th>
                        <th>AI Verdict</th>
                        <th>Time</th>
                        <th style="text-align:right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($comments as $comment)
                        <tr>
                            <td>
                                <div style="color:#e5e5e5;font-size:13px;line-height:1.5;max-width:380px">
                                    {{ \Illuminate\Support\Str::limit($comment->body, 220) }}
                                </div>
                                @if($comment->is_spoiler)
                                    <span class="badge" style="background:rgba(234,179,8,0.15);color:#eab308;margin-top:6px">Spoiler</span>
                                @endif
                                @if(!$comment->is_visible)
                                    <span class="badge" style="background:rgba(220,38,38,0.15);color:#ef4444;margin-top:6px">Hidden from public</span>
                                @endif
                            </td>
                            <td>
                                <div style="font-weight:500;color:#fff">{{ $comment->user?->name ?? 'Unknown' }}</div>
                                <div style="font-size:11px;color:#666;margin-top:2px">{{ $comment->user?->email }}</div>
                            </td>
                            <td>
                                @if($comment->movie)
                                    <a href="{{ route('admin.movies.edit', $comment->movie) }}"
                                       style="color:#C5A55A;text-decoration:none;font-size:13px">
                                        {{ \Illuminate\Support\Str::limit($comment->movie->title, 32) }}
                                    </a>
                                @else
                                    <span style="color:#555">—</span>
                                @endif
                            </td>
                            <td>
                                @php
                                    $labelColor = match($comment->moderation_label) {
                                        'safe' => 'rgba(34,197,94,0.2);color:#22c55e',
                                        'toxic' => 'rgba(220,38,38,0.2);color:#ef4444',
                                        'spam' => 'rgba(234,179,8,0.2);color:#eab308',
                                        'off_topic' => 'rgba(59,130,246,0.2);color:#3b82f6',
                                        'inappropriate' => 'rgba(168,85,247,0.2);color:#a855f7',
                                        default => '#2a2a2a;color:#777',
                                    };
                                @endphp
                                @if($comment->moderation_label)
                                    <span class="badge" style="background:{{ $labelColor }}">
                                        {{ str_replace('_', ' ', $comment->moderation_label) }}
                                    </span>
                                    @if($comment->moderation_score !== null)
                                        <div style="font-size:11px;color:#777;margin-top:4px">
                                            confidence {{ number_format((float) $comment->moderation_score * 100, 0) }}%
                                        </div>
                                    @endif
                                @else
                                    <span style="color:#555;font-size:12px">Not yet moderated</span>
                                @endif
                            </td>
                            <td>
                                <div style="color:#aaa;font-size:12px">{{ $comment->created_at?->diffForHumans() }}</div>
                                @if($comment->moderated_at)
                                    <div style="font-size:11px;color:#555;margin-top:2px">
                                        moderated {{ $comment->moderated_at->diffForHumans() }}
                                    </div>
                                @endif
                            </td>
                            <td style="text-align:right;white-space:nowrap">
                                @if($comment->moderation_status !== 'approved')
                                    <form method="POST" action="{{ route('admin.comments.approve', $comment) }}" style="display:inline">
                                        @csrf @method('PATCH')
                                        <button type="submit" class="btn btn-gold btn-sm">Approve</button>
                                    </form>
                                @endif
                                @if($comment->moderation_status !== 'rejected')
                                    <form method="POST"
                                          action="{{ route('admin.comments.reject', $comment) }}"
                                          style="display:inline"
                                          onsubmit="return confirm('Reject and hide comment #{{ $comment->id }}?')">
                                        @csrf @method('PATCH')
                                        <button type="submit" class="btn btn-danger btn-sm">Reject</button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('admin.comments.rerun', $comment) }}" style="display:inline">
                                    @csrf
                                    <button type="submit" class="btn btn-ghost btn-sm" title="Re-run AI moderation">
                                        <x-icon name="sparkles" :size="13" /> Re-run
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    @if($comments->hasPages())
        <div style="margin-top:16px">
            {{ $comments->links() }}
        </div>
    @endif

    {{-- Info Box --}}
    <div style="margin-top:24px;background:rgba(197,165,90,0.06);border:1px solid rgba(197,165,90,0.25);border-radius:10px;padding:16px 20px">
        <div style="color:#C5A55A;font-weight:600;font-size:13px;margin-bottom:6px;display:flex;align-items:center;gap:8px">
            <x-icon name="info" :size="16" />
            About AI Moderation
        </div>
        <div style="color:#aaa;font-size:12px;line-height:1.6">
            Comments are auto-classified on creation via the default AI provider (set at <a href="{{ route('admin.ai.index') }}" style="color:#C5A55A">/admin/ai-settings</a>).
            Labels: <strong>safe</strong>, <strong>toxic</strong>, <strong>spam</strong>, <strong>off_topic</strong>, <strong>inappropriate</strong>.<br>
            Comments with non-safe label and confidence &gt; 70% are auto-flagged and hidden from public until reviewed here.
            Use <strong>Re-run</strong> to re-classify after prompt or model changes.
        </div>
    </div>

</x-admin.layout>
