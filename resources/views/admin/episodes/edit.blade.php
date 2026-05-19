<x-admin.layout title="Edit Episode">

    <div style="margin-bottom:24px">
        <div style="font-size:12px;color:#666;text-transform:uppercase;letter-spacing:1.2px;margin-bottom:6px">
            <a href="{{ route('admin.movies.seasons.episodes.index', [$movie, $season]) }}" style="color:#C5A55A;text-decoration:none">{{ $movie->title }} / Season {{ $season->season_number }} / Episodes</a>
        </div>
        <h2 style="margin:0;font-size:22px;font-weight:600;color:#fff">Edit Episode {{ $episode->episode_number }}: {{ $episode->title }}</h2>
    </div>

    @if(session('error'))
        <div style="background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.3);color:#ef4444;padding:12px 20px;border-radius:8px;margin-bottom:16px;font-size:14px">{{ session('error') }}</div>
    @endif

    <form method="POST" action="{{ route('admin.movies.seasons.episodes.update', [$movie, $season, $episode]) }}"
          style="max-width:760px;background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:24px">
        @csrf @method('PUT')

        @include('admin.episodes._form', ['episode' => $episode, 'nextNumber' => $episode->episode_number])

        @if($episode->generated_summary)
            <div style="background:#0e0e0e;border:1px dashed rgba(197,165,90,0.35);border-radius:10px;padding:14px;margin-bottom:18px">
                <div style="font-size:10px;letter-spacing:1.5px;text-transform:uppercase;color:#C5A55A;margin-bottom:6px;font-weight:600">
                    AI Blurb
                    @if($episode->generated_summary_at)
                        <span style="color:#555;font-weight:400;letter-spacing:0;text-transform:none;font-size:11px;margin-left:8px">
                            generated {{ $episode->generated_summary_at->diffForHumans() }}
                        </span>
                    @endif
                </div>
                <p style="font-size:13px;color:#ddd;line-height:1.6;margin:0">{{ $episode->generated_summary }}</p>
            </div>
        @endif

        <div style="display:flex;gap:8px;margin-top:8px;align-items:center;flex-wrap:wrap">
            <button type="submit" class="btn btn-gold">Save Changes</button>
            <button type="submit" name="ai_fill" value="1" class="btn btn-ghost"
                    title="Save lalu generate ulang AI blurb (overwrite jika sudah ada)">
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-right:4px"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                Save + Re-generate AI Blurb
            </button>
            <a href="{{ route('admin.movies.seasons.episodes.index', [$movie, $season]) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-admin.layout>
