<x-admin.layout title="Seasons">

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px">
        <div>
            <div style="font-size:12px;color:#666;text-transform:uppercase;letter-spacing:1.2px;margin-bottom:6px">
                <a href="{{ route('admin.movies.index') }}" style="color:#C5A55A;text-decoration:none">Movies</a>
                <span style="color:#444"> / </span>
                <span>{{ \Illuminate\Support\Str::limit($movie->title, 40) }}</span>
            </div>
            <h2 style="margin:0;font-size:22px;font-weight:600;color:#fff">
                Seasons
                <span style="font-size:12px;color:#888;font-weight:400;margin-left:6px">
                    {{ $seasons->count() }} total · {{ $movie->total_episodes ?? $seasons->sum('episode_count') }} episode
                </span>
            </h2>
        </div>
        <div style="display:flex;gap:8px">
            <a href="{{ route('admin.movies.edit', $movie) }}" class="btn btn-ghost btn-sm">Back to Movie</a>
            <a href="{{ route('admin.movies.seasons.create', $movie) }}" class="btn btn-gold">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Add Season
            </a>
        </div>
    </div>

    @if(session('error'))
        <div style="background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.3);color:#ef4444;padding:12px 20px;border-radius:8px;margin-bottom:16px;font-size:14px">
            {{ session('error') }}
        </div>
    @endif

    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden">
        <table class="admin-table">
            <thead>
                <tr>
                    <th style="width:80px">#</th>
                    <th>Title</th>
                    <th style="width:130px">Air Date</th>
                    <th style="width:100px">Episodes</th>
                    <th style="width:200px;text-align:right;padding-right:20px">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($seasons as $season)
                <tr>
                    <td>
                        <span style="display:inline-block;padding:4px 10px;border-radius:6px;background:rgba(197,165,90,0.12);color:#C5A55A;font-weight:700">
                            S{{ $season->season_number }}
                        </span>
                    </td>
                    <td>
                        <div style="font-weight:500;color:#fff">{{ $season->title ?: 'Season ' . $season->season_number }}</div>
                        @if($season->overview)
                            <div style="font-size:12px;color:#777;margin-top:2px">{{ \Illuminate\Support\Str::limit($season->overview, 80) }}</div>
                        @endif
                    </td>
                    <td style="color:#888">{{ $season->air_date ? $season->air_date->format('Y-m-d') : '—' }}</td>
                    <td style="color:#bbb">{{ $season->episodes_count ?? $season->episode_count }}</td>
                    <td style="text-align:right;padding-right:20px">
                        <a href="{{ route('admin.movies.seasons.episodes.index', [$movie, $season]) }}" class="btn btn-ghost btn-sm" style="margin-right:6px">
                            Episodes →
                        </a>
                        <a href="{{ route('admin.movies.seasons.edit', [$movie, $season]) }}" class="btn btn-ghost btn-sm" style="margin-right:6px">Edit</a>
                        <form method="POST" action="{{ route('admin.movies.seasons.destroy', [$movie, $season]) }}" style="display:inline" onsubmit="return confirm('Hapus Season {{ $season->season_number }} dan semua episode-nya?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" style="text-align:center;color:#555;padding:40px">
                        Belum ada season. <a href="{{ route('admin.movies.seasons.create', $movie) }}" style="color:#C5A55A">Tambah season pertama →</a>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

</x-admin.layout>
