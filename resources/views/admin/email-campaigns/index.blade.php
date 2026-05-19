<x-admin.layout title="Email Campaigns">

    @if(session('success'))
        <div class="flash-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div style="background:rgba(220,38,38,0.15);border:1px solid rgba(220,38,38,0.3);color:#ef4444;padding:12px 20px;border-radius:8px;margin-bottom:20px;font-size:14px">
            {{ session('error') }}
        </div>
    @endif

    {{-- Header --}}
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px">
        <div>
            <h2 style="font-size:22px;font-weight:600">Email Campaigns</h2>
            <p style="color:#777;font-size:13px;margin-top:4px">Compose, segment, AI-draft, send & track bulk email broadcasts.</p>
        </div>
        <a href="{{ route('admin.email-campaigns.create') }}" class="btn btn-gold">+ New Campaign</a>
    </div>

    {{-- KPI strip --}}
    <div class="grid-stats" style="margin-bottom:24px">
        <div class="stat-card">
            <div class="label">Total Campaigns</div>
            <div class="value">{{ $campaigns->total() }}</div>
        </div>
        <div class="stat-card">
            <div class="label">Drafts</div>
            <div class="value">{{ \App\Models\EmailCampaign::where('status','draft')->count() }}</div>
        </div>
        <div class="stat-card">
            <div class="label">Sending</div>
            <div class="value">{{ \App\Models\EmailCampaign::whereIn('status',['queued','sending'])->count() }}</div>
        </div>
        <div class="stat-card">
            <div class="label">Sent</div>
            <div class="value">{{ \App\Models\EmailCampaign::where('status','sent')->count() }}</div>
        </div>
    </div>

    {{-- Campaign list --}}
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden">
        @if($campaigns->isEmpty())
            <div style="padding:48px 20px;text-align:center;color:#555">
                <p style="margin-bottom:8px">Belum ada campaign.</p>
                <p style="font-size:12px">Klik <strong>+ New Campaign</strong> untuk membuat draft pertama.</p>
            </div>
        @else
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Status</th>
                        <th style="text-align:right">Audience</th>
                        <th style="text-align:right">Sent</th>
                        <th style="text-align:right">Open %</th>
                        <th style="text-align:right">Click %</th>
                        <th style="text-align:right">Bounces</th>
                        <th>Created</th>
                        <th style="text-align:right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($campaigns as $c)
                        @php
                            $statusColor = match($c->status) {
                                'draft' => '#6b7280',
                                'queued' => '#3b82f6',
                                'sending' => '#C5A55A',
                                'sent' => '#22c55e',
                                'cancelled' => '#ef4444',
                                default => '#666',
                            };
                        @endphp
                        <tr>
                            <td>
                                <div style="font-weight:500;color:#e5e5e5">{{ $c->name }}</div>
                                <div style="font-size:11px;color:#666;margin-top:2px">{{ \Illuminate\Support\Str::limit($c->subject, 60) }}</div>
                            </td>
                            <td>
                                <span class="badge" style="background:{{ $statusColor }}20;color:{{ $statusColor }}">
                                    {{ strtoupper($c->status) }}
                                </span>
                            </td>
                            <td style="text-align:right;color:#aaa">{{ number_format($c->audience_estimated) }}</td>
                            <td style="text-align:right;color:#aaa">{{ number_format($c->send_count) }}</td>
                            <td style="text-align:right;color:#C5A55A;font-weight:600">{{ $c->openRate() }}%</td>
                            <td style="text-align:right;color:#22c55e;font-weight:600">{{ $c->clickRate() }}%</td>
                            <td style="text-align:right;color:#ef4444">{{ number_format($c->bounce_count) }}</td>
                            <td style="color:#888;font-size:12px">{{ $c->created_at?->diffForHumans() }}</td>
                            <td style="text-align:right;white-space:nowrap">
                                @if($c->isEditable())
                                    <a href="{{ route('admin.email-campaigns.edit', $c) }}" class="btn btn-ghost btn-sm">Edit</a>
                                @else
                                    <a href="{{ route('admin.email-campaigns.report', $c) }}" class="btn btn-ghost btn-sm">Report</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    @if($campaigns->hasPages())
        <div style="margin-top:20px">{{ $campaigns->links() }}</div>
    @endif
</x-admin.layout>
