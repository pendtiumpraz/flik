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

        /* ── Bulk-action UI ───────────────────────────────────────── */
        .bulk-checkbox { width: 16px; height: 16px; accent-color: #C5A55A; cursor: pointer; }
        .bulk-toolbar {
            position: sticky; top: 0; z-index: 25;
            background: linear-gradient(180deg, #1f1c14 0%, #1a1a1a 100%);
            border: 1px solid #C5A55A; border-radius: 10px;
            padding: 10px 14px; margin-bottom: 16px;
            display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
            box-shadow: 0 6px 18px rgba(0,0,0,0.45);
        }
        .bulk-toolbar .count {
            font-weight: 600; color: #C5A55A; font-size: 13px;
            padding-right: 6px; border-right: 1px solid #3a2f1a;
        }
        .bulk-btn {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 12px; font-size: 12px; font-weight: 500;
            border-radius: 6px; border: 1px solid #3a3a3a;
            background: transparent; color: #ddd; cursor: pointer;
            transition: all .12s;
        }
        .bulk-btn:hover:not(:disabled) { background: #252525; border-color: #555; color: #fff; }
        .bulk-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .bulk-btn.is-danger:hover:not(:disabled) { border-color: #ef4444; color: #ef4444; }
        .bulk-btn.is-gold:hover:not(:disabled)   { border-color: #C5A55A; color: #C5A55A; }
        .bulk-btn svg { width: 13px; height: 13px; }

        /* Selection highlight: dim non-selected rows, gold border on selected */
        tr.is-selected td { background: rgba(197,165,90,0.08) !important; }
        tr.is-selected td:first-child { border-left: 3px solid #C5A55A; padding-left: 13px; }
        tr.is-dimmed td { opacity: 0.55; }

        /* Loading spinner inside toolbar */
        .spinner {
            display: inline-block; width: 12px; height: 12px;
            border: 2px solid #3a3a3a; border-top-color: #C5A55A;
            border-radius: 50%; animation: spin .7s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* Confirm modal */
        .modal-backdrop {
            position: fixed; inset: 0; background: rgba(0,0,0,0.7);
            z-index: 100; display: flex; align-items: center; justify-content: center;
            backdrop-filter: blur(3px);
        }
        .modal-card {
            background: #1a1a1a; border: 1px solid #2a2a2a;
            border-radius: 12px; padding: 24px; max-width: 460px; width: 90%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.6);
        }
    </style>

    {{-- The whole table + toolbar share one Alpine component so the sticky
         toolbar can read/write the same `selected` set the per-row
         checkboxes mutate. --}}
    <div x-data="movieBulk()" x-cloak>

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

        {{-- ── Sticky bulk action toolbar ───────────────────────────
             Hidden until at least one row is checked. The `x-transition`
             slide-down makes it feel like the toolbar drops in. --}}
        <template x-if="selected.length > 0">
            <div class="bulk-toolbar"
                 x-transition:enter="transition ease-out duration-150"
                 x-transition:enter-start="opacity-0 -translate-y-2"
                 x-transition:enter-end="opacity-100 translate-y-0">
                <span class="count" x-text="selected.length + ' movies selected'"></span>

                <button class="bulk-btn is-danger" :disabled="loading" @click="confirmDelete = true">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22"/></svg>
                    Delete
                </button>
                <button class="bulk-btn is-gold" :disabled="loading" @click="bulkAction('feature')">★ Feature</button>
                <button class="bulk-btn"        :disabled="loading" @click="bulkAction('unfeature')">Unfeature</button>
                <button class="bulk-btn is-gold" :disabled="loading" @click="bulkAction('trend')">↑ Trending</button>
                <button class="bulk-btn"        :disabled="loading" @click="bulkAction('untrend')">Untrend</button>

                <select class="form-input" style="padding:5px 8px;font-size:12px;height:28px" x-model="genreId">
                    <option value="">— Genre to add —</option>
                    @foreach(\App\Models\Genre::orderBy('name')->get(['id','name']) as $g)
                        <option value="{{ $g->id }}">{{ $g->name }}</option>
                    @endforeach
                </select>
                <button class="bulk-btn is-gold" :disabled="loading || !genreId"
                        @click="bulkAction('add-genre', { genre_id: parseInt(genreId) })">
                    + Add Genre
                </button>

                <button class="bulk-btn" :disabled="loading" @click="bulkAction('ai-tags')">⟳ AI Tags</button>
                <button class="bulk-btn" :disabled="loading" @click="bulkAction('ai-synopsis')">⟳ Synopsis</button>
                <button class="bulk-btn" :disabled="loading" @click="exportCsv()">⤓ CSV</button>

                <span x-show="loading" class="spinner" style="margin-left:4px"></span>

                <button class="bulk-btn" style="margin-left:auto" @click="clearSelection()">
                    ✕ Clear
                </button>
            </div>
        </template>

        {{-- "Select all matching X" callout — only when a search is active --}}
        @if(request('search') && $movies->total() > $movies->count())
            <div x-show="selected.length === pageIds.length && selected.length > 0"
                 style="background:#1a1a1a;border:1px dashed #C5A55A;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:13px;color:#ccc">
                All <strong>{{ $movies->count() }}</strong> on this page are selected.
                <a href="#" @click.prevent="selectAllMatching()" style="color:#C5A55A;margin-left:6px">
                    Select all {{ number_format(min($movies->total(), 1000)) }} matching "{{ request('search') }}" →
                </a>
                <span x-show="useSearch" style="color:#C5A55A;margin-left:6px">✓ All matching selected</span>
            </div>
        @endif

        <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th style="width:36px;padding-left:16px">
                            <input type="checkbox" class="bulk-checkbox"
                                   @change="$event.target.checked ? selectAllVisible() : clearSelection()"
                                   :checked="selected.length > 0 && selected.length >= pageIds.length"
                                   title="Select all on this page">
                        </th>
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
                    <tr :class="{ 'is-selected': isSelected({{ $movie->id }}), 'is-dimmed': selected.length > 0 && !isSelected({{ $movie->id }}) }">
                        <td style="padding-left:16px">
                            <input type="checkbox" class="bulk-checkbox"
                                   value="{{ $movie->id }}"
                                   :checked="isSelected({{ $movie->id }})"
                                   @change="toggle({{ $movie->id }}, $event.target.checked, {{ \Js::from(['title' => $movie->title]) }})">
                        </td>
                        <td style="color:#555">{{ $movie->id }}</td>
                        <td>
                            <div style="display:flex;align-items:center;gap:12px">
                                <img src="{{ $movie->poster_url }}" alt="{{ $movie->title }}" style="width:40px;height:56px;object-fit:cover;border-radius:4px;background:#333"
                                    onerror="this.onerror=null">
                                <div>
                                    <div style="font-weight:500;color:#fff;display:flex;align-items:center;gap:6px">
                                        {{-- TV badge — present only for series rows so the operator
                                             can spot them at a glance in the catalog list. --}}
                                        @if($movie->is_series)
                                            <span title="TV Series" style="display:inline-flex;align-items:center;font-size:11px;padding:2px 6px;border-radius:6px;background:rgba(197,165,90,0.18);color:#C5A55A;border:1px solid rgba(197,165,90,0.4);font-weight:600;letter-spacing:0.5px">
                                                📺 SERIES
                                            </span>
                                        @endif
                                        <span>{{ \Str::limit($movie->title, 30) }}</span>
                                    </div>
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
                                        {{-- Seasons manager — shown for every movie so an admin can
                                             convert a standalone film into a series simply by adding
                                             its first season (SeasonController auto-flips content_type). --}}
                                        @if (\Illuminate\Support\Facades\Route::has('admin.movies.seasons.index'))
                                            <a href="{{ route('admin.movies.seasons.index', $movie) }}" class="dropdown-item">
                                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                                                Seasons{{ $movie->is_series ? ' (' . ($movie->total_seasons ?? '·') . ')' : '' }}
                                            </a>
                                        @endif

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

                                        @if (\Illuminate\Support\Facades\Route::has('admin.movies.soundtrack.generate'))
                                            <form method="POST" action="{{ route('admin.movies.soundtrack.generate', $movie) }}" style="margin:0">
                                                @csrf
                                                <button type="submit" class="dropdown-item" style="width:100%;text-align:left;background:none;border:0;cursor:pointer;color:inherit;font:inherit"
                                                        onclick="return confirm('Generate AI soundtrack analysis for {{ addslashes($movie->title) }}?');">
                                                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3" stroke-width="2" fill="none"/><circle cx="18" cy="16" r="3" stroke-width="2" fill="none"/></svg>
                                                    Soundtrack Analysis
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="8" style="text-align:center;color:#555;padding:40px">
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

        {{-- ── Bulk delete confirm modal (Alpine, not native confirm) ── --}}
        <template x-if="confirmDelete">
            <div class="modal-backdrop" @click.self="confirmDelete = false">
                <div class="modal-card">
                    <h3 style="font-size:18px;margin-bottom:10px;color:#fff">
                        Delete <span x-text="selected.length"></span> movies?
                    </h3>
                    <p style="font-size:13px;color:#aaa;margin-bottom:14px">
                        This will permanently remove the selected movies and detach their genres &amp; cast. This action cannot be undone.
                    </p>
                    <div style="background:#0f0f0f;border:1px solid #222;border-radius:8px;padding:10px 14px;font-size:12px;color:#ccc;margin-bottom:20px;max-height:120px;overflow:auto">
                        <template x-for="(t, i) in titleSample" :key="i">
                            <div x-text="'• ' + t" style="padding:2px 0"></div>
                        </template>
                        <div x-show="selected.length > titleSample.length"
                             x-text="'+ ' + (selected.length - titleSample.length) + ' more'"
                             style="padding:2px 0;color:#888"></div>
                    </div>
                    <div style="display:flex;justify-content:flex-end;gap:8px">
                        <button class="btn btn-ghost btn-sm" @click="confirmDelete = false">Cancel</button>
                        <button class="btn btn-danger btn-sm" :disabled="loading"
                                @click="confirmDelete = false; bulkAction('delete')">
                            <span x-show="!loading">Yes, delete</span>
                            <span x-show="loading" class="spinner"></span>
                        </button>
                    </div>
                </div>
            </div>
        </template>

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

    </div>{{-- /movieBulk --}}

    @push('scripts')
    <script>
        // Alpine factory powering every multi-select interaction on this page.
        // Two payload shapes:
        //   - { action, ids:[...], params }            ← explicit IDs (default)
        //   - { action, search:'...', params }         ← "select all matching"
        function movieBulk() {
            return {
                // ─── State ───────────────────────────────────────────────
                selected: [],              // array of movie IDs (numbers)
                titles: {},                // { id: title } so the confirm modal can preview
                pageIds: @json($movies->pluck('id')->map(fn($v) => (int)$v)->all()),
                searchQuery: @json(request('search', '')),
                useSearch: false,          // when true, send `search` instead of `ids`
                genreId: '',
                loading: false,
                confirmDelete: false,

                // ─── Helpers ─────────────────────────────────────────────
                get titleSample() {
                    return this.selected.slice(0, 3).map(id => this.titles[id] || `#${id}`);
                },

                isSelected(id) { return this.selected.includes(id); },

                toggle(id, on, meta) {
                    const i = this.selected.indexOf(id);
                    if (on && i === -1) {
                        this.selected.push(id);
                        if (meta && meta.title) this.titles[id] = meta.title;
                    } else if (!on && i !== -1) {
                        this.selected.splice(i, 1);
                    }
                    // Manual checkbox interaction always reverts to explicit-IDs mode.
                    this.useSearch = false;
                },

                selectAllVisible() {
                    this.selected = [...this.pageIds];
                    this.useSearch = false;
                    // Cache page titles for the confirm modal preview.
                    document.querySelectorAll('tbody input.bulk-checkbox[value]').forEach(cb => {
                        const id = parseInt(cb.value, 10);
                        const title = cb.closest('tr')?.querySelector('td:nth-child(3) div div')?.textContent?.trim();
                        if (title) this.titles[id] = title;
                    });
                },

                selectAllMatching() {
                    this.useSearch = true;
                    // We don't know all matching IDs upfront — leave `selected` as
                    // the page IDs (so counter+modal still show something useful)
                    // but flip `useSearch` so the request resolves server-side.
                },

                clearSelection() {
                    this.selected = [];
                    this.titles = {};
                    this.useSearch = false;
                },

                // ─── Network ─────────────────────────────────────────────
                async bulkAction(action, params = {}) {
                    if (this.loading) return;
                    if (this.selected.length === 0 && !this.useSearch) return;

                    this.loading = true;
                    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
                    const body = { action, params };
                    if (this.useSearch) {
                        body.search = this.searchQuery;
                    } else {
                        body.ids = this.selected;
                    }

                    try {
                        const res = await fetch(@json(route('admin.movies.bulk')), {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': token,
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            credentials: 'same-origin',
                            body: JSON.stringify(body),
                        });
                        const data = await res.json().catch(() => ({}));
                        if (!res.ok || data.success === false) {
                            alert(data.message || ('Bulk action failed (HTTP ' + res.status + ')'));
                            this.loading = false;
                            return;
                        }
                        // Stash a one-shot flash before reload so the next page
                        // render can surface it via the standard flash bar.
                        sessionStorage.setItem('flik.bulk.flash', data.message || 'OK');
                        window.location.reload();
                    } catch (e) {
                        alert('Network error: ' + e.message);
                        this.loading = false;
                    }
                },

                exportCsv() {
                    if (this.selected.length === 0 && !this.useSearch) return;
                    // CSV is a download — submit a hidden form so the browser
                    // handles the file save dialog natively instead of fetch+blob.
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = @json(route('admin.movies.bulk'));
                    form.style.display = 'none';

                    const csrf = document.createElement('input');
                    csrf.name = '_token';
                    csrf.value = document.querySelector('meta[name="csrf-token"]')?.content || '';
                    form.appendChild(csrf);

                    const act = document.createElement('input');
                    act.name = 'action'; act.value = 'export-csv';
                    form.appendChild(act);

                    if (this.useSearch) {
                        const s = document.createElement('input');
                        s.name = 'search'; s.value = this.searchQuery;
                        form.appendChild(s);
                    } else {
                        this.selected.forEach(id => {
                            const i = document.createElement('input');
                            i.name = 'ids[]'; i.value = id;
                            form.appendChild(i);
                        });
                    }

                    document.body.appendChild(form);
                    form.submit();
                    document.body.removeChild(form);
                },

                init() {
                    // Surface one-shot flash messages stashed before a reload.
                    const flash = sessionStorage.getItem('flik.bulk.flash');
                    if (flash) {
                        sessionStorage.removeItem('flik.bulk.flash');
                        const bar = document.createElement('div');
                        bar.className = 'flash-success';
                        bar.textContent = '✓ ' + flash;
                        document.querySelector('.admin-content')?.prepend(bar);
                    }
                },
            };
        }
    </script>
    @endpush

</x-admin.layout>
