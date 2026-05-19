<x-admin.layout title="Episodes">

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px">
        <div>
            <div style="font-size:12px;color:#666;text-transform:uppercase;letter-spacing:1.2px;margin-bottom:6px">
                <a href="{{ route('admin.movies.index') }}" style="color:#C5A55A;text-decoration:none">Movies</a>
                <span style="color:#444"> / </span>
                <a href="{{ route('admin.movies.seasons.index', $movie) }}" style="color:#C5A55A;text-decoration:none">{{ \Illuminate\Support\Str::limit($movie->title, 30) }}</a>
                <span style="color:#444"> / </span>
                <span>Season {{ $season->season_number }}</span>
            </div>
            <h2 style="margin:0;font-size:22px;font-weight:600;color:#fff">
                Episodes
                <span style="font-size:12px;color:#888;font-weight:400;margin-left:6px">
                    {{ $episodes->count() }} total
                </span>
            </h2>
        </div>
        <div style="display:flex;gap:8px">
            <a href="{{ route('admin.movies.seasons.index', $movie) }}" class="btn btn-ghost btn-sm">Back to Seasons</a>
            <a href="{{ route('admin.movies.seasons.episodes.create', [$movie, $season]) }}" class="btn btn-gold">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Add Episode
            </a>
        </div>
    </div>

    @if(session('error'))
        <div style="background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.3);color:#ef4444;padding:12px 20px;border-radius:8px;margin-bottom:16px;font-size:14px">{{ session('error') }}</div>
    @endif

    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden">
        <table class="admin-table">
            <thead>
                <tr>
                    <th style="width:60px">Ep</th>
                    <th>Title</th>
                    <th style="width:80px">Runtime</th>
                    <th style="width:120px">Air Date</th>
                    <th style="width:80px">AI</th>
                    <th style="width:140px;text-align:right;padding-right:20px">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($episodes as $episode)
                <tr>
                    <td>
                        <span style="display:inline-block;padding:4px 8px;border-radius:6px;background:rgba(197,165,90,0.12);color:#C5A55A;font-weight:700;font-size:12px">
                            {{ $episode->episode_number }}
                        </span>
                    </td>
                    <td>
                        <div style="font-weight:500;color:#fff">{{ $episode->title }}</div>
                        @if($episode->overview)
                            <div style="font-size:12px;color:#777;margin-top:2px">{{ \Illuminate\Support\Str::limit($episode->overview, 90) }}</div>
                        @endif
                    </td>
                    <td style="color:#888">{{ $episode->runtime_minutes ? $episode->runtime_minutes . ' min' : '—' }}</td>
                    <td style="color:#888">{{ $episode->air_date ? $episode->air_date->format('Y-m-d') : '—' }}</td>
                    <td>
                        @if($episode->generated_summary)
                            <span class="badge badge-gold" title="{{ \Illuminate\Support\Str::limit($episode->generated_summary, 200) }}">AI ✓</span>
                        @else
                            <span style="color:#555;font-size:12px">—</span>
                        @endif
                    </td>
                    <td style="text-align:right;padding-right:20px">
                        <a href="{{ route('admin.movies.seasons.episodes.edit', [$movie, $season, $episode]) }}" class="btn btn-ghost btn-sm" style="margin-right:6px">Edit</a>
                        <form method="POST" action="{{ route('admin.movies.seasons.episodes.destroy', [$movie, $season, $episode]) }}" style="display:inline" onsubmit="return confirm('Hapus episode ini?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" style="text-align:center;color:#555;padding:40px">
                        Belum ada episode. <a href="{{ route('admin.movies.seasons.episodes.create', [$movie, $season]) }}" style="color:#C5A55A">Tambah episode pertama →</a>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

</x-admin.layout>
