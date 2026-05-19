<x-admin.layout title="Edit Campaign — {{ $campaign->name }}">

    @if(session('success'))
        <div class="flash-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div style="background:rgba(220,38,38,0.15);border:1px solid rgba(220,38,38,0.3);color:#ef4444;padding:12px 20px;border-radius:8px;margin-bottom:20px;font-size:14px">
            {{ session('error') }}
        </div>
    @endif

    <div style="display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:24px;flex-wrap:wrap;gap:12px">
        <div>
            <a href="{{ route('admin.email-campaigns.index') }}" style="font-size:12px;color:#777;text-decoration:none">← All Campaigns</a>
            <h2 style="font-size:22px;font-weight:600;margin-top:4px">Edit Campaign</h2>
            <p style="color:#777;font-size:13px;margin-top:4px">{{ $campaign->name }} · estimated audience: <strong style="color:#C5A55A">{{ number_format($campaign->audience_estimated) }}</strong></p>
        </div>

        <div style="display:flex;gap:8px">
            <form method="POST" action="{{ route('admin.email-campaigns.destroy', $campaign) }}"
                  onsubmit="return confirm('Hapus draft ini? Tidak bisa di-undo.')">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-danger">Delete Draft</button>
            </form>

            <form method="POST" action="{{ route('admin.email-campaigns.send', $campaign) }}"
                  onsubmit="return confirm('Kirim sekarang ke {{ number_format($campaign->audience_estimated) }} penerima?')">
                @csrf
                <button type="submit" class="btn btn-gold">Send Now →</button>
            </form>
        </div>
    </div>

    @include('admin.email-campaigns._form', [
        'action'   => route('admin.email-campaigns.update', $campaign),
        'method'   => 'PUT',
    ])
</x-admin.layout>
