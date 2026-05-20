@php
    /**
     * Shared form fields for the create + edit forms. Receives a nullable
     * `$list` (null on create) and the `$visibilities` taxonomy.
     *
     * Cover picker is implemented as a searchable typeahead via the
     * /api/search/autocomplete endpoint (peer DEV swarm) — on selection,
     * the hidden cover_movie_id is populated.
     */
    $isEdit = isset($list) && $list !== null;
    $currentVisibility = old('visibility', $isEdit ? $list->visibility : 'public');
    $coverId = old('cover_movie_id', $isEdit ? $list->cover_movie_id : null);
    $coverPreview = $isEdit && $list->cover
        ? [
            'id' => $list->cover->id,
            'title' => $list->cover->title,
            'poster' => $list->cover->poster_url,
        ]
        : null;
@endphp

@if ($errors->any())
    <div class="rounded-lg px-4 py-3 text-sm text-red-300"
         style="background: rgba(220,38,38,0.08); border: 1px solid rgba(220,38,38,0.3);">
        <ul class="list-disc list-inside space-y-0.5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

{{-- Title --}}
<div>
    <label for="title" class="block text-xs font-semibold uppercase tracking-wider text-[#C5A55A]/80 mb-2">Judul List <span class="text-red-400">*</span></label>
    <input type="text" id="title" name="title" required maxlength="160"
           value="{{ old('title', $isEdit ? $list->title : '') }}"
           placeholder="contoh: Film Horor Indonesia Terbaik 2020-2025"
           class="w-full px-3 py-2.5 rounded-lg text-sm text-white placeholder-gray-500"
           style="background: rgba(20,18,16,0.6); border: 1px solid rgba(197,165,90,0.18);">
</div>

{{-- Description --}}
<div>
    <label for="description" class="block text-xs font-semibold uppercase tracking-wider text-[#C5A55A]/80 mb-2">Deskripsi</label>
    <textarea id="description" name="description" rows="4" maxlength="5000"
              placeholder="Ceritakan tentang list ini..."
              class="w-full px-3 py-2.5 rounded-lg text-sm text-white placeholder-gray-500"
              style="background: rgba(20,18,16,0.6); border: 1px solid rgba(197,165,90,0.18);">{{ old('description', $isEdit ? $list->description : '') }}</textarea>
    <p class="mt-1 text-[11px] text-gray-600">Maks 5000 karakter.</p>
</div>

{{-- Visibility --}}
<div>
    <span class="block text-xs font-semibold uppercase tracking-wider text-[#C5A55A]/80 mb-2">Visibilitas</span>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
        @php
            $visibilityMeta = [
                'public'   => ['icon' => 'eye',     'label' => 'Public',   'desc' => 'Bisa ditemukan & diikuti siapa saja.'],
                'unlisted' => ['icon' => 'gem',     'label' => 'Unlisted', 'desc' => 'Hanya yang punya link yang bisa lihat.'],
                'private'  => ['icon' => 'shield',  'label' => 'Private',  'desc' => 'Hanya kamu yang bisa lihat.'],
            ];
        @endphp
        @foreach($visibilities as $v)
            @php $meta = $visibilityMeta[$v] ?? ['icon' => 'eye', 'label' => ucfirst($v), 'desc' => '']; @endphp
            <label class="relative cursor-pointer">
                <input type="radio" name="visibility" value="{{ $v }}" class="peer sr-only"
                       @checked($currentVisibility === $v)>
                <div class="p-3 rounded-lg transition-colors peer-checked:border-[#C5A55A] peer-checked:bg-[#C5A55A]/10"
                     style="background: rgba(20,18,16,0.6); border: 1px solid rgba(197,165,90,0.18);">
                    <div class="flex items-center gap-2 text-sm font-semibold text-white">
                        <x-icon :name="$meta['icon']" :size="14" class="text-[#C5A55A]" />
                        {{ $meta['label'] }}
                    </div>
                    @if($meta['desc'])
                        <p class="mt-1 text-[11px] text-gray-500">{{ $meta['desc'] }}</p>
                    @endif
                </div>
            </label>
        @endforeach
    </div>
</div>

{{-- Cover movie picker (typeahead) --}}
<div x-data="listCoverPicker(@js($coverPreview))" x-init="init()">
    <span class="block text-xs font-semibold uppercase tracking-wider text-[#C5A55A]/80 mb-2">Cover Film (opsional)</span>

    <input type="hidden" name="cover_movie_id" :value="selected?.id ?? ''" value="{{ $coverId }}">

    <template x-if="selected">
        <div class="flex items-center gap-3 p-3 rounded-lg mb-2"
             style="background: rgba(197,165,90,0.08); border: 1px solid rgba(197,165,90,0.3);">
            <img :src="selected.poster" alt="" class="h-14 w-10 object-cover rounded">
            <div class="flex-1 min-w-0">
                <div class="text-sm font-semibold text-white truncate" x-text="selected.title"></div>
                <button type="button" @click="clear()" class="text-[11px] text-red-400 hover:text-red-300 mt-1">
                    Hapus cover
                </button>
            </div>
        </div>
    </template>

    <div class="relative">
        <input type="text" x-model="query" @input.debounce.250ms="search()"
               placeholder="Ketik judul film untuk dijadikan cover..."
               class="w-full px-3 py-2.5 rounded-lg text-sm text-white placeholder-gray-500"
               style="background: rgba(20,18,16,0.6); border: 1px solid rgba(197,165,90,0.18);">

        <div x-show="results.length > 0" x-cloak
             class="absolute z-20 mt-1 w-full rounded-lg overflow-hidden shadow-2xl max-h-72 overflow-y-auto"
             style="background: #141414; border: 1px solid rgba(197,165,90,0.3);">
            <template x-for="r in results" :key="r.id">
                <button type="button" @click="pick(r)"
                        class="w-full flex items-center gap-3 px-3 py-2 text-left hover:bg-[#C5A55A]/10 transition-colors">
                    <img :src="r.poster ?? '/images/no-poster.png'" alt="" class="h-10 w-7 object-cover rounded">
                    <span class="text-sm text-white truncate" x-text="r.title"></span>
                </button>
            </template>
        </div>
    </div>
    <p class="mt-1 text-[11px] text-gray-600">Kalau kosong, cover akan otomatis dibuat dari mosaik 4 poster pertama.</p>
</div>

<script>
function listCoverPicker(initialSelected) {
    return {
        query: '',
        results: [],
        selected: initialSelected,
        controller: null,
        init() {},
        async search() {
            const q = this.query.trim();
            if (q.length < 2) { this.results = []; return; }
            try {
                this.controller?.abort();
                this.controller = new AbortController();
                const res = await fetch(`/api/search/autocomplete?q=${encodeURIComponent(q)}`, {
                    headers: { 'Accept': 'application/json' },
                    signal: this.controller.signal,
                });
                if (!res.ok) { this.results = []; return; }
                const json = await res.json();
                // Normalise — autocomplete returns either {results: [...]} or [...]
                const items = Array.isArray(json) ? json : (json.results ?? json.movies ?? []);
                this.results = items.slice(0, 8).map(m => ({
                    id: m.id,
                    title: m.title,
                    poster: m.poster_url ?? m.poster ?? null,
                }));
            } catch (e) {
                if (e.name !== 'AbortError') this.results = [];
            }
        },
        pick(r) {
            this.selected = r;
            this.query = '';
            this.results = [];
        },
        clear() {
            this.selected = null;
        },
    };
}
</script>
