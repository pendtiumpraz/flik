<x-admin.layout title="Movies">

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px">
        <!-- Search -->
        <form method="GET" action="{{ route('admin.movies.index') }}" style="display:flex;gap:8px">
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Search movies..." class="form-input" style="width:280px;padding:8px 14px">
            <button type="submit" class="btn btn-ghost btn-sm">Search</button>
            @if(request('search'))
                <a href="{{ route('admin.movies.index') }}" class="btn btn-ghost btn-sm" style="color:#666">Clear</a>
            @endif
        </form>
        <a href="{{ route('admin.movies.create') }}" class="btn btn-gold">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            Add Movie
        </a>
    </div>

    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden">
        <table class="admin-table">
            <thead>
                <tr>
                    <th style="width:50px">#</th>
                    <th>Movie</th>
                    <th>Genres</th>
                    <th>Rating</th>
                    <th>Year</th>
                    <th>Flags</th>
                    <th style="width:120px">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($movies as $movie)
                <tr>
                    <td style="color:#555">{{ $movie->id }}</td>
                    <td>
                        <div style="display:flex;align-items:center;gap:12px">
                            <img src="{{ $movie->poster_url }}" alt="{{ $movie->title }}" style="width:40px;height:56px;object-fit:cover;border-radius:4px;background:#333"
                                onerror="this.style.background='#333';this.src='https://via.placeholder.com/40x56/333/666?text='">
                            <div>
                                <div style="font-weight:500;color:#fff">{{ \Str::limit($movie->title, 30) }}</div>
                                @if($movie->original_title && $movie->original_title !== $movie->title)
                                    <div style="font-size:11px;color:#555">{{ \Str::limit($movie->original_title, 35) }}</div>
                                @endif
                            </div>
                        </div>
                    </td>
                    <td>
                        @foreach($movie->genres->take(3) as $genre)
                            <span class="badge badge-blue" style="margin-right:2px;margin-bottom:2px">{{ $genre->name }}</span>
                        @endforeach
                    </td>
                    <td><span style="color:#22c55e">★ {{ number_format($movie->vote_average, 1) }}</span></td>
                    <td style="color:#888">{{ $movie->release_date ? $movie->release_date->format('Y') : '-' }}</td>
                    <td>
                        @if($movie->is_popular) <span class="badge badge-gold" style="margin-right:2px">Pop</span> @endif
                        @if($movie->is_trending) <span class="badge badge-green">Trend</span> @endif
                    </td>
                    <td>
                        <div style="display:flex;gap:6px">
                            <a href="{{ route('admin.movies.edit', $movie) }}" class="btn btn-ghost btn-sm">Edit</a>
                            <form method="POST" action="{{ route('admin.movies.destroy', $movie) }}" onsubmit="return confirm('Delete {{ addslashes($movie->title) }}?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-sm">Del</button>
                            </form>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" style="text-align:center;color:#555;padding:40px">
                        @if(request('search'))
                            No movies found for "{{ request('search') }}"
                        @else
                            No movies yet. <a href="{{ route('admin.movies.create') }}" style="color:#C5A55A">Add your first movie →</a>
                        @endif
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($movies->hasPages())
    <div style="margin-top:20px;display:flex;justify-content:center;gap:8px">
        @if($movies->onFirstPage())
            <span class="btn btn-ghost btn-sm" style="opacity:0.3">← Prev</span>
        @else
            <a href="{{ $movies->previousPageUrl() }}" class="btn btn-ghost btn-sm">← Prev</a>
        @endif

        <span style="padding:6px 12px;font-size:13px;color:#888">
            Page {{ $movies->currentPage() }} of {{ $movies->lastPage() }}
        </span>

        @if($movies->hasMorePages())
            <a href="{{ $movies->nextPageUrl() }}" class="btn btn-ghost btn-sm">Next →</a>
        @else
            <span class="btn btn-ghost btn-sm" style="opacity:0.3">Next →</span>
        @endif
    </div>
    @endif

</x-admin.layout>
