<x-admin.layout :title="$movie ? 'Edit: ' . $movie->title : 'Add New Movie'">

    <div style="max-width:700px">
        <div style="margin-bottom:24px;display:flex;align-items:center;justify-content:space-between;gap:12px">
            <a href="{{ route('admin.movies.index') }}" style="font-size:13px;color:#888;text-decoration:none">← Back to Movies</a>

            {{-- Re-sync from TMDB — only shown when the row already has a tmdb_id
                 stamped on it (i.e. originally came from the TMDB wizard). Posts
                 to the same import endpoint with overwrite_fields=true so the
                 admin's hand-tuned tweaks are intentionally replaced. --}}
            @if($movie && !empty($movie->tmdb_id) && Route::has('admin.tmdb.import') && auth()->user()?->can('movies.create'))
                <form method="POST" action="{{ route('admin.tmdb.import') }}"
                      onsubmit="return confirm('Re-sync this movie from TMDB? This will overwrite title, overview, cast, genres, and images with TMDB data.');">
                    @csrf
                    <input type="hidden" name="tmdb_id" value="{{ $movie->tmdb_id }}">
                    <input type="hidden" name="type" value="{{ $movie->content_type === 'series' ? 'tv' : 'movie' }}">
                    <input type="hidden" name="queue" value="0">
                    <input type="hidden" name="options[overwrite_fields]" value="1">
                    <input type="hidden" name="options[download_images]" value="1">
                    <button type="submit" class="btn btn-ghost btn-sm" title="TMDB ID: {{ $movie->tmdb_id }}">
                        Re-sync from TMDB
                    </button>
                </form>
            @endif
        </div>

        <form method="POST" action="{{ $movie ? route('admin.movies.update', $movie) : route('admin.movies.store') }}"
              enctype="multipart/form-data"
              style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:24px">

            @csrf
            @if($movie) @method('PUT') @endif

            @if($errors->any())
                <div style="background:rgba(220,38,38,0.15);border:1px solid rgba(220,38,38,0.3);color:#ef4444;padding:12px 20px;border-radius:8px;margin-bottom:20px;font-size:13px">
                    <ul style="margin:0;padding-left:16px">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="form-group">
                <label>Title *</label>
                <input type="text" name="title" class="form-input" value="{{ old('title', $movie?->title) }}" required placeholder="e.g. Dune: Part Two">
            </div>

            <div class="form-group">
                <label>Original Title</label>
                <input type="text" name="original_title" class="form-input" value="{{ old('original_title', $movie?->original_title) }}" placeholder="Leave empty if same as title">
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                <div class="form-group">
                    <label>Type</label>
                    <select name="content_type" class="form-input">
                        @php $ct = old('content_type', $movie?->content_type ?? 'movie'); @endphp
                        <option value="movie" {{ $ct === 'movie' ? 'selected' : '' }}>Movie</option>
                        <option value="series" {{ $ct === 'series' ? 'selected' : '' }}>Series</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Runtime (minutes)</label>
                    <input type="number" name="runtime_minutes" class="form-input" min="0" max="2000" value="{{ old('runtime_minutes', $movie?->runtime_minutes) }}" placeholder="e.g. 148">
                </div>
            </div>

            <div class="form-group">
                <label>Tagline</label>
                <input type="text" name="tagline" class="form-input" value="{{ old('tagline', $movie?->tagline) }}" placeholder="One-line hook, e.g. “Long live the fighters.”">
            </div>

            <div class="form-group">
                <label>Overview *</label>
                <textarea name="overview" class="form-input" required placeholder="Movie synopsis...">{{ old('overview', $movie?->overview) }}</textarea>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                <div class="form-group">
                    <label>Release Date</label>
                    <input type="date" name="release_date" class="form-input" value="{{ old('release_date', $movie?->release_date?->format('Y-m-d')) }}">
                </div>
                <div class="form-group">
                    <label>Rating (0-10)</label>
                    <input type="number" name="vote_average" class="form-input" step="0.1" min="0" max="10" value="{{ old('vote_average', $movie?->vote_average) }}" placeholder="8.5">
                </div>
            </div>

            <div class="form-group">
                <label>Poster</label>
                <div style="display:flex;gap:12px;align-items:flex-start">
                    @if($movie && $movie->poster_url)
                        <img src="{{ $movie->poster_url }}" style="height:96px;border-radius:6px;background:#333;flex-shrink:0" onerror="this.style.display='none'">
                    @endif
                    <div style="flex:1;min-width:0">
                        <input type="file" name="poster_file" accept="image/jpeg,image/png,image/webp" class="form-input" style="padding:8px">
                        <div style="font-size:11px;color:#777;margin:4px 0 8px">Upload JPG/PNG/WebP (maks 5 MB) — atau tempel URL di bawah.</div>
                        <input type="text" name="poster_path" class="form-input" value="{{ old('poster_path', $movie?->poster_path) }}" placeholder="https://image.tmdb.org/...">
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label>Backdrop</label>
                <div style="display:flex;gap:12px;align-items:flex-start">
                    @if($movie && $movie->backdrop_path)
                        <img src="{{ $movie->backdrop_url }}" style="height:72px;border-radius:6px;background:#333;flex-shrink:0" onerror="this.style.display='none'">
                    @endif
                    <div style="flex:1;min-width:0">
                        <input type="file" name="backdrop_file" accept="image/jpeg,image/png,image/webp" class="form-input" style="padding:8px">
                        <div style="font-size:11px;color:#777;margin:4px 0 8px">Upload JPG/PNG/WebP (maks 5 MB) — atau tempel URL di bawah.</div>
                        <input type="text" name="backdrop_path" class="form-input" value="{{ old('backdrop_path', $movie?->backdrop_path) }}" placeholder="https://image.tmdb.org/...">
                    </div>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                <div class="form-group">
                    <label>YouTube Trailer Key</label>
                    <input type="text" name="youtube_key" class="form-input" value="{{ old('youtube_key', $movie?->youtube_key) }}" placeholder="e.g. Way9Dexny3w">
                </div>
                <div class="form-group">
                    <label>Popularity Score</label>
                    <input type="number" name="popularity" class="form-input" step="0.01" min="0" value="{{ old('popularity', $movie?->popularity) }}" placeholder="100.00">
                </div>
            </div>

            <!-- Genres -->
            <div class="form-group">
                <label>Genres</label>
                <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:4px">
                    @foreach($genres as $genre)
                        <label style="display:flex;align-items:center;gap:6px;padding:6px 12px;background:#252525;border-radius:6px;cursor:pointer;font-size:13px;border:1px solid #333;transition:all 0.2s"
                               onmouseover="this.style.borderColor='#C5A55A'" onmouseout="this.style.borderColor='#333'">
                            <input type="checkbox" name="genres[]" value="{{ $genre->id }}"
                                {{ (collect(old('genres', $movie?->genres?->pluck('id')->toArray() ?? []))->contains($genre->id)) ? 'checked' : '' }}
                                style="accent-color:#C5A55A">
                            {{ $genre->name }}
                        </label>
                    @endforeach
                </div>
            </div>

            <!-- Video Master (DRM/Transcode pipeline) -->
            {{-- DRM hardening per docs/audit/04-drm-playback.md FIX #2 §6.
                 The legacy inline upload field used to store unencrypted MP4
                 on the public disk, bypassing DRM. Removed; admins now upload
                 the master via the dedicated MovieUploadController page which
                 dispatches TranscodeMovie → EncryptHlsSegments → UploadToBunny. --}}
            <div class="form-group">
                <label>📹 Master Video (DRM-protected pipeline)</label>
                <div style="background:#1f1d1a;border:1px solid rgba(197,165,90,0.25);border-radius:8px;padding:14px 16px;font-size:13px;color:#cbb98a;line-height:1.6">
                    @if($movie)
                        @if($movie->encoding_status === 'ready')
                            <strong style="color:#7be78a">✓ Master sudah di-encode</strong> — pipeline DRM/HLS aktif. Upload ulang untuk mengganti.
                        @elseif($movie->encoding_status === 'processing')
                            <strong style="color:#f0b942">⏳ Sedang encoding</strong> — buka halaman upload untuk memantau progres.
                        @elseif($movie->encoding_status === 'failed')
                            <strong style="color:#ef4444">✗ Encoding gagal</strong> — buka halaman upload untuk mencoba ulang.
                        @else
                            <strong>Belum ada master.</strong> Simpan dulu metadata di sini, lalu buka halaman upload untuk mengirim file master.
                        @endif
                        <div style="margin-top:10px">
                            @if(\Illuminate\Support\Facades\Route::has('admin.movies.upload-page'))
                                <a href="{{ route('admin.movies.upload-page', $movie) }}"
                                   class="btn btn-gold btn-sm"
                                   style="font-size:12px;padding:8px 14px;border-radius:6px;text-decoration:none">
                                    Upload Master Video →
                                </a>
                            @endif
                        </div>
                    @else
                        Simpan film dulu, lalu upload master video melalui pipeline DRM/transcode pada halaman berikutnya.
                    @endif
                </div>
            </div>

            <!-- Flags -->
            <div style="display:flex;gap:28px;margin-top:12px;margin-bottom:24px">
                <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
                    <span class="toggle">
                        <input type="hidden" name="is_popular" value="0">
                        <input type="checkbox" name="is_popular" value="1"
                            {{ old('is_popular', $movie?->is_popular) ? 'checked' : '' }}>
                        <span class="slider"></span>
                    </span>
                    <span style="font-size:13px">Popular</span>
                </label>

                <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
                    <span class="toggle">
                        <input type="hidden" name="is_trending" value="0">
                        <input type="checkbox" name="is_trending" value="1"
                            {{ old('is_trending', $movie?->is_trending) ? 'checked' : '' }}>
                        <span class="slider"></span>
                    </span>
                    <span style="font-size:13px">Trending</span>
                </label>
            </div>

            <div style="display:flex;gap:12px">
                <button type="submit" class="btn btn-gold">
                    {{ $movie ? '💾 Save Changes' : '➕ Create Movie' }}
                </button>
                <a href="{{ route('admin.movies.index') }}" class="btn btn-ghost">Cancel</a>
            </div>
        </form>
    </div>

</x-admin.layout>
