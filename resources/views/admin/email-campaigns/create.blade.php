<x-admin.layout title="New Email Campaign">

    @if(session('error'))
        <div style="background:rgba(220,38,38,0.15);border:1px solid rgba(220,38,38,0.3);color:#ef4444;padding:12px 20px;border-radius:8px;margin-bottom:20px;font-size:14px">
            {{ session('error') }}
        </div>
    @endif

    <div style="margin-bottom:24px">
        <a href="{{ route('admin.email-campaigns.index') }}" style="font-size:12px;color:#777;text-decoration:none">← All Campaigns</a>
        <h2 style="font-size:22px;font-weight:600;margin-top:4px">New Email Campaign</h2>
    </div>

    @include('admin.email-campaigns._form', [
        'action'   => route('admin.email-campaigns.store'),
        'method'   => 'POST',
    ])
</x-admin.layout>
