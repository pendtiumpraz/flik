@props([
    'initial'    => '',
    'standalone' => false,  // true → full-width / bigger sizing for the results page; false → compact header form
    'placeholder' => 'Cari film, aktor, vibe, tahun…',
])

@php
    $endpoint     = url('/search');
    $autocompleteEndpoint = url('/api/search/autocomplete');
    $inputId      = 'smart-search-' . uniqid();
@endphp

<div class="smart-search-wrap relative {{ $standalone ? 'w-full' : 'w-64 xl:w-72' }}"
     x-data="smartSearchBar({
        endpoint: @js($endpoint),
        autocompleteEndpoint: @js($autocompleteEndpoint),
        initial: @js($initial),
     })"
     x-on:keydown.escape.window="close()">

    {{-- ── The form ──────────────────────────────────────────── --}}
    <form method="GET" :action="endpoint" @submit="onSubmit($event)"
          class="relative">
        <div class="relative flex items-center"
             :class="standaloneClass">

            {{-- search icon --}}
            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-[#C5A55A]/70 pointer-events-none z-10"
                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>

            <input
                id="{{ $inputId }}"
                name="q"
                type="text"
                x-model="query"
                x-on:focus="onFocus()"
                x-on:input.debounce.250ms="fetchSuggestions()"
                x-on:keydown.arrow-down.prevent="moveDown()"
                x-on:keydown.arrow-up.prevent="moveUp()"
                x-on:keydown.enter="onEnter($event)"
                placeholder="{{ $placeholder }}"
                autocomplete="off"
                spellcheck="false"
                @class([
                    'w-full pl-9 pr-9 text-white placeholder-gray-500 transition-colors focus:outline-none',
                    'rounded-lg bg-white/10 border border-white/10 focus:border-[#C5A55A]/50 py-2 text-sm' => !$standalone,
                    'rounded-xl py-3.5 md:py-4 text-sm md:text-base' => $standalone,
                ])
                @if($standalone)
                    style="background: rgba(20,18,16,0.7); border: 1px solid rgba(197,165,90,0.3); box-shadow: 0 8px 32px -8px rgba(197,165,90,0.15)"
                @endif
            >

            {{-- clear / spinner --}}
            <button type="button"
                    x-show="query.length > 0 && !loading"
                    x-on:click="clear()"
                    class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 hover:text-[#C5A55A] transition-colors z-10"
                    title="Clear">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
            <div x-show="loading"
                 class="absolute right-3 top-1/2 -translate-y-1/2 z-10">
                <div class="h-3.5 w-3.5 rounded-full border-2 border-[#C5A55A]/30 border-t-[#C5A55A] animate-spin"></div>
            </div>
        </div>
    </form>

    {{-- ── Dropdown ──────────────────────────────────────────── --}}
    <div x-cloak x-show="open && (suggestions.movies.length > 0 || suggestions.people.length > 0 || (query.length >= 2 && !loading))"
         x-on:click.away="close()"
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0 translate-y-1"
         x-transition:enter-end="opacity-100 translate-y-0"
         class="absolute left-0 right-0 mt-2 rounded-xl overflow-hidden shadow-2xl z-50 max-h-[70vh] overflow-y-auto"
         style="background: linear-gradient(180deg, #1a1a1a 0%, #141210 100%); border: 1px solid rgba(197,165,90,0.25)">

        {{-- Films section --}}
        <template x-if="suggestions.movies.length > 0">
            <div>
                <div class="px-4 py-2 text-[10px] font-semibold uppercase tracking-wider border-b"
                     style="color: #C5A55A; border-color: rgba(197,165,90,0.15); background: rgba(197,165,90,0.04)">
                    Film
                </div>
                <ul>
                    <template x-for="(m, i) in suggestions.movies" :key="'m-' + m.id">
                        <li>
                            <a :href="m.url"
                               x-on:mouseenter="activeIndex = i"
                               :class="activeIndex === i ? 'bg-[#C5A55A]/10' : ''"
                               class="flex items-center gap-3 px-4 py-2.5 hover:bg-[#C5A55A]/10 transition-colors">
                                <template x-if="m.poster_url">
                                    <img :src="m.poster_url" :alt="m.title"
                                         class="w-8 h-12 rounded object-cover flex-shrink-0"
                                         loading="lazy">
                                </template>
                                <template x-if="!m.poster_url">
                                    <div class="w-8 h-12 rounded flex-shrink-0 flex items-center justify-center"
                                         style="background: rgba(197,165,90,0.1)">
                                        <svg class="w-4 h-4 text-[#C5A55A]/60" fill="currentColor" viewBox="0 0 20 20"><path d="M2 6a2 2 0 012-2h12a2 2 0 012 2v8a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/></svg>
                                    </div>
                                </template>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm text-white truncate" x-text="m.title"></p>
                                    <p class="text-[10px] text-gray-500" x-show="m.year" x-text="m.year"></p>
                                </div>
                            </a>
                        </li>
                    </template>
                </ul>
            </div>
        </template>

        {{-- People section --}}
        <template x-if="suggestions.people.length > 0">
            <div>
                <div class="px-4 py-2 text-[10px] font-semibold uppercase tracking-wider border-b border-t"
                     style="color: #C5A55A; border-color: rgba(197,165,90,0.15); background: rgba(197,165,90,0.04)">
                    Aktor / Sutradara
                </div>
                <ul>
                    <template x-for="(p, i) in suggestions.people" :key="'p-' + p.id">
                        <li>
                            <a :href="p.search_url"
                               x-on:mouseenter="activeIndex = suggestions.movies.length + i"
                               :class="activeIndex === (suggestions.movies.length + i) ? 'bg-[#C5A55A]/10' : ''"
                               class="flex items-center gap-3 px-4 py-2.5 hover:bg-[#C5A55A]/10 transition-colors">
                                <template x-if="p.profile_url">
                                    <img :src="p.profile_url" :alt="p.name"
                                         class="w-8 h-8 rounded-full object-cover flex-shrink-0"
                                         loading="lazy">
                                </template>
                                <template x-if="!p.profile_url">
                                    <div class="w-8 h-8 rounded-full flex-shrink-0 flex items-center justify-center text-xs font-semibold text-black"
                                         style="background: linear-gradient(135deg, #C5A55A, #E8D5A3)">
                                        <span x-text="p.name.charAt(0).toUpperCase()"></span>
                                    </div>
                                </template>
                                <p class="text-sm text-white truncate flex-1" x-text="p.name"></p>
                                <svg class="w-3.5 h-3.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                </svg>
                            </a>
                        </li>
                    </template>
                </ul>
            </div>
        </template>

        {{-- Empty state --}}
        <template x-if="!loading && query.length >= 2 && suggestions.movies.length === 0 && suggestions.people.length === 0">
            <div class="px-4 py-6 text-center">
                <p class="text-sm text-gray-400">
                    Tidak ada hasil cepat untuk
                    "<span class="text-white" x-text="query"></span>"
                </p>
                <p class="text-xs text-gray-600 mt-2">
                    Tekan Enter untuk smart search (AI akan analisa intent).
                </p>
            </div>
        </template>

        {{-- Submit / "see all" footer --}}
        <template x-if="query.length >= 2">
            <a :href="endpoint + '?q=' + encodeURIComponent(query)"
               class="block px-4 py-2.5 text-center text-xs font-bold uppercase tracking-wider transition-colors border-t"
               style="color: #C5A55A; border-color: rgba(197,165,90,0.15); background: rgba(197,165,90,0.04)">
                Lihat semua hasil →
            </a>
        </template>
    </div>
</div>

@once
<script>
    function smartSearchBar({ endpoint, autocompleteEndpoint, initial }) {
        return {
            endpoint,
            autocompleteEndpoint,
            query: initial || '',
            open: false,
            loading: false,
            activeIndex: -1,
            suggestions: { movies: [], people: [] },
            standaloneClass: '',
            _ctrl: null,

            init() {
                // Restore standalone-class flag from data attribute if needed; nothing to do otherwise.
            },

            onFocus() {
                if (this.query.length >= 2 && (this.suggestions.movies.length || this.suggestions.people.length)) {
                    this.open = true;
                }
            },

            async fetchSuggestions() {
                const q = this.query.trim();
                if (q.length < 2) {
                    this.suggestions = { movies: [], people: [] };
                    this.open = false;
                    this.activeIndex = -1;
                    return;
                }

                // Cancel in-flight request
                if (this._ctrl) {
                    try { this._ctrl.abort(); } catch (e) { /* ignore */ }
                }
                this._ctrl = new AbortController();
                this.loading = true;
                this.open = true;

                try {
                    const url = this.autocompleteEndpoint + '?q=' + encodeURIComponent(q);
                    const res = await fetch(url, {
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                        signal: this._ctrl.signal,
                    });
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    const data = await res.json();
                    this.suggestions = {
                        movies: Array.isArray(data.movies) ? data.movies : [],
                        people: Array.isArray(data.people) ? data.people : [],
                    };
                    this.activeIndex = -1;
                } catch (e) {
                    if (e.name !== 'AbortError') {
                        // silent fail — keep suggestions as-is
                    }
                } finally {
                    this.loading = false;
                }
            },

            onSubmit(e) {
                if (this.query.trim().length < 2) {
                    e.preventDefault();
                }
                this.close();
            },

            onEnter(e) {
                // If a suggestion is highlighted, follow that link instead of submitting
                if (this.activeIndex >= 0) {
                    e.preventDefault();
                    const all = [...this.suggestions.movies, ...this.suggestions.people];
                    const item = all[this.activeIndex];
                    if (item) {
                        window.location.href = item.url || item.search_url;
                        return;
                    }
                }
                // otherwise let the form submit normally
            },

            moveDown() {
                const total = this.suggestions.movies.length + this.suggestions.people.length;
                if (total === 0) return;
                this.open = true;
                this.activeIndex = (this.activeIndex + 1) % total;
            },

            moveUp() {
                const total = this.suggestions.movies.length + this.suggestions.people.length;
                if (total === 0) return;
                this.open = true;
                this.activeIndex = (this.activeIndex - 1 + total) % total;
            },

            clear() {
                this.query = '';
                this.suggestions = { movies: [], people: [] };
                this.open = false;
                this.activeIndex = -1;
            },

            close() {
                this.open = false;
                this.activeIndex = -1;
            },
        }
    }
</script>
@endonce
