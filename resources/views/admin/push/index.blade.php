<x-admin.layout title="Push Notifications">

    {{-- ── Header summary row ───────────────────────────────────── --}}
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px">
        <div>
            <h2 style="font-size:22px;font-weight:700;color:#fff;letter-spacing:0.5px">
                Web Push Broadcasts
            </h2>
            <p style="color:#888;font-size:13px;margin-top:4px">
                Compose and dispatch browser push notifications to FLiK subscribers.
            </p>
        </div>
        <a href="{{ route('admin.push.create') }}" class="btn btn-gold">
            + New broadcast
        </a>
    </div>

    {{-- ── VAPID config status banner ──────────────────────────── --}}
    @unless ($pushEnabled)
        <div style="background:rgba(239,68,68,0.1);border:1px solid #ef4444;border-radius:10px;padding:14px 18px;margin-bottom:20px;color:#fca5a5;font-size:13px">
            <strong style="color:#fff">VAPID is not configured.</strong>
            New broadcasts will be queued but not delivered.
            Run <code style="background:#000;padding:2px 6px;border-radius:4px;color:#C5A55A">php artisan flik:push:generate-vapid-keys</code>
            and populate <code style="background:#000;padding:2px 6px;border-radius:4px;color:#C5A55A">VAPID_*</code> in your .env, then restart the queue worker.
        </div>
    @endunless

    {{-- ── Subscriber stats ────────────────────────────────────── --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:24px">
        <div class="stat-card">
            <div class="label">Total subscriptions</div>
            <div class="value">{{ number_format($subscriberStats['total']) }}</div>
        </div>
        <div class="stat-card">
            <div class="label">Authenticated</div>
            <div class="value">{{ number_format($subscriberStats['authenticated']) }}</div>
        </div>
        <div class="stat-card">
            <div class="label">Anonymous</div>
            <div class="value">{{ number_format($subscriberStats['anonymous']) }}</div>
        </div>
        <div class="stat-card">
            <div class="label">Healthy (deliverable)</div>
            <div class="value">{{ number_format($subscriberStats['healthy']) }}</div>
        </div>
    </div>

    {{-- ── Broadcast history table ─────────────────────────────── --}}
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden">
        <table class="admin-table">
            <thead>
                <tr>
                    <th style="width:60px">#</th>
                    <th>Title</th>
                    <th>Audience</th>
                    <th>Status</th>
                    <th style="text-align:right">Delivered</th>
                    <th>Sent at</th>
                    <th>Author</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($messages as $msg)
                    <tr>
                        <td style="color:#666">{{ $msg->id }}</td>
                        <td>
                            <div style="font-weight:600;color:#fff">{{ $msg->title }}</div>
                            <div style="font-size:12px;color:#777;margin-top:2px">{{ \Illuminate\Support\Str::limit($msg->body, 80) }}</div>
                        </td>
                        <td>
                            <code style="background:#0f0f0f;padding:3px 8px;border-radius:4px;color:#C5A55A;font-size:11.5px">{{ $msg->audience }}</code>
                        </td>
                        <td>
                            @if ($msg->isDelivered())
                                <span style="color:#10b981;font-size:12.5px;font-weight:500">Delivered</span>
                            @else
                                <span style="color:#f59e0b;font-size:12.5px;font-weight:500">Queued</span>
                            @endif
                        </td>
                        <td style="text-align:right">
                            <span style="color:#10b981">{{ number_format($msg->success_count) }}</span>
                            <span style="color:#666">/</span>
                            <span style="color:#888">{{ number_format($msg->sent_count) }}</span>
                            @if ($msg->failure_count > 0)
                                <div style="font-size:11px;color:#ef4444;margin-top:2px">
                                    {{ number_format($msg->failure_count) }} failed
                                </div>
                            @endif
                        </td>
                        <td style="color:#888;font-size:12.5px">
                            {{ $msg->sent_at?->diffForHumans() ?? '—' }}
                        </td>
                        <td style="color:#888;font-size:12.5px">
                            {{ $msg->author?->name ?? 'system' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" style="text-align:center;color:#666;padding:40px 20px;font-size:14px">
                            No broadcasts yet. Click <strong style="color:#C5A55A">+ New broadcast</strong> to send your first push.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div style="margin-top:18px">
        {{ $messages->links() }}
    </div>

</x-admin.layout>
