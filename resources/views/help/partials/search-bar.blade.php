{{-- Reusable search bar with Alpine-powered autocomplete dropdown.
     Drop into any page; it self-contains state and posts to /help/search
     on submit, plus calls /help/api/suggest as the user types.

     Props:
       size     — 'hero' (big, landing-page) or 'compact' (in-page)
       initial  — pre-fill value (used on the results page)
--}}
@props(['size' => 'compact', 'initial' => ''])

@php
    $isHero = $size === 'hero';
@endphp

<div x-data="helpSearchBar({{ json_encode(['initial' => (string) $initial]) }})"
     class="relative w-full {{ $isHero ? 'max-w-3xl mx-auto' : 'max-w-xl' }}">
    <form method="GET" action="{{ route('help.search') }}" @submit="lockSuggestions">
        <div class="relative">
            <input type="text"
                   name="q"
                   x-model="q"
                   @input.debounce.250ms="fetchSuggestions"
                   @focus="open = q.length > 1"
                   @keydown.escape="open = false"
                   @keydown.down.prevent="moveCursor(1)"
                   @keydown.up.prevent="moveCursor(-1)"
                   @keydown.enter="selectIfCursored"
                   autocomplete="off"
                   placeholder="Cari bantuan… (mis. cara reset kata sandi)"
                   class="w-full bg-[#141414] border border-[#2a2a2a] focus:border-[#C5A55A] text-white placeholder-gray-500 rounded-xl outline-none transition-colors {{ $isHero ? 'text-lg py-5 pl-14 pr-32' : 'py-3 pl-11 pr-24' }}">

            {{-- Magnifier --}}
            <svg class="absolute left-4 top-1/2 -translate-y-1/2 text-[#C5A55A] {{ $isHero ? 'w-6 h-6' : 'w-5 h-5' }}"
                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>

            <button type="submit"
                    class="absolute right-2 top-1/2 -translate-y-1/2 bg-[#C5A55A] hover:bg-[#d4b76a] text-black font-semibold rounded-lg transition-colors {{ $isHero ? 'px-5 py-3 text-sm' : 'px-4 py-2 text-xs' }}">
                Cari
            </button>
        </div>
    </form>

    {{-- Autocomplete dropdown --}}
    <div x-show="open && suggestions.length > 0"
         x-cloak
         x-transition.opacity
         @click.outside="open = false"
         class="absolute z-30 left-0 right-0 mt-2 bg-[#141414] border border-[#2a2a2a] rounded-xl shadow-2xl overflow-hidden">
        <template x-for="(s, i) in suggestions" :key="s.id">
            <a :href="'/help/' + s.slug"
               @mouseenter="cursor = i"
               :class="cursor === i ? 'bg-[#1f1f1f] text-white' : 'text-gray-300 hover:text-white'"
               class="block px-4 py-3 text-sm border-b border-[#2a2a2a] last:border-b-0">
                <span x-text="s.title"></span>
            </a>
        </template>
    </div>
</div>

@push('scripts')
    <script>
        if (!window._helpSearchBarRegistered) {
            window._helpSearchBarRegistered = true;
            window.helpSearchBar = function (opts) {
                return {
                    q: opts.initial || '',
                    suggestions: [],
                    open: false,
                    cursor: -1,
                    fetchSuggestions() {
                        if ((this.q || '').trim().length < 2) {
                            this.suggestions = [];
                            this.open = false;
                            return;
                        }
                        fetch('{{ route('help.suggest') }}?q=' + encodeURIComponent(this.q), {
                            headers: { 'Accept': 'application/json' },
                        })
                            .then(r => r.ok ? r.json() : { suggestions: [] })
                            .then(data => {
                                this.suggestions = data.suggestions || [];
                                this.open = this.suggestions.length > 0;
                                this.cursor = -1;
                            })
                            .catch(() => {
                                this.suggestions = [];
                                this.open = false;
                            });
                    },
                    moveCursor(delta) {
                        if (!this.open || this.suggestions.length === 0) return;
                        this.cursor = Math.max(0, Math.min(this.suggestions.length - 1, this.cursor + delta));
                    },
                    selectIfCursored(e) {
                        if (this.cursor >= 0 && this.cursor < this.suggestions.length) {
                            e.preventDefault();
                            window.location.href = '/help/' + this.suggestions[this.cursor].slug;
                        }
                    },
                    lockSuggestions() {
                        // Allow the form to submit naturally — close the panel.
                        this.open = false;
                    },
                };
            };
        }
    </script>
@endpush
