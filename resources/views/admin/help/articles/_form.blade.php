{{-- Shared editor form for create + edit. Mirrors the blog post editor
     so the editorial team has a single mental model for both surfaces. --}}
@push('styles')
    <style>
        .help-grid { display:grid; grid-template-columns: 1fr 320px; gap:24px; }
        @media (max-width: 1024px) { .help-grid { grid-template-columns: 1fr; } }
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
        .tag-chip { display:inline-flex; align-items:center; gap:6px; background:rgba(197,165,90,0.1); border:1px solid rgba(197,165,90,0.3); padding:4px 10px; margin:2px; border-radius:14px; color:#E8D5A3; font-size:12px; }
        .tag-chip button { background:transparent; border:0; color:#E8D5A3; cursor:pointer; font-size:14px; line-height:1; padding:0; }
        .ai-output { background:#0f0f0f; border:1px solid #2a2a2a; border-radius:6px; padding:10px; margin-top:8px; font-size:12px; color:#aaa; max-height:200px; overflow-y:auto; white-space:pre-wrap; }
        .ai-title-pill { display:inline-block; background:rgba(197,165,90,0.1); border:1px solid rgba(197,165,90,0.3); padding:6px 10px; margin:4px 4px 0 0; border-radius:6px; color:#E8D5A3; font-size:12px; cursor:pointer; }
        .ai-title-pill:hover { background:rgba(197,165,90,0.25); color:#fff; }
    </style>
@endpush

@php
    // Pre-serialise existing tags so the Alpine chip component can hydrate
    // without splitting strings client-side.
    $initialTags = old('tags');
    if ($initialTags === null) {
        $initialTags = is_array($article->tags) ? implode(',', $article->tags) : '';
    }
@endphp

<form method="POST" action="{{ $formAction }}" x-data="helpEditor({{ json_encode(['initialTags' => $initialTags]) }})">
    @csrf
    @if($method === 'PUT')@method('PUT')@endif

    @if($errors->any())
        <div style="background:rgba(220,38,38,0.15);border:1px solid rgba(220,38,38,0.3);color:#ef4444;padding:12px 20px;border-radius:8px;margin-bottom:20px;font-size:13px">
            <ul style="margin-left:18px">
                @foreach($errors->all() as $err)<li>{{ $err }}</li>@endforeach
            </ul>
        </div>
    @endif

    <div class="help-grid">

        {{-- ── LEFT: title + markdown editor ── --}}
        <div>
            <div class="form-group">
                <label>Title *</label>
                <input type="text" name="title" value="{{ old('title', $article->title) }}" class="form-input" required maxlength="200">
            </div>

            <div class="form-group">
                <label>Slug <span style="color:#666;font-weight:400">(auto-generated if empty)</span></label>
                <input type="text" name="slug" value="{{ old('slug', $article->slug) }}" class="form-input" maxlength="160" placeholder="cara-mendaftar-akun">
            </div>

            <div class="form-group">
                <label>Excerpt <span style="color:#666;font-weight:400">(short summary for listing cards)</span></label>
                <textarea name="excerpt" class="form-input" rows="3" maxlength="2000">{{ old('excerpt', $article->excerpt) }}</textarea>
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

                <div class="editor-toolbar" x-show="tab === 'edit'">
                    <button type="button" @click="wrap('**','**')" title="Bold"><b>B</b></button>
                    <button type="button" @click="wrap('*','*')" title="Italic"><i>I</i></button>
                    <button type="button" @click="prefix('## ')" title="H2">H2</button>
                    <button type="button" @click="prefix('### ')" title="H3">H3</button>
                    <button type="button" @click="prefix('- ')" title="List">- List</button>
                    <button type="button" @click="prefix('1. ')" title="Ordered">1. List</button>
                    <button type="button" @click="prefix('> ')" title="Quote">Quote</button>
                    <button type="button" @click="wrap('`','`')" title="Code">{ }</button>
                    <button type="button" @click="wrap('[', '](https://)')" title="Link">Link</button>
                </div>

                <textarea
                    x-show="tab === 'edit'"
                    x-ref="body"
                    name="body"
                    class="editor-textarea"
                    placeholder="Tulis dalam markdown. Gunakan ## untuk pertanyaan FAQ — sistem akan mendeteksi pola Q/A untuk schema FAQPage."
                    required
                >{{ old('body', $article->body) }}</textarea>

                <div class="preview-pane" x-show="tab === 'preview'" x-html="rendered"></div>
            </div>
        </div>

        {{-- ── RIGHT: metadata sidebar ── --}}
        <div>
            {{-- Save buttons --}}
            <div class="sidebar-card">
                <h4>Publish</h4>
                @php
                    $statusColor = $article->status === 'published' ? '#22c55e' : '#6b7280';
                @endphp
                <p style="font-size:12px;color:#888;margin-bottom:10px">
                    Status:
                    <span class="badge" style="background:{{ $statusColor }}22;color:{{ $statusColor }}">
                        {{ strtoupper($article->status ?? 'draft') }}
                    </span>
                </p>
                <div style="display:flex;flex-direction:column;gap:6px">
                    <button type="submit" name="action" value="save" class="btn btn-gold btn-sm">Save</button>
                    <button type="submit" name="action" value="draft" class="btn btn-ghost btn-sm">Save as Draft</button>
                    <button type="submit" name="action" value="publish" class="btn btn-gold btn-sm">Publish Now</button>
                </div>
            </div>

            {{-- Category --}}
            <div class="sidebar-card">
                <h4>Category</h4>
                <select name="category_id" class="form-input">
                    <option value="">— Tidak ada —</option>
                    @foreach($categories as $cat)
                        <option value="{{ $cat->id }}" @selected(old('category_id', $article->category_id) == $cat->id)>{{ $cat->name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Sort order --}}
            <div class="sidebar-card">
                <h4>Display Order</h4>
                <input type="number" name="sort_order" value="{{ old('sort_order', $article->sort_order ?? 0) }}" class="form-input">
                <p style="font-size:11px;color:#666;margin-top:6px">Lower number = appears higher in the category list.</p>
            </div>

            {{-- Tags (chip input) --}}
            <div class="sidebar-card">
                <h4>Tags</h4>
                <div style="margin-bottom:8px">
                    <template x-for="(t, i) in tags" :key="t + i">
                        <span class="tag-chip">
                            <span x-text="t"></span>
                            <button type="button" @click="removeTag(i)" title="Hapus">&times;</button>
                        </span>
                    </template>
                </div>
                <input type="text" x-model="tagInput" @keydown.enter.prevent="addTag" @keydown.comma.prevent="addTag" class="form-input" placeholder="Ketik tag lalu Enter atau koma">
                {{-- Hidden field carries the comma-joined list to the controller. --}}
                <input type="hidden" name="tags" :value="tags.join(',')">
                <p style="font-size:11px;color:#666;margin-top:6px">Tag membantu fitur "Artikel terkait" pada halaman publik.</p>
            </div>

            {{-- Last reviewed --}}
            <div class="sidebar-card">
                <h4>Last Reviewed</h4>
                <input type="datetime-local" name="last_reviewed_at"
                       value="{{ old('last_reviewed_at', $article->last_reviewed_at ? $article->last_reviewed_at->format('Y-m-d\TH:i') : '') }}"
                       class="form-input">
                <p style="font-size:11px;color:#666;margin-top:6px">Ditampilkan publik untuk membangun kepercayaan akan kesegaran artikel.</p>
            </div>

            {{-- AI Assist --}}
            <div class="sidebar-card">
                <h4>AI Assist</h4>
                <div class="form-group" style="margin-bottom:8px">
                    <label style="font-size:11px">Pertanyaan pengguna (untuk Suggest / Draft)</label>
                    <textarea x-model="aiQuestion" rows="2" class="form-input" placeholder="contoh: bagaimana cara reset kata sandi?"></textarea>
                </div>
                <div style="display:flex;flex-direction:column;gap:6px">
                    <button type="button" @click="aiSuggestTitle" class="btn btn-ghost btn-sm">Suggest 3 Titles</button>
                    <button type="button" @click="aiDraftAnswer" class="btn btn-ghost btn-sm">Draft Answer</button>
                    <button type="button" @click="aiImprove" class="btn btn-ghost btn-sm">Polish Existing</button>
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
    <script src="https://cdn.jsdelivr.net/npm/marked@12.0.0/marked.min.js"></script>
    <script>
        function helpEditor(opts) {
            return {
                tab: 'edit',
                rendered: '',
                tags: (opts.initialTags || '')
                    .split(',')
                    .map(t => t.trim())
                    .filter(t => t.length > 0),
                tagInput: '',
                aiQuestion: '',
                aiStatus: '',
                aiTitles: [],

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

                addTag() {
                    const t = (this.tagInput || '').trim().replace(/,$/, '');
                    if (t && t.length <= 40 && !this.tags.includes(t)) {
                        this.tags.push(t);
                    }
                    this.tagInput = '';
                },

                removeTag(i) {
                    this.tags.splice(i, 1);
                },

                async aiPost(url, payload) {
                    this.aiStatus = '<span style="color:#C5A55A">Memanggil AI...</span>';
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

                async aiSuggestTitle() {
                    const q = (this.aiQuestion || '').trim();
                    if (q.length < 3) { this.aiStatus = 'Isi pertanyaan pengguna dulu (min 3 karakter).'; return; }
                    const r = await this.aiPost('{{ route('admin.help.ai.suggest-title') }}', { question: q });
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

                async aiDraftAnswer() {
                    const q = (this.aiQuestion || '').trim();
                    if (q.length < 5) { this.aiStatus = 'Isi pertanyaan pengguna dulu (min 5 karakter).'; return; }
                    const r = await this.aiPost('{{ route('admin.help.ai.draft-answer') }}', { question: q });
                    if (r && r.draft) {
                        const ta = this.$refs.body;
                        ta.value = (ta.value ? ta.value + '\n\n' : '') + r.draft;
                        this.aiStatus = '<span style="color:#22c55e">Draft ditambahkan ke body.</span>';
                    } else if (r) {
                        this.aiStatus = '<span style="color:#ef4444">AI tidak menghasilkan draft.</span>';
                    }
                },

                async aiImprove() {
                    const existing = this.$refs.body.value;
                    if (!existing || existing.length < 10) { this.aiStatus = 'Tulis draft minimal 10 karakter dulu.'; return; }
                    const r = await this.aiPost('{{ route('admin.help.ai.improve') }}', { existing });
                    if (r && r.improved) {
                        this.$refs.body.value = r.improved;
                        this.aiStatus = '<span style="color:#22c55e">Artikel dipoles oleh AI (termasuk Pertanyaan Umum).</span>';
                    }
                },
            }
        }
    </script>
@endpush
