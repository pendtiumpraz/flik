{{-- Shared editor form for create + edit. --}}
@push('styles')
    <style>
        .blog-grid { display:grid; grid-template-columns: 1fr 320px; gap:24px; }
        @media (max-width: 1024px) { .blog-grid { grid-template-columns: 1fr; } }
        .sidebar-card { background:#1a1a1a; border:1px solid #2a2a2a; border-radius:12px; padding:16px; margin-bottom:16px; }
        .sidebar-card h4 { font-size:13px; text-transform:uppercase; letter-spacing:1px; color:#C5A55A; margin-bottom:12px; font-weight:600; }
        .tab-btns { display:flex; gap:4px; margin-bottom:8px; }
        .tab-btn { padding:6px 14px; background:transparent; border:1px solid #2a2a2a; color:#999; cursor:pointer; border-radius:6px; font-size:12px; font-weight:500; }
        .tab-btn.active { background:rgba(197,165,90,0.15); border-color:#C5A55A; color:#C5A55A; }
        .editor-toolbar { display:flex; gap:4px; flex-wrap:wrap; padding:8px; background:#141414; border:1px solid #2a2a2a; border-bottom:none; border-radius:8px 8px 0 0; }
        .editor-toolbar button { background:transparent; border:1px solid #2a2a2a; color:#aaa; padding:4px 10px; border-radius:4px; cursor:pointer; font-size:12px; }
        .editor-toolbar button:hover { color:#C5A55A; border-color:#C5A55A; }
        .editor-textarea { width:100%; min-height:520px; padding:14px; background:#0f0f0f; border:1px solid #2a2a2a; border-top:none; border-radius:0 0 8px 8px; color:#e5e5e5; font-family:'Fira Code',Menlo,monospace; font-size:13px; line-height:1.65; resize:vertical; }
        .editor-textarea:focus { outline:none; border-color:#C5A55A; }
        .preview-pane { min-height:520px; padding:24px; background:#0f0f0f; border:1px solid #2a2a2a; border-top:none; border-radius:0 0 8px 8px; overflow-y:auto; }
        .preview-pane h1, .preview-pane h2, .preview-pane h3 { color:#fff; margin:1.2em 0 0.6em; }
        .preview-pane p { color:#ccc; margin-bottom:0.8em; line-height:1.7; }
        .preview-pane a { color:#C5A55A; }
        .preview-pane code { background:#1a1a1a; padding:2px 6px; border-radius:4px; color:#E8D5A3; font-size:90%; }
        .preview-pane pre { background:#0a0a0a; padding:12px; border-radius:6px; overflow-x:auto; }
        .preview-pane blockquote { border-left:3px solid #C5A55A; padding-left:14px; color:#bbb; margin:1em 0; }
        .related-movie { display:flex; align-items:center; gap:8px; padding:6px 10px; background:#141414; border:1px solid #2a2a2a; border-radius:6px; margin-bottom:6px; cursor:move; }
        .related-movie .handle { color:#555; cursor:grab; }
        .ai-output { background:#0f0f0f; border:1px solid #2a2a2a; border-radius:6px; padding:10px; margin-top:8px; font-size:12px; color:#aaa; max-height:200px; overflow-y:auto; white-space:pre-wrap; }
        .ai-title-pill { display:inline-block; background:rgba(197,165,90,0.1); border:1px solid rgba(197,165,90,0.3); padding:6px 10px; margin:4px 4px 0 0; border-radius:6px; color:#E8D5A3; font-size:12px; cursor:pointer; }
        .ai-title-pill:hover { background:rgba(197,165,90,0.25); color:#fff; }
    </style>
@endpush

<form method="POST" action="{{ $formAction }}" x-data="blogEditor()" @submit="syncMovieIds">
    @csrf
    @if($method === 'PUT')@method('PUT')@endif

    @if($errors->any())
        <div style="background:rgba(220,38,38,0.15);border:1px solid rgba(220,38,38,0.3);color:#ef4444;padding:12px 20px;border-radius:8px;margin-bottom:20px;font-size:13px">
            <ul style="margin-left:18px">
                @foreach($errors->all() as $err)<li>{{ $err }}</li>@endforeach
            </ul>
        </div>
    @endif

    <div class="blog-grid">

        {{-- ── LEFT: title + markdown editor + AI assist ── --}}
        <div>
            <div class="form-group">
                <label>Title *</label>
                <input type="text" name="title" value="{{ old('title', $post->title) }}" class="form-input" required maxlength="200">
            </div>

            <div class="form-group">
                <label>Slug <span style="color:#666;font-weight:400">(auto-generated if empty)</span></label>
                <input type="text" name="slug" value="{{ old('slug', $post->slug) }}" class="form-input" maxlength="160" placeholder="post-judul-singkat">
            </div>

            <div class="form-group">
                <label>Excerpt <span style="color:#666;font-weight:400">(short summary for listing cards)</span></label>
                <textarea name="excerpt" class="form-input" rows="3" maxlength="2000">{{ old('excerpt', $post->excerpt) }}</textarea>
            </div>

            {{-- Tab toggle: Editor / Preview --}}
            <div class="form-group">
                <label style="display:flex;justify-content:space-between;align-items:center">
                    <span>Body (Markdown) *</span>
                    <div class="tab-btns">
                        <button type="button" class="tab-btn" :class="tab === 'edit' ? 'active' : ''" @click="tab = 'edit'">Edit</button>
                        <button type="button" class="tab-btn" :class="tab === 'preview' ? 'active' : ''" @click="tab = 'preview'; renderPreview()">Preview</button>
                    </div>
                </label>

                {{-- Toolbar --}}
                <div class="editor-toolbar" x-show="tab === 'edit'">
                    <button type="button" @click="wrap('**','**')" title="Bold"><b>B</b></button>
                    <button type="button" @click="wrap('*','*')" title="Italic"><i>I</i></button>
                    <button type="button" @click="prefix('## ')" title="H2">H2</button>
                    <button type="button" @click="prefix('### ')" title="H3">H3</button>
                    <button type="button" @click="prefix('- ')" title="List">•&nbsp;List</button>
                    <button type="button" @click="prefix('1. ')" title="Ordered">1.&nbsp;List</button>
                    <button type="button" @click="prefix('> ')" title="Quote">Quote</button>
                    <button type="button" @click="wrap('`','`')" title="Code">{ }</button>
                    <button type="button" @click="wrap('[', '](https://)')" title="Link">Link</button>
                </div>

                <textarea
                    x-show="tab === 'edit'"
                    x-ref="body"
                    name="body"
                    class="editor-textarea"
                    placeholder="Tulis dalam markdown..."
                    required
                    x-init="$watch('tab', v => { if (v === 'preview') renderPreview() })"
                >{{ old('body', $post->body) }}</textarea>

                <div class="preview-pane" x-show="tab === 'preview'" x-html="rendered"></div>
            </div>
        </div>

        {{-- ── RIGHT: metadata sidebar ── --}}
        <div>
            {{-- Save buttons --}}
            <div class="sidebar-card">
                <h4>Publish</h4>
                @php
                    $status = old('action_status', $post->status ?? 'draft');
                    $statusColor = match($post->status ?? 'draft') {
                        'draft' => '#6b7280',
                        'scheduled' => '#3b82f6',
                        'published' => '#22c55e',
                        'archived' => '#ef4444',
                        default => '#666',
                    };
                @endphp
                <p style="font-size:12px;color:#888;margin-bottom:10px">
                    Status:
                    <span class="badge" style="background:{{ $statusColor }}22;color:{{ $statusColor }}">
                        {{ strtoupper($post->status ?? 'draft') }}
                    </span>
                </p>
                @if($post->exists && $post->published_at)
                    <p style="font-size:11px;color:#666;margin-bottom:10px">Published at {{ $post->published_at->format('Y-m-d H:i') }}</p>
                @endif
                <div style="display:flex;flex-direction:column;gap:6px">
                    <button type="submit" name="action" value="draft" class="btn btn-ghost btn-sm">Save Draft</button>
                    <button type="submit" name="action" value="publish" class="btn btn-gold btn-sm">Publish Now</button>
                    <button type="submit" name="action" value="schedule" class="btn btn-ghost btn-sm">Save Schedule</button>
                    @if($post->exists)
                        <button type="submit" name="action" value="archive" class="btn btn-danger btn-sm">Archive</button>
                    @endif
                </div>
            </div>

            {{-- Schedule --}}
            <div class="sidebar-card">
                <h4>Schedule For</h4>
                <input type="datetime-local" name="scheduled_for"
                       value="{{ old('scheduled_for', $post->scheduled_for ? $post->scheduled_for->format('Y-m-d\TH:i') : '') }}"
                       class="form-input">
                <p style="font-size:11px;color:#666;margin-top:6px">Cron flips scheduled posts every 5 minutes.</p>
            </div>

            {{-- Category --}}
            <div class="sidebar-card">
                <h4>Category</h4>
                <select name="category_id" class="form-input">
                    <option value="">— Tidak ada —</option>
                    @foreach($categories as $cat)
                        <option value="{{ $cat->id }}" @selected(old('category_id', $post->category_id) == $cat->id)>{{ $cat->name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Cover image (URL or storage path string) --}}
            <div class="sidebar-card">
                <h4>Cover Image</h4>
                <input type="text" name="cover_image" value="{{ old('cover_image', $post->cover_image) }}" class="form-input" placeholder="URL atau storage path">
                @if($post->cover_image)
                    <img src="{{ str_starts_with($post->cover_image, 'http') ? $post->cover_image : asset('storage/'.$post->cover_image) }}"
                         alt="Cover" style="width:100%;margin-top:10px;border-radius:6px;border:1px solid #2a2a2a">
                @endif
            </div>

            {{-- Featured --}}
            <div class="sidebar-card">
                <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
                    <span class="toggle">
                        <input type="hidden" name="is_featured" value="0">
                        <input type="checkbox" name="is_featured" value="1" @checked(old('is_featured', $post->is_featured))>
                        <span class="slider"></span>
                    </span>
                    <span style="color:#e5e5e5;font-size:13px">Featured (homepage spotlight)</span>
                </label>
            </div>

            {{-- Related movies picker --}}
            <div class="sidebar-card">
                <h4>Related Movies</h4>
                <input type="text" x-model="movieQuery" @input.debounce.300ms="searchMovies()" class="form-input" placeholder="Cari judul film...">
                <div x-show="movieResults.length > 0" x-cloak style="margin-top:8px;background:#0f0f0f;border:1px solid #2a2a2a;border-radius:6px;max-height:200px;overflow-y:auto">
                    <template x-for="m in movieResults" :key="m.id">
                        <div @click="addMovie(m)" style="padding:8px 10px;cursor:pointer;border-bottom:1px solid #1a1a1a;font-size:12px;color:#e5e5e5"
                             onmouseover="this.style.background='#1a1a1a'" onmouseout="this.style.background='transparent'">
                            <span x-text="m.title"></span>
                        </div>
                    </template>
                </div>
                <div style="margin-top:12px" x-ref="movieList">
                    <template x-for="(m, i) in selectedMovies" :key="m.id">
                        <div class="related-movie" :data-id="m.id">
                            <span class="handle">⋮⋮</span>
                            <span style="flex:1;font-size:12px" x-text="m.title"></span>
                            <button type="button" @click="removeMovie(i)" style="background:transparent;border:0;color:#ef4444;cursor:pointer;font-size:14px">×</button>
                        </div>
                    </template>
                    <p x-show="selectedMovies.length === 0" style="font-size:11px;color:#555;text-align:center;padding:10px">Belum ada film terkait.</p>
                </div>
                <template x-for="m in selectedMovies" :key="'h-'+m.id">
                    <input type="hidden" name="movie_ids[]" :value="m.id">
                </template>
            </div>

            {{-- SEO --}}
            <div class="sidebar-card">
                <h4>SEO</h4>
                <div class="form-group" style="margin-bottom:10px">
                    <label style="font-size:11px">SEO Title</label>
                    <input type="text" name="seo_title" value="{{ old('seo_title', $post->seo_title) }}" class="form-input" maxlength="200">
                </div>
                <div class="form-group" style="margin-bottom:0">
                    <label style="font-size:11px">SEO Description</label>
                    <textarea name="seo_description" rows="3" class="form-input" maxlength="1000">{{ old('seo_description', $post->seo_description) }}</textarea>
                </div>
            </div>

            {{-- AI Assist --}}
            <div class="sidebar-card">
                <h4>AI Assist</h4>
                <div style="display:flex;flex-direction:column;gap:6px">
                    <button type="button" @click="aiSuggestTitles" class="btn btn-ghost btn-sm">Suggest 5 Titles</button>
                    <button type="button" @click="aiOutline" class="btn btn-ghost btn-sm">Generate Outline</button>
                    <button type="button" @click="aiEnrich" class="btn btn-ghost btn-sm">Polish Draft (Enrich)</button>
                </div>
                <div class="ai-output" x-show="aiStatus" x-cloak x-html="aiStatus"></div>
                <div x-show="aiTitles.length > 0" x-cloak style="margin-top:8px">
                    <template x-for="t in aiTitles" :key="t">
                        <span class="ai-title-pill" @click="useTitle(t)" x-text="t"></span>
                    </template>
                </div>
            </div>
        </div>

    </div>
</form>

@push('scripts')
    {{-- marked.js for in-browser preview render. Pin to a known-good version. --}}
    <script src="https://cdn.jsdelivr.net/npm/marked@12.0.0/marked.min.js"></script>
    {{-- Sortable.js for drag-reorder of related movies. --}}
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>

    <script>
        function blogEditor() {
            return {
                tab: 'edit',
                rendered: '',
                movieQuery: '',
                movieResults: [],
                selectedMovies: @json($movies->map(fn($m) => ['id' => $m->id, 'title' => $m->title])->values()),
                aiStatus: '',
                aiTitles: [],

                init() {
                    // Initialize Sortable for drag-reorder.
                    this.$nextTick(() => {
                        if (window.Sortable && this.$refs.movieList) {
                            Sortable.create(this.$refs.movieList, {
                                handle: '.handle',
                                animation: 150,
                                onEnd: (e) => {
                                    const item = this.selectedMovies.splice(e.oldIndex, 1)[0];
                                    this.selectedMovies.splice(e.newIndex, 0, item);
                                },
                            });
                        }
                    });
                },

                renderPreview() {
                    if (!this.$refs.body || !window.marked) {
                        this.rendered = '';
                        return;
                    }
                    this.rendered = marked.parse(this.$refs.body.value || '');
                },

                wrap(before, after) {
                    const ta = this.$refs.body;
                    const start = ta.selectionStart, end = ta.selectionEnd;
                    const sel = ta.value.substring(start, end);
                    ta.value = ta.value.substring(0, start) + before + sel + after + ta.value.substring(end);
                    ta.focus();
                    ta.selectionStart = start + before.length;
                    ta.selectionEnd = end + before.length;
                },

                prefix(p) {
                    const ta = this.$refs.body;
                    const start = ta.selectionStart;
                    const lineStart = ta.value.lastIndexOf('\n', start - 1) + 1;
                    ta.value = ta.value.substring(0, lineStart) + p + ta.value.substring(lineStart);
                    ta.focus();
                    ta.selectionStart = ta.selectionEnd = start + p.length;
                },

                async searchMovies() {
                    if (this.movieQuery.length < 2) { this.movieResults = []; return; }
                    try {
                        const r = await fetch('{{ route('search.autocomplete') }}?q=' + encodeURIComponent(this.movieQuery), {
                            headers: { 'Accept': 'application/json' },
                        });
                        if (!r.ok) return;
                        const data = await r.json();
                        const items = data.results || data.movies || data.data || [];
                        // Filter out already-selected
                        const taken = new Set(this.selectedMovies.map(m => m.id));
                        this.movieResults = items
                            .filter(m => m && m.id && !taken.has(m.id))
                            .slice(0, 8)
                            .map(m => ({ id: m.id, title: m.title || m.name }));
                    } catch (e) {
                        this.movieResults = [];
                    }
                },

                addMovie(m) {
                    if (!this.selectedMovies.find(x => x.id === m.id)) {
                        this.selectedMovies.push(m);
                    }
                    this.movieQuery = '';
                    this.movieResults = [];
                },

                removeMovie(i) {
                    this.selectedMovies.splice(i, 1);
                },

                syncMovieIds() { /* hidden inputs already track via x-for */ },

                async aiPost(url, payload) {
                    this.aiStatus = '<span style="color:#C5A55A">⏳ Memanggil AI...</span>';
                    try {
                        const r = await fetch(url, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                            },
                            body: JSON.stringify(payload),
                        });
                        const data = await r.json();
                        if (!r.ok || !data.ok) {
                            this.aiStatus = '<span style="color:#ef4444">Gagal: ' + (data.message || r.status) + '</span>';
                            return null;
                        }
                        this.aiStatus = '';
                        return data;
                    } catch (e) {
                        this.aiStatus = '<span style="color:#ef4444">Gagal koneksi AI.</span>';
                        return null;
                    }
                },

                async aiSuggestTitles() {
                    const topic = document.querySelector('[name=title]').value || document.querySelector('[name=excerpt]').value;
                    if (!topic || topic.length < 3) { this.aiStatus = 'Isi judul dulu sebagai topik.'; return; }
                    const r = await this.aiPost('{{ route('admin.blog.ai.suggest-titles') }}', { topic });
                    if (r) {
                        this.aiTitles = r.titles || [];
                        if (this.aiTitles.length === 0) {
                            this.aiStatus = '<span style="color:#ef4444">AI tidak menghasilkan judul. Coba lagi.</span>';
                        }
                    }
                },

                useTitle(t) {
                    document.querySelector('[name=title]').value = t;
                    this.aiTitles = [];
                },

                async aiOutline() {
                    const brief = document.querySelector('[name=excerpt]').value || document.querySelector('[name=title]').value;
                    if (!brief || brief.length < 5) { this.aiStatus = 'Isi excerpt/title dulu sebagai brief.'; return; }
                    const r = await this.aiPost('{{ route('admin.blog.ai.outline') }}', { brief });
                    if (r && r.outline) {
                        const ta = this.$refs.body;
                        ta.value = (ta.value ? ta.value + '\n\n' : '') + r.outline;
                    } else if (r) {
                        this.aiStatus = '<span style="color:#ef4444">AI tidak menghasilkan outline.</span>';
                    }
                },

                async aiEnrich() {
                    const draft = this.$refs.body.value;
                    if (!draft || draft.length < 10) { this.aiStatus = 'Tulis draft minimal 10 karakter dulu.'; return; }
                    const r = await this.aiPost('{{ route('admin.blog.ai.enrich') }}', { draft });
                    if (r && r.enriched) {
                        this.$refs.body.value = r.enriched;
                        this.aiStatus = '<span style="color:#22c55e">✓ Draft dipoles oleh AI.</span>';
                    }
                },
            }
        }
    </script>
@endpush
