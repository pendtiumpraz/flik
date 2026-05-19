<x-admin.layout title="Report — {{ $campaign->name }}">

    @if(session('success'))
        <div class="flash-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div style="background:rgba(220,38,38,0.15);border:1px solid rgba(220,38,38,0.3);color:#ef4444;padding:12px 20px;border-radius:8px;margin-bottom:20px;font-size:14px">
            {{ session('error') }}
        </div>
    @endif

    @php
        $statusColor = match($campaign->status) {
            'draft' => '#6b7280',
            'queued' => '#3b82f6',
            'sending' => '#C5A55A',
            'sent' => '#22c55e',
            'cancelled' => '#ef4444',
            default => '#666',
        };
    @endphp

    {{-- Header --}}
    <div style="display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:24px;flex-wrap:wrap;gap:12px">
        <div>
            <a href="{{ route('admin.email-campaigns.index') }}" style="font-size:12px;color:#777;text-decoration:none">← All Campaigns</a>
            <h2 style="font-size:22px;font-weight:600;margin-top:4px">{{ $campaign->name }}</h2>
            <p style="color:#777;font-size:13px;margin-top:4px">
                <span class="badge" style="background:{{ $statusColor }}20;color:{{ $statusColor }}">{{ strtoupper($campaign->status) }}</span>
                · {{ $campaign->sent_at ? 'Sent ' . $campaign->sent_at->diffForHumans() : 'Created ' . $campaign->created_at?->diffForHumans() }}
            </p>
        </div>

        @if($campaign->isCancellable())
            <form method="POST" action="{{ route('admin.email-campaigns.cancel', $campaign) }}"
                  onsubmit="return confirm('Cancel campaign? Worker akan skip jobs yang masih pending.')">
                @csrf
                <button type="submit" class="btn btn-danger">Cancel Campaign</button>
            </form>
        @endif
    </div>

    {{-- KPI strip --}}
    <div class="grid-stats" style="margin-bottom:24px">
        <div class="stat-card">
            <div class="label">Audience</div>
            <div class="value">{{ number_format($campaign->audience_estimated) }}</div>
        </div>
        <div class="stat-card">
            <div class="label">Sent</div>
            <div class="value">{{ number_format($campaign->send_count) }}</div>
        </div>
        <div class="stat-card">
            <div class="label">Opens</div>
            <div class="value">{{ number_format($campaign->open_count) }}</div>
            <div style="font-size:12px;color:#C5A55A;margin-top:4px">{{ $campaign->openRate() }}% open rate</div>
        </div>
        <div class="stat-card">
            <div class="label">Clicks</div>
            <div class="value">{{ number_format($campaign->click_count) }}</div>
            <div style="font-size:12px;color:#22c55e;margin-top:4px">{{ $campaign->clickRate() }}% CTR</div>
        </div>
        <div class="stat-card">
            <div class="label">Bounces</div>
            <div class="value" style="color:#ef4444">{{ number_format($bouncedCount) }}</div>
        </div>
    </div>

    {{-- Subject + segment --}}
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:24px;margin-bottom:24px">
        <h3 style="font-size:13px;color:#C5A55A;text-transform:uppercase;letter-spacing:1px;margin-bottom:12px">Campaign Details</h3>
        <table style="width:100%;border-collapse:collapse">
            <tr><td style="padding:8px 0;color:#666;width:140px">Subject</td><td>{{ $campaign->subject }}</td></tr>
            <tr><td style="padding:8px 0;color:#666">Preheader</td><td>{{ $campaign->preheader ?: '—' }}</td></tr>
            <tr><td style="padding:8px 0;color:#666;vertical-align:top">Segment</td>
                <td><pre style="font-family:'Menlo','Consolas',monospace;font-size:12px;background:#0f0f0f;padding:12px;border-radius:8px;margin:0;color:#aaa">{{ json_encode($campaign->segment_definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></td>
            </tr>
        </table>
    </div>

    {{-- Top links --}}
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden;margin-bottom:24px">
        <div style="padding:16px 20px;border-bottom:1px solid #2a2a2a">
            <h3 style="font-size:15px;font-weight:600">Top Clicked Links</h3>
        </div>
        @if($linkBreakdown->isEmpty())
            <div style="padding:32px 20px;text-align:center;color:#555;font-size:13px">No clicks tracked yet.</div>
        @else
            <table class="admin-table">
                <thead><tr><th>URL</th><th style="text-align:right">Clicks</th></tr></thead>
                <tbody>
                    @foreach($linkBreakdown as $row)
                        <tr>
                            <td style="font-size:12px;color:#aaa;word-break:break-all">{{ \Illuminate\Support\Str::limit($row->original_url, 120) }}</td>
                            <td style="text-align:right;color:#C5A55A;font-weight:600">{{ number_format($row->click_count) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- Recent failures --}}
    @if($recentFailures->isNotEmpty())
        <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden">
            <div style="padding:16px 20px;border-bottom:1px solid #2a2a2a">
                <h3 style="font-size:15px;font-weight:600">Recent Failures</h3>
            </div>
            <table class="admin-table">
                <thead><tr><th>Email</th><th>Reason</th><th>Failed At</th></tr></thead>
                <tbody>
                    @foreach($recentFailures as $f)
                        <tr>
                            <td>{{ $f->email }}</td>
                            <td style="color:#ef4444;font-size:12px">{{ \Illuminate\Support\Str::limit($f->error_reason ?? 'unknown', 100) }}</td>
                            <td style="color:#888;font-size:12px">{{ $f->failed_at?->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-admin.layout>
