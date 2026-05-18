<x-admin.layout title="Movies">

    <style>
        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 7px 10px;
            font-size: 13px;
            color: #ddd;
            text-decoration: none;
            border-radius: 5px;
            transition: background .12s, color .12s;
            white-space: nowrap;
        }
        .dropdown-item:hover {
            background: #252525;
            color: #fff;
        }
        .btn.is-active {
            background: #252525;
            color: #C5A55A;
        }
    </style>

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
                    <th style="width:160px">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($movies as $movie)
                <tr>
                    <td style="color:#555">{{ $movie->id }}</td>
                    <td>
                        <div style="display:flex;align-items:center;gap:12px">
                            <img src="{{ $movie->poster_url }}" alt="{{ $movie->title }}" style="width:40px;height:56px;object-fit:cover;border-radius:4px;background:#333"
                                onerror="this.onerror=null">
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
                        <div style="display:flex;gap:6px;align-items:center" x-data="{ open: false }" @click.outside="open = false">
                            <a href="{{ route('admin.movies.edit', $movie) }}" class="btn btn-ghost btn-sm" title="Edit">Edit</a>

                            <form method="POST" action="{{ route('admin.movies.destroy', $movie) }}" onsubmit="return confirm('Delete {{ addslashes($movie->title) }}?')" style="margin:0">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-sm" title="Delete">Del</button>
                            </form>

                            {{-- More dropdown — collapses the long action tail --}}
                            <div style="position:relative">
                                <button type="button" @click="open = !open" class="btn btn-ghost btn-sm" :class="{ 'is-active': open }" style="display:inline-flex;align-items:center;gap:4px" title="More actions">
                                    More
                                    <svg width="10" height="10" fill="none" stroke="currentColor" viewBox="0 0 24 24" :style="open ? 'transform:rotate(180deg)' : ''" style="transition:transform .15s"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M19 9l-7 7-7-7"/></svg>
                                </button>
                                <div x-show="open" x-cloak x-transition.opacity style="position:absolute;right:0;top:calc(100% + 4px);min-width:200px;background:#1a1a1a;border:1px solid #2a2a2a;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,0.5);z-index:50;padding:6px;display:flex;flex-direction:column;gap:2px">
                                    <a href="{{ route('admin.movies.subtitles.index', $movie) }}" class="dropdown-item" title="Manage Subtitles">
                                        <span style="color:#C5A55A;width:18px;display:inline-block">CC</span> Subtitles
                                    </a>

                                    @if (\Illuminate\Support\Facades\Route::has('admin.movies.encoding-status'))
                                        <a href="{{ route('admin.movies.encoding-status', $movie) }}" class="dropdown-item" title="Encoding Status">
                                            <span style="width:18px;display:inline-block">⚙</span> Encoding Status
                                        </a>
                                    @endif

                                    @if (\Illuminate\Support\Facades\Route::has('admin.movies.upload-master'))
                                        <form method="POST" action="{{ route('admin.movies.upload-master', $movie) }}" enctype="multipart/form-data" style="margin:0" onsubmit="return this.querySelector('input[type=file]').files.length > 0 || (alert('Pick a master file first'), false)">
                                            @csrf
                                            <label class="dropdown-item" title="Upload Master Video" style="cursor:pointer;display:flex">
                                                <input type="file" name="master" accept="video/*" style="display:none" onchange="this.form.requestSubmit()">
                                                <span style="width:18px;display:inline-block">⬆</span> Upload Master
                                            </label>
                                        </form>
                                    @endif

                                    @if (\Illuminate\Support\Facades\Route::has('highlight.show'))
                                        <a href="{{ route('highlight.show', $movie) }}" class="dropdown-item" title="Highlight Reel">
                                            <span style="width:18px;display:inline-block">✦</span> Highlight Reel
                                        </a>
                                    @endif

                                    <div style="height:1px;background:#2a2a2a;margin:4px 6px"></div>
                                    <div style="font-size:10px;text-transform:uppercase;color:#555;padding:4px 10px;letter-spacing:0.5px">Marketing</div>

                                    @if (\Illuminate\Support\Facades\Route::has('admin.movies.marketing-ops.tiktok-clips'))
                                        <a href="{{ route('admin.movies.marketing-ops.tiktok-clips', $movie) }}" class="dropdown-item" title="TikTok Clips">
                                            <span style="width:18px;display:inline-block">▶</span> TikTok Clips
                                        </a>
                                    @endif
                                    @if (\Illuminate\Support\Facades\Route::has('admin.movies.marketing-ops.title-alternatives'))
                                        <a href="{{ route('admin.movies.marketing-ops.title-alternatives', $movie) }}" class="dropdown-item" title="Title A/B Alternatives">
                                            <span style="width:18px;display:inline-block">A/B</span> Title Alternatives
                                        </a>
                                    @endif
                                </div>
                            </div>
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
