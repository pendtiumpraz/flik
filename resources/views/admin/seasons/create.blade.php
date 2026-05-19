<x-admin.layout title="Add Season">

    <div style="margin-bottom:24px">
        <div style="font-size:12px;color:#666;text-transform:uppercase;letter-spacing:1.2px;margin-bottom:6px">
            <a href="{{ route('admin.movies.seasons.index', $movie) }}" style="color:#C5A55A;text-decoration:none">{{ $movie->title }} / Seasons</a>
        </div>
        <h2 style="margin:0;font-size:22px;font-weight:600;color:#fff">Add Season</h2>
    </div>

    @if(session('error'))
        <div style="background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.3);color:#ef4444;padding:12px 20px;border-radius:8px;margin-bottom:16px;font-size:14px">{{ session('error') }}</div>
    @endif

    <form method="POST" action="{{ route('admin.movies.seasons.store', $movie) }}" style="max-width:640px;background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:24px">
        @csrf

        @include('admin.seasons._form', ['season' => null, 'nextNumber' => $nextNumber])

        <div style="display:flex;gap:8px;margin-top:8px">
            <button type="submit" class="btn btn-gold">Create Season</button>
            <a href="{{ route('admin.movies.seasons.index', $movie) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-admin.layout>
