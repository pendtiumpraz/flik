<x-admin.layout title="TMDB Bulk Import">

    <div style="max-width:900px;margin:0 auto">
        <div style="margin-bottom:20px">
            <a href="{{ route('admin.tmdb.index') }}" style="font-size:13px;color:#888;text-decoration:none">← Back to TMDB Wizard</a>
            <h2 style="font-size:24px;margin:6px 0 4px">Bulk TMDB Import</h2>
            <p style="color:#777;font-size:13px;margin:0">
                Paste a list of TMDB IDs (one per line, or comma-separated). Max <strong>{{ $limit }}</strong> per batch. Each ID is dispatched as a background job 2s apart.
            </p>
        </div>

        @if(!$enabled)
            <div style="background:rgba(245,158,11,0.12);border:1px solid rgba(245,158,11,0.35);color:#f59e0b;padding:18px 22px;border-radius:12px;margin-bottom:24px">
                TMDB API key not configured. Set <code style="background:#1a1a1a;padding:1px 6px;border-radius:4px">TMDB_KEY</code> in <code style="background:#1a1a1a;padding:1px 6px;border-radius:4px">.env</code> first.
            </div>
        @endif

        <div x-data="tmdbBulk()" x-init="init()" style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:22px">

            <div style="display:flex;gap:8px;margin-bottom:16px">
                <button type="button" @click="type='movie'"
                        :class="type === 'movie' ? 'btn-gold' : 'btn-ghost'" class="btn btn-sm" style="flex:1">
                    Movie
                </button>
                <button type="button" @click="type='tv'"
                        :class="type === 'tv' ? 'btn-gold' : 'btn-ghost'" class="btn btn-sm" style="flex:1">
                    TV Series
                </button>
            </div>

            <div class="form-group">
                <label>TMDB IDs (one per line, or comma-separated)</label>
                <textarea class="form-input" rows="10" x-model="idsText" placeholder="438631&#10;693134&#10;299536"
                          style="font-family:monospace;font-size:13px"></textarea>
                <div style="font-size:11px;color:#666;margin-top:6px">
                    Detected: <strong style="color:#C5A55A" x-text="parsedIds.length"></strong> id(s)
                    <span x-show="parsedIds.length > {{ $limit }}" style="color:#ef4444;margin-left:8px">over limit!</span>
                </div>
            </div>

            <div style="display:flex;flex-wrap:wrap;gap:12px;margin:12px 0 18px">
                <label style="display:flex;align-items:center;gap:6px;font-size:13px;color:#ddd;cursor:pointer">
                    <input type="checkbox" x-model="options.download_images" style="accent-color:#C5A55A"> Download images
                </label>
                <label style="display:flex;align-items:center;gap:6px;font-size:13px;color:#ddd;cursor:pointer">
                    <input type="checkbox" x-model="options.translate_synopsis" style="accent-color:#C5A55A"> Translate synopsis
                </label>
                <label style="display:flex;align-items:center;gap:6px;font-size:13px;color:#ddd;cursor:pointer">
                    <input type="checkbox" x-model="options.overwrite_fields" style="accent-color:#C5A55A"> Overwrite existing fields
                </label>
            </div>

            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <button type="button" class="btn btn-ghost" @click="validateIds()" :disabled="!enabled || parsedIds.length === 0 || validating">
                    <span x-show="!validating">Validate IDs</span>
                    <span x-show="validating" x-cloak>Validating…</span>
                </button>
                <button type="button" class="btn btn-gold" @click="queueAll()"
                        :disabled="!enabled || parsedIds.length === 0 || parsedIds.length > {{ $limit }} || queueing">
                    <span x-show="!queueing">Queue All</span>
                    <span x-show="queueing" x-cloak>Dispatching…</span>
                </button>
            </div>

            <template x-if="message">
                <div style="margin-top:18px;padding:12px 16px;border-radius:8px;font-size:13px"
                     :class="messageType === 'error' ? '' : ''"
                     :style="messageType === 'error'
                         ? 'background:rgba(220,38,38,0.15);border:1px solid rgba(220,38,38,0.3);color:#ef4444'
                         : 'background:rgba(34,197,94,0.15);border:1px solid rgba(34,197,94,0.3);color:#22c55e'"
                     x-text="message" x-cloak></div>
            </template>

            <template x-if="validationResults.length > 0">
                <div style="margin-top:18px;background:#0f0f0f;border:1px solid #2a2a2a;border-radius:8px" x-cloak>
                    <div style="padding:10px 14px;font-size:13px;font-weight:500;color:#aaa;border-bottom:1px solid #2a2a2a">
                        Validation sample (<span x-text="validationResults.length"></span> ids)
                    </div>
                    <div style="max-height:280px;overflow-y:auto">
                        <template x-for="vr in validationResults" :key="vr.tmdb_id">
                            <div style="display:flex;gap:10px;align-items:center;padding:8px 14px;border-bottom:1px solid #1a1a1a">
                                <span style="font-family:monospace;color:#888;font-size:12px;min-width:60px" x-text="vr.tmdb_id"></span>
                                <template x-if="vr.found">
                                    <span style="flex:1;font-size:13px;color:#e5e5e5">
                                        <span x-text="vr.title"></span>
                                        <span x-show="vr.year" style="color:#666;font-size:11px" x-text="' (' + vr.year + ')'"></span>
                                        <template x-if="vr.already_imported">
                                            <span style="margin-left:8px;color:#60a5fa;font-size:10px">[already imported]</span>
                                        </template>
                                    </span>
                                </template>
                                <template x-if="!vr.found">
                                    <span style="flex:1;color:#ef4444;font-size:12px">Not found on TMDB</span>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>
            </template>
        </div>
    </div>

    @push('scripts')
    <script>
    function tmdbBulk() {
        return {
            enabled: @json($enabled),
            type: 'movie',
            idsText: '',
            options: { download_images: true, translate_synopsis: false, overwrite_fields: false },
            validating: false,
            queueing: false,
            message: '',
            messageType: '',
            validationResults: [],
            init() {
                this.csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            },
            get parsedIds() {
                return [...new Set(
                    this.idsText
                        .split(/[\s,;]+/)
                        .map(s => s.trim())
                        .filter(s => /^\d+$/.test(s))
                        .map(Number)
                )];
            },
            async validateIds() {
                if (!this.enabled || this.parsedIds.length === 0) return;
                this.validating = true;
                this.validationResults = [];
                this.message = '';
                // Sample up to 10 ids to avoid hammering TMDB; rest is taken on faith.
                const sample = this.parsedIds.slice(0, 10);
                for (const id of sample) {
                    try {
                        const url = new URL(@json(route('admin.tmdb.preview')), window.location.origin);
                        url.searchParams.set('tmdb_id', id);
                        url.searchParams.set('type', this.type);
                        const resp = await fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                        if (!resp.ok) {
                            this.validationResults.push({ tmdb_id: id, found: false });
                            continue;
                        }
                        const data = await resp.json();
                        const p = data.preview || {};
                        this.validationResults.push({
                            tmdb_id: id,
                            found: true,
                            title: p.title,
                            year: (p.release_date || '').slice(0,4),
                            already_imported: !!p.already_imported,
                        });
                    } catch (e) {
                        this.validationResults.push({ tmdb_id: id, found: false });
                    }
                }
                this.validating = false;
            },
            async queueAll() {
                if (!this.enabled || this.parsedIds.length === 0) return;
                this.queueing = true;
                this.message = '';
                try {
                    const resp = await fetch(@json(route('admin.tmdb.bulk-import')), {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': this.csrf,
                        },
                        body: JSON.stringify({
                            ids: this.parsedIds,
                            type: this.type,
                            options: this.options,
                        }),
                    });
                    const data = await resp.json().catch(() => ({}));
                    if (!resp.ok) {
                        this.message = data.message || ('Failed (HTTP ' + resp.status + ')');
                        this.messageType = 'error';
                        return;
                    }
                    this.message = data.message || (this.parsedIds.length + ' jobs queued.');
                    this.messageType = 'success';
                } catch (e) {
                    this.message = 'Network error: ' + e.message;
                    this.messageType = 'error';
                } finally {
                    this.queueing = false;
                }
            },
        };
    }
    </script>
    @endpush
</x-admin.layout>
