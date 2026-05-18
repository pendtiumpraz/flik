<x-admin.layout title="Movies">

    <style>
        .icon-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            padding: 0;
            border-radius: 6px;
            background: transparent;
            border: 1px solid #2a2a2a;
            color: #bbb;
            cursor: pointer;
            transition: all .12s;
        }
        .icon-btn:hover {
            background: #252525;
            color: #fff;
            border-color: #3a3a3a;
        }
        .icon-btn.icon-gold:hover { color: #C5A55A; border-color: #C5A55A; }
        .icon-btn.icon-danger:hover { color: #ef4444; border-color: #ef4444; }
        .icon-btn svg { width: 15px; height: 15px; }
        .icon-btn.is-active { background: #252525; color: #C5A55A; border-color: #C5A55A; }

        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 10px;
            font-size: 13px;
            color: #ddd;
            text-decoration: none;
            border-radius: 5px;
            transition: background .12s, color .12s;
            white-space: nowrap;
        }
        .dropdown-item:hover { background: #252525; color: #fff; }
        .dropdown-item svg { width: 14px; height: 14px; color: #888; flex-shrink: 0; }
        .dropdown-item:hover svg { color: #C5A55A; }
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
                    <th style="width:140px;text-align:right;padding-right:20px">Actions</th>
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
                    <td style="text-align:right;padding-right:20px">
                        <div style="display:inline-flex;gap:4px;align-items:center" x-data="{ open: false }" @click.outside="open = false">
                            {{-- Edit (pencil) --}}
                            <a href="{{ route('admin.movies.edit', $movie) }}" class="icon-btn icon-gold" title="Edit movie">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            </a>

                            {{-- Delete (trash) --}}
                            <form method="POST" action="{{ route('admin.movies.destroy', $movie) }}" onsubmit="return confirm('Delete {{ addslashes($movie->title) }}?')" style="margin:0">
                                @csrf @method('DELETE')
                                <button type="submit" class="icon-btn icon-danger" title="Delete movie">
                                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3"/></svg>
                                </button>
                            </form>

                            {{-- More dropdown (dots-vertical) --}}
                            <div style="position:relative">
                                <button type="button" @click="open = !open" class="icon-btn" :class="{ 'is-active': open }" title="More actions">
                                    <svg fill="currentColor" viewBox="0 0 24 24"><path d="M12 8c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm0 2c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm0 6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"/></svg>
                                </button>
                                <div x-show="open" x-cloak x-transition.opacity style="position:absolute;right:0;top:calc(100% + 6px);min-width:220px;background:#1a1a1a;border:1px solid #2a2a2a;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,0.5);z-index:50;padding:6px;display:flex;flex-direction:column;gap:2px;text-align:left">
                                    <a href="{{ route('admin.movies.subtitles.index', $movie) }}" class="dropdown-item">
                                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/></svg>
                                        Subtitles
                                    </a>

                                    @if (\Illuminate\Support\Facades\Route::has('admin.movies.encoding-status'))
                                        <a href="{{ route('admin.movies.encoding-status', $movie) }}" class="dropdown-item">
                                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                            Encoding Status
                                        </a>
                                    @endif

                                    @if (\Illuminate\Support\Facades\Route::has('admin.movies.upload-master'))
                                        <form method="POST" action="{{ route('admin.movies.upload-master', $movie) }}" enctype="multipart/form-data" style="margin:0" onsubmit="return this.querySelector('input[type=file]').files.length > 0 || (alert('Pick a master file first'), false)">
                                            @csrf
                                            <label class="dropdown-item" style="cursor:pointer">
                                                <input type="file" name="master" accept="video/*" style="display:none" onchange="this.form.requestSubmit()">
                                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                                                Upload Master Video
                                            </label>
                                        </form>
                                    @endif

                                    @if (\Illuminate\Support\Facades\Route::has('highlight.show'))
                                        <a href="{{ route('highlight.show', $movie) }}" class="dropdown-item">
                                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
                                            Highlight Reel
                                        </a>
                                    @endif

                                    <div style="height:1px;background:#2a2a2a;margin:4px 6px"></div>
                                    <div style="font-size:10px;text-transform:uppercase;color:#555;padding:4px 10px;letter-spacing:0.5px">Marketing</div>

                                    @if (\Illuminate\Support\Facades\Route::has('admin.movies.marketing-ops.tiktok-clips'))
                                        <a href="{{ route('admin.movies.marketing-ops.tiktok-clips', $movie) }}" class="dropdown-item">
                                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                            TikTok Clips
                                        </a>
                                    @endif
                                    @if (\Illuminate\Support\Facades\Route::has('admin.movies.marketing-ops.title-alternatives'))
                                        <a href="{{ route('admin.movies.marketing-ops.title-alternatives', $movie) }}" class="dropdown-item">
                                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h7"/></svg>
                                            Title A/B Alternatives
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
