<x-admin.layout title="Add Episode">

    <div style="margin-bottom:24px">
        <div style="font-size:12px;color:#666;text-transform:uppercase;letter-spacing:1.2px;margin-bottom:6px">
            <a href="{{ route('admin.movies.seasons.episodes.index', [$movie, $season]) }}" style="color:#C5A55A;text-decoration:none">{{ $movie->title }} / Season {{ $season->season_number }} / Episodes</a>
        </div>
        <h2 style="margin:0;font-size:22px;font-weight:600;color:#fff">Add Episode</h2>
    </div>

    @if(session('error'))
        <div style="background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.3);color:#ef4444;padding:12px 20px;border-radius:8px;margin-bottom:16px;font-size:14px">{{ session('error') }}</div>
    @endif

    <form method="POST" action="{{ route('admin.movies.seasons.episodes.store', [$movie, $season]) }}"
          style="max-width:760px;background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:24px"
          x-data="{ aiFill: false }">
        @csrf

        @include('admin.episodes._form', ['episode' => null, 'nextNumber' => $nextNumber])

        <div style="display:flex;gap:8px;margin-top:8px;align-items:center;flex-wrap:wrap">
            <button type="submit" class="btn btn-gold" @click="aiFill = false">Create Episode</button>
            {{-- "AI Fill": same form submit but with ai_fill=1 so EpisodeSummarizer runs after the row lands. --}}
            <button type="submit" name="ai_fill" value="1" class="btn btn-ghost"
                    title="Buat episode lalu generate blurb 50 kata otomatis via AI">
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-right:4px"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                Create + AI Fill
            </button>
            <a href="{{ route('admin.movies.seasons.episodes.index', [$movie, $season]) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-admin.layout>
