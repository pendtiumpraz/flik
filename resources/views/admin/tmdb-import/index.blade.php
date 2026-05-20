<x-admin.layout title="TMDB Import">

    <div style="max-width:1100px;margin:0 auto">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
            <div>
                <h2 style="font-size:24px;margin-bottom:4px">TMDB Import Wizard</h2>
                <p style="color:#777;font-size:13px;margin:0">
                    Paste a TMDB id or search by title — preview, customize, then import in one click.
                </p>
            </div>
            <a href="{{ route('admin.tmdb.bulk') }}" class="btn btn-ghost">
                Bulk Import →
            </a>
        </div>

        @if(!$enabled)
            <div style="background:rgba(245,158,11,0.12);border:1px solid rgba(245,158,11,0.35);color:#f59e0b;padding:18px 22px;border-radius:12px;margin-bottom:24px">
                <strong style="display:block;font-size:14px;margin-bottom:4px">TMDB API key not configured</strong>
                <p style="font-size:13px;line-height:1.6;margin:0;color:#d2a14f">
                    Set <code style="background:#1a1a1a;padding:1px 6px;border-radius:4px">TMDB_KEY</code>
                    (or <code style="background:#1a1a1a;padding:1px 6px;border-radius:4px">TMDB_BEARER</code>)
                    in <code style="background:#1a1a1a;padding:1px 6px;border-radius:4px">.env</code>, then run
                    <code style="background:#1a1a1a;padding:1px 6px;border-radius:4px">php artisan config:clear</code>.
                    See <a href="https://www.themoviedb.org/settings/api" target="_blank" rel="noopener" style="color:#C5A55A;text-decoration:underline">themoviedb.org/settings/api</a> to grab one (free).
                </p>
            </div>
        @endif

        <div x-data="tmdbWizard()" x-init="init()" style="display:grid;grid-template-columns: 1fr 1.4fr; gap:20px;">

            {{-- ─── LEFT: search + ID entry ─────────────────────────────── --}}
            <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:20px">
                <div style="display:flex;gap:8px;margin-bottom:14px">
                    <button type="button" @click="type='movie'; clearSearch()"
                            :class="type === 'movie' ? 'btn-gold' : 'btn-ghost'" class="btn btn-sm" style="flex:1">
                        Movie
                    </button>
                    <button type="button" @click="type='tv'; clearSearch()"
                            :class="type === 'tv' ? 'btn-gold' : 'btn-ghost'" class="btn btn-sm" style="flex:1">
                        TV Series
                    </button>
                </div>

                <label style="display:block;font-size:12px;color:#aaa;margin-bottom:6px;font-weight:500">Search by title</label>
                <input type="text" class="form-input" x-model.debounce.400ms="query" placeholder="e.g. Dune: Part Two" @input="onQueryInput()" :disabled="!enabled">

                <div x-show="searching" style="margin-top:10px;font-size:12px;color:#777" x-cloak>
                    <span>Searching TMDB…</span>
                </div>

                <div x-show="results.length > 0" style="margin-top:12px;max-height:380px;overflow-y:auto;border:1px solid #2a2a2a;border-radius:8px" x-cloak>
                    <template x-for="hit in results" :key="hit.tmdb_id">
                        <button type="button" @click="loadPreview(hit.tmdb_id)"
                                style="display:flex;width:100%;gap:10px;padding:10px;background:transparent;border:none;border-bottom:1px solid #232323;text-align:left;cursor:pointer;color:#e5e5e5"
                                onmouseover="this.style.background='#222'" onmouseout="this.style.background='transparent'">
                            <img :src="hit.poster_url || '/images/no-poster.png'" style="width:46px;height:69px;object-fit:cover;border-radius:4px;background:#0a0a0a;flex-shrink:0">
                            <div style="flex:1;min-width:0">
                                <div style="font-size:13px;font-weight:500;color:#fff;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" x-text="hit.title"></div>
                                <div style="font-size:11px;color:#888;margin-top:2px">
                                    <span x-text="hit.year || '—'"></span>
                                    <span x-show="hit.vote_average" style="margin-left:8px">★ <span x-text="hit.vote_average"></span></span>
                                </div>
                                <div style="font-size:11px;color:#666;margin-top:4px;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical" x-text="hit.overview"></div>
                            </div>
                        </button>
                    </template>
                </div>

                <div style="margin-top:18px;padding-top:18px;border-top:1px solid #2a2a2a">
                    <label style="display:block;font-size:12px;color:#aaa;margin-bottom:6px;font-weight:500">Or paste a TMDB ID</label>
                    <div style="display:flex;gap:8px">
                        <input type="number" class="form-input" min="1" x-model="manualId" placeholder="e.g. 438631" :disabled="!enabled">
                        <button type="button" class="btn btn-gold" @click="manualId && loadPreview(parseInt(manualId,10))" :disabled="!enabled || !manualId">
                            Preview
                        </button>
                    </div>
                    <div style="font-size:11px;color:#666;margin-top:6px">
                        TMDB ID is the number in the URL: <code style="background:#0a0a0a;padding:1px 5px;border-radius:3px">themoviedb.org/{{ '{type}' }}/<strong style="color:#C5A55A">438631</strong></code>
                    </div>
                </div>
            </div>

            {{-- ─── RIGHT: preview pane + import action ─────────────────── --}}
            <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:20px;min-height:420px">

                <template x-if="loadingPreview">
                    <div style="display:flex;align-items:center;justify-content:center;height:380px;color:#666;font-size:13px" x-cloak>
                        Loading preview…
                    </div>
                </template>

                <template x-if="!loadingPreview && !preview && !errorMessage">
                    <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:380px;color:#555;text-align:center;font-size:13px">
                        <svg width="48" height="48" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-bottom:10px;opacity:0.4"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-3-3v6m9-6a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <p style="margin:0">Pick a result on the left, or paste a TMDB id to preview.</p>
                    </div>
                </template>

                <template x-if="errorMessage">
                    <div style="background:rgba(220,38,38,0.12);border:1px solid rgba(220,38,38,0.3);color:#ef4444;padding:14px 18px;border-radius:8px;font-size:13px" x-text="errorMessage" x-cloak></div>
                </template>

                <template x-if="preview && !loadingPreview">
                    <div x-cloak>
                        <template x-if="preview.already_imported">
                            <div style="background:rgba(59,130,246,0.12);border:1px solid rgba(59,130,246,0.3);color:#60a5fa;padding:12px 16px;border-radius:8px;margin-bottom:14px;font-size:13px">
                                Already imported — re-importing will keep existing genres/cast and only update blank fields unless you tick <strong>Overwrite existing fields</strong> below.
                                <template x-if="preview.existing_movie">
                                    <a :href="preview.existing_movie.edit_url" style="color:#C5A55A;margin-left:6px;text-decoration:underline">Open existing →</a>
                                </template>
                            </div>
                        </template>

                        <div style="display:flex;gap:16px;margin-bottom:18px">
                            <img :src="preview.poster_url || '/images/no-poster.png'" style="width:120px;height:180px;object-fit:cover;border-radius:8px;background:#0a0a0a;flex-shrink:0">
                            <div style="flex:1;min-width:0">
                                <h3 style="font-size:20px;margin:0;color:#fff" x-text="preview.title"></h3>
                                <div x-show="preview.original_title" style="font-size:12px;color:#888;margin-top:2px" x-text="'Orig: ' + preview.original_title"></div>
                                <div style="font-size:12px;color:#aaa;margin-top:8px">
                                    <span x-show="preview.release_date" x-text="preview.release_date"></span>
                                    <span x-show="preview.runtime_minutes" style="margin-left:10px"><span x-text="preview.runtime_minutes"></span> min</span>
                                    <span x-show="preview.vote_average" style="margin-left:10px">★ <span x-text="preview.vote_average"></span> (<span x-text="preview.vote_count"></span>)</span>
                                </div>
                                <div x-show="preview.tagline" style="font-size:12px;color:#C5A55A;margin-top:8px;font-style:italic" x-text="'“' + preview.tagline + '”'"></div>
                                <div style="font-size:12px;color:#bbb;margin-top:10px;line-height:1.6;max-height:120px;overflow-y:auto" x-text="preview.overview"></div>
                            </div>
                        </div>

                        {{-- Customize sections --}}
                        <details style="margin-bottom:12px;background:#0f0f0f;border:1px solid #2a2a2a;border-radius:8px" open>
                            <summary style="padding:10px 14px;cursor:pointer;font-size:13px;font-weight:500;color:#e5e5e5">
                                Genres (<span x-text="preview.genres.length"></span>)
                            </summary>
                            <div style="padding:0 14px 14px;display:flex;flex-wrap:wrap;gap:6px">
                                <template x-for="g in preview.genres" :key="g.name">
                                    <span :style="g.existing_id ? 'background:rgba(34,197,94,0.15);color:#22c55e;' : 'background:rgba(197,165,90,0.15);color:#C5A55A;'"
                                          style="padding:4px 10px;border-radius:14px;font-size:12px"
                                          :title="g.existing_id ? 'Existing genre #' + g.existing_id : 'New genre — will be created'">
                                        <span x-text="g.name"></span><span x-show="!g.existing_id" style="font-size:10px;margin-left:4px;opacity:0.7">NEW</span>
                                    </span>
                                </template>
                            </div>
                        </details>

                        <details style="margin-bottom:12px;background:#0f0f0f;border:1px solid #2a2a2a;border-radius:8px">
                            <summary style="padding:10px 14px;cursor:pointer;font-size:13px;font-weight:500;color:#e5e5e5">
                                Cast (top <span x-text="preview.cast.length"></span>)
                            </summary>
                            <div style="padding:0 14px 14px;display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:8px;max-height:300px;overflow-y:auto">
                                <template x-for="c in preview.cast" :key="(c.tmdb_id || c.name) + '-' + c.order">
                                    <div style="display:flex;gap:8px;align-items:center;padding:6px;background:#1a1a1a;border-radius:6px">
                                        <img :src="c.profile_url || '/images/no-poster.png'" style="width:32px;height:32px;border-radius:50%;object-fit:cover;background:#0a0a0a;flex-shrink:0">
                                        <div style="min-width:0;flex:1">
                                            <div style="font-size:12px;color:#fff;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" x-text="c.name"></div>
                                            <div style="font-size:10px;color:#888;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" x-text="c.character"></div>
                                            <div x-show="c.existing_id" style="font-size:9px;color:#22c55e;margin-top:1px">Existing</div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </details>

                        <template x-if="preview.directors && preview.directors.length > 0">
                            <details style="margin-bottom:12px;background:#0f0f0f;border:1px solid #2a2a2a;border-radius:8px">
                                <summary style="padding:10px 14px;cursor:pointer;font-size:13px;font-weight:500;color:#e5e5e5">
                                    Directors (<span x-text="preview.directors.length"></span>)
                                </summary>
                                <div style="padding:0 14px 14px;display:flex;flex-wrap:wrap;gap:6px">
                                    <template x-for="d in preview.directors" :key="d.name">
                                        <span style="background:#1a1a1a;color:#bbb;padding:4px 10px;border-radius:6px;font-size:12px" x-text="d.name"></span>
                                    </template>
                                </div>
                            </details>
                        </template>

                        <template x-if="preview.tv">
                            <div style="background:rgba(197,165,90,0.08);border:1px solid rgba(197,165,90,0.2);padding:10px 14px;border-radius:8px;font-size:12px;color:#C5A55A;margin-bottom:14px">
                                TV series — <span x-text="preview.seasons_count || 0"></span> seasons / <span x-text="preview.episodes_count || 0"></span> episodes
                            </div>
                        </template>

                        {{-- Import options --}}
                        <div style="background:#0f0f0f;border:1px solid #2a2a2a;border-radius:8px;padding:14px;margin-bottom:14px">
                            <div style="font-size:12px;font-weight:600;color:#aaa;text-transform:uppercase;letter-spacing:1px;margin-bottom:10px">
                                Import options
                            </div>
                            <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#ddd;margin-bottom:8px;cursor:pointer">
                                <input type="checkbox" x-model="options.download_images" style="accent-color:#C5A55A"> Download images to local storage
                            </label>
                            <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#ddd;margin-bottom:8px;cursor:pointer">
                                <input type="checkbox" x-model="options.translate_synopsis" style="accent-color:#C5A55A"> Translate synopsis to Indonesian (uses AI provider)
                            </label>
                            <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#ddd;margin-bottom:8px;cursor:pointer">
                                <input type="checkbox" x-model="options.overwrite_fields" style="accent-color:#C5A55A"> Overwrite existing fields (when re-importing)
                            </label>
                            <template x-if="preview.tv">
                                <div>
                                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#ddd;margin-bottom:8px;cursor:pointer">
                                        <input type="checkbox" x-model="options.import_seasons" style="accent-color:#C5A55A"> Seed season rows
                                    </label>
                                </div>
                            </template>
                        </div>

                        <div style="display:flex;gap:8px;flex-wrap:wrap">
                            <button type="button" class="btn btn-gold" @click="doImport(false)" :disabled="importing">
                                <span x-show="!importing">Import Now</span>
                                <span x-show="importing" x-cloak>Working…</span>
                            </button>
                            <button type="button" class="btn btn-ghost" @click="doImport(true)" :disabled="importing">
                                Queue Import (background)
                            </button>
                            <a :href="preview.tv ? ('https://www.themoviedb.org/tv/' + preview.tmdb_id) : ('https://www.themoviedb.org/movie/' + preview.tmdb_id)"
                               target="_blank" rel="noopener"
                               class="btn btn-ghost" style="margin-left:auto;font-size:12px">
                                View on TMDB ↗
                            </a>
                        </div>

                        <template x-if="successMessage">
                            <div style="background:rgba(34,197,94,0.15);border:1px solid rgba(34,197,94,0.3);color:#22c55e;padding:12px 16px;border-radius:8px;margin-top:14px;font-size:13px" x-cloak>
                                <span x-text="successMessage"></span>
                                <template x-if="lastImportedUrl">
                                    <a :href="lastImportedUrl" style="color:#C5A55A;margin-left:8px;text-decoration:underline">Open in editor →</a>
                                </template>
                            </div>
                        </template>
                    </div>
                </template>

            </div>
        </div>
    </div>

    @push('scripts')
    <script>
    function tmdbWizard() {
        return {
            enabled: @json($enabled),
            type: 'movie',
            query: '',
            manualId: '',
            results: [],
            searching: false,
            preview: null,
            loadingPreview: false,
            errorMessage: '',
            importing: false,
            successMessage: '',
            lastImportedUrl: '',
            options: {
                download_images: true,
                translate_synopsis: false,
                overwrite_fields: false,
                import_seasons: false,
            },
            init() {
                this.csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            },
            clearSearch() {
                this.query = '';
                this.results = [];
                this.preview = null;
                this.errorMessage = '';
                this.successMessage = '';
            },
            async onQueryInput() {
                if (!this.enabled) return;
                if (!this.query || this.query.length < 2) {
                    this.results = [];
                    return;
                }
                this.searching = true;
                try {
                    const url = new URL(@json(route('admin.tmdb.search')), window.location.origin);
                    url.searchParams.set('q', this.query);
                    url.searchParams.set('type', this.type);
                    const resp = await fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                    const data = await resp.json();
                    this.results = (data && data.results) ? data.results : [];
                } catch (e) {
                    this.results = [];
                } finally {
                    this.searching = false;
                }
            },
            async loadPreview(tmdbId) {
                if (!tmdbId) return;
                this.preview = null;
                this.errorMessage = '';
                this.successMessage = '';
                this.loadingPreview = true;
                try {
                    const url = new URL(@json(route('admin.tmdb.preview')), window.location.origin);
                    url.searchParams.set('tmdb_id', tmdbId);
                    url.searchParams.set('type', this.type);
                    const resp = await fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                    if (!resp.ok) {
                        const data = await resp.json().catch(() => ({}));
                        this.errorMessage = data.message || ('Preview failed (HTTP ' + resp.status + ')');
                        return;
                    }
                    const data = await resp.json();
                    this.preview = data.preview;
                    if (this.preview && this.preview.tv) {
                        // Default to enabling season seeding for TV — operator can toggle off.
                        this.options.import_seasons = true;
                    }
                } catch (e) {
                    this.errorMessage = 'Network error: ' + e.message;
                } finally {
                    this.loadingPreview = false;
                }
            },
            async doImport(queue) {
                if (!this.preview) return;
                this.importing = true;
                this.successMessage = '';
                this.errorMessage = '';
                this.lastImportedUrl = '';
                try {
                    const resp = await fetch(@json(route('admin.tmdb.import')), {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': this.csrf,
                        },
                        body: JSON.stringify({
                            tmdb_id: this.preview.tmdb_id,
                            type: this.preview.tv ? 'tv' : 'movie',
                            queue: !!queue,
                            options: this.options,
                        }),
                    });
                    const data = await resp.json().catch(() => ({}));
                    if (!resp.ok) {
                        this.errorMessage = data.message || ('Import failed (HTTP ' + resp.status + ')');
                        return;
                    }
                    this.successMessage = data.message || 'Import successful.';
                    if (data.movie && data.movie.edit_url) {
                        this.lastImportedUrl = data.movie.edit_url;
                    }
                } catch (e) {
                    this.errorMessage = 'Network error: ' + e.message;
                } finally {
                    this.importing = false;
                }
            },
        };
    }
    </script>
    @endpush
</x-admin.layout>
