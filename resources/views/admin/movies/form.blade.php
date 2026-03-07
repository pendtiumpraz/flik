<x-admin.layout :title="$movie ? 'Edit: ' . $movie->title : 'Add New Movie'">

    <div style="max-width:700px">
        <div style="margin-bottom:24px">
            <a href="{{ route('admin.movies.index') }}" style="font-size:13px;color:#888;text-decoration:none">← Back to Movies</a>
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
                <label>Poster URL</label>
                <input type="text" name="poster_path" class="form-input" value="{{ old('poster_path', $movie?->poster_path) }}" placeholder="https://image.tmdb.org/...">
                @if($movie && $movie->poster_url)
                    <div style="margin-top:8px">
                        <img src="{{ $movie->poster_url }}" style="height:80px;border-radius:6px;background:#333" onerror="this.style.display='none'">
                    </div>
                @endif
            </div>

            <div class="form-group">
                <label>Backdrop URL</label>
                <input type="text" name="backdrop_path" class="form-input" value="{{ old('backdrop_path', $movie?->backdrop_path) }}" placeholder="https://image.tmdb.org/...">
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

            <!-- Video Upload -->
            <div class="form-group">
                <label>📹 Video File (MP4, WebM, MOV — max 500MB)</label>
                <input type="file" name="video_file" class="form-input" accept=".mp4,.webm,.mov,.avi" style="padding:10px">
                @if($movie && $movie->video_path)
                    <div style="margin-top:8px;padding:8px 12px;background:#252525;border-radius:6px;font-size:12px;color:#888">
                        ✅ Video saat ini: <strong style="color:#C5A55A">{{ basename($movie->video_path) }}</strong>
                        <span style="color:#555">({{ $movie->video_disk }})</span>
                    </div>
                @endif
            </div>

            <!-- Flags -->
            <div style="display:flex;gap:24px;margin-top:12px;margin-bottom:24px">
                <label class="toggle" style="display:flex;align-items:center;gap:10px;cursor:pointer">
                    <div style="position:relative;width:44px;height:24px">
                        <input type="hidden" name="is_popular" value="0">
                        <input type="checkbox" name="is_popular" value="1"
                            {{ old('is_popular', $movie?->is_popular) ? 'checked' : '' }}
                            style="opacity:0;width:0;height:0;position:absolute">
                        <span class="slider"></span>
                    </div>
                    <span style="font-size:13px">Popular</span>
                </label>

                <label class="toggle" style="display:flex;align-items:center;gap:10px;cursor:pointer">
                    <div style="position:relative;width:44px;height:24px">
                        <input type="hidden" name="is_trending" value="0">
                        <input type="checkbox" name="is_trending" value="1"
                            {{ old('is_trending', $movie?->is_trending) ? 'checked' : '' }}
                            style="opacity:0;width:0;height:0;position:absolute">
                        <span class="slider"></span>
                    </div>
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
