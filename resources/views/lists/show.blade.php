@php
    /** @var \App\Models\UserList $list */
    /** @var \App\Models\User $owner */
    /** @var \Illuminate\Database\Eloquent\Collection $items */
    $userKey = $owner->username ?? (string) $owner->id;

    // Hero source — explicit cover, else first item's backdrop, else fallback.
    $heroUrl = $list->cover?->backdrop_url
        ?? $list->cover?->poster_url
        ?? $items->first()?->movie?->backdrop_url
        ?? $items->first()?->movie?->poster_url;

    // Mosaic source when no hero (4 first posters).
    $mosaic = $heroUrl
        ? collect()
        : $items->take(4)->map(fn ($i) => $i->movie)->filter()->values();

    $visibilityMeta = match ($list->visibility) {
        \App\Models\UserList::VISIBILITY_PUBLIC   => ['icon' => 'eye',    'label' => 'Public'],
        \App\Models\UserList::VISIBILITY_UNLISTED => ['icon' => 'gem',    'label' => 'Unlisted'],
        default => ['icon' => 'shield', 'label' => 'Private'],
    };
@endphp

<x-layout :title="$list->title . ' — FLiK'" :description="$list->description">
    <div class="min-h-screen bg-black pt-16 pb-20">

        {{-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ --}}
        {{-- HERO — backdrop or 4-poster mosaic + title + owner + actions --}}
        {{-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ --}}
        <div class="relative w-full overflow-hidden" style="min-height: 360px;">
            <div class="absolute inset-0">
                @if($heroUrl)
                    <img src="{{ $heroUrl }}" alt="" class="w-full h-full object-cover"
                         style="filter: brightness(0.55);">
                @elseif($mosaic->isNotEmpty())
                    <div class="grid grid-cols-4 grid-rows-1 w-full h-full">
                        @foreach($mosaic as $m)
                            <img src="{{ $m->poster_url }}" alt="" class="w-full h-full object-cover"
                                 style="filter: brightness(0.55);">
                        @endforeach
                    </div>
                @else
                    <div class="w-full h-full" style="background: radial-gradient(circle at 50% 35%, rgba(197,165,90,0.18), #0a0a0a 70%);"></div>
                @endif

                {{-- Gradient overlay --}}
                <div class="absolute inset-0" style="background: linear-gradient(180deg, rgba(0,0,0,0.5) 0%, rgba(0,0,0,0.8) 60%, #000 100%);"></div>
            </div>

            <div class="relative container mx-auto px-4 md:px-8 lg:px-16 max-w-[1600px] pt-16 md:pt-24 pb-12">

                {{-- Breadcrumb --}}
                <a href="{{ route('user-lists.index') }}"
                   class="inline-flex items-center gap-1 text-xs text-gray-300 hover:text-[#C5A55A] transition-colors mb-4">
                    <x-icon name="chevron-left" :size="12" /> All Lists
                </a>

                <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-6">
                    <div class="max-w-3xl">
                        {{-- Badges --}}
                        <div class="flex items-center gap-2 mb-3">
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 text-[10px] font-semibold rounded uppercase tracking-wider text-white"
                                  style="background: rgba(0,0,0,0.6); border: 1px solid rgba(197,165,90,0.3);">
                                <x-icon :name="$visibilityMeta['icon']" :size="11" /> {{ $visibilityMeta['label'] }}
                            </span>
                            @if($list->is_featured)
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 text-[10px] font-semibold rounded uppercase tracking-wider text-black"
                                      style="background: linear-gradient(135deg, #C5A55A, #E8D5A3);">
                                    Featured
                                </span>
                            @endif
                        </div>

                        <h1 class="font-heading text-3xl md:text-5xl font-bold text-white tracking-tight">
                            {{ $list->title }}
                        </h1>

                        @if($list->description)
                            <p class="mt-3 text-sm md:text-base text-gray-300 leading-relaxed">
                                {{ $list->description }}
                            </p>
                        @endif

                        {{-- Owner row --}}
                        <div class="mt-5 flex items-center gap-3">
                            @if($owner->avatar_url)
                                <img src="{{ $owner->avatar_url }}" alt="{{ $owner->name }}"
                                     class="h-9 w-9 rounded-full object-cover ring-1 ring-[#C5A55A]/40">
                            @else
                                <div class="h-9 w-9 rounded-full flex items-center justify-center text-sm font-semibold text-black ring-1 ring-[#C5A55A]/40"
                                     style="background: linear-gradient(135deg, #C5A55A, #E8D5A3);">
                                    {{ strtoupper(substr($owner->name, 0, 1)) }}
                                </div>
                            @endif
                            <div>
                                <div class="text-sm font-semibold text-white">
                                    @if($owner->username)
                                        <a href="{{ url('/u/' . $owner->username) }}" class="hover:text-[#C5A55A] transition-colors">
                                            {{ $owner->name }}
                                        </a>
                                        <span class="text-[11px] text-gray-500 font-normal ml-1">&middot; @{{ $owner->username }}</span>
                                    @else
                                        {{ $owner->name }}
                                    @endif
                                </div>
                                <div class="text-[11px] text-gray-500">
                                    Diperbarui {{ $list->updated_at?->diffForHumans() }}
                                </div>
                            </div>
                        </div>

                        {{-- Stats --}}
                        <div class="mt-4 flex items-center gap-5 text-sm">
                            <span class="inline-flex items-center gap-1.5 text-gray-300">
                                <x-icon name="film" :size="14" class="text-[#C5A55A]" />
                                <span class="font-semibold text-white">{{ number_format($list->items_count) }}</span>
                                <span class="text-gray-500">{{ \Illuminate\Support\Str::plural('film', $list->items_count) }}</span>
                            </span>
                            <span class="inline-flex items-center gap-1.5 text-gray-300">
                                <x-icon name="heart" :size="14" class="text-[#C5A55A]" />
                                <span class="font-semibold text-white" x-data x-text="window.__listFollowers ?? {{ $list->followers_count }}">{{ number_format($list->followers_count) }}</span>
                                <span class="text-gray-500">followers</span>
                            </span>
                            <span class="inline-flex items-center gap-1.5 text-gray-300">
                                <x-icon name="eye" :size="14" class="text-[#C5A55A]" />
                                <span class="font-semibold text-white">{{ number_format($list->views_count) }}</span>
                                <span class="text-gray-500">views</span>
                            </span>
                        </div>
                    </div>

                    {{-- Actions --}}
                    <div class="flex flex-wrap items-center gap-2">
                        @if($isOwner)
                            <a href="{{ route('user-lists.edit', ['user' => $userKey, 'list' => $list->slug]) }}"
                               class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm text-gray-200 hover:text-[#C5A55A] transition-colors"
                               style="background: rgba(20,18,16,0.7); border: 1px solid rgba(197,165,90,0.25);">
                                <x-icon name="cog" :size="14" /> Edit List
                            </a>
                        @elseif($canFollow)
                            <div x-data="listFollow({
                                followUrl: @js(route('user-lists.follow', ['user' => $userKey, 'list' => $list->slug])),
                                unfollowUrl: @js(route('user-lists.unfollow', ['user' => $userKey, 'list' => $list->slug])),
                                following: @js($isFollowing),
                                followersCount: {{ $list->followers_count }},
                                csrf: @js(csrf_token()),
                            })">
                                <button @click="toggle()"
                                        :disabled="busy"
                                        :class="following ? 'bg-transparent text-[#C5A55A]' : ''"
                                        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg text-sm font-semibold text-black transition-all"
                                        :style="following
                                            ? 'background: rgba(197,165,90,0.1); border: 1px solid #C5A55A;'
                                            : 'background: linear-gradient(135deg, #C5A55A, #E8D5A3);'">
                                    <x-icon name="heart" :size="14" />
                                    <span x-text="following ? 'Following' : 'Follow'"></span>
                                </button>
                            </div>
                        @elseif(! auth()->check())
                            <a href="{{ route('login') }}"
                               class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg text-sm font-semibold text-black"
                               style="background: linear-gradient(135deg, #C5A55A, #E8D5A3);">
                                <x-icon name="heart" :size="14" /> Log in to Follow
                            </a>
                        @endif

                        {{-- Copy link button --}}
                        <button type="button"
                                x-data
                                @click="navigator.clipboard.writeText(window.location.href); $el.innerText='Tersalin!'; setTimeout(() => $el.innerHTML = `<svg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke-width='1.75' stroke='currentColor' class='inline w-4 h-4 mr-1'><path stroke-linecap='round' stroke-linejoin='round' d='M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z' /></svg>Share link`, 1500)"
                                class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm text-gray-200 hover:text-[#C5A55A] transition-colors"
                                style="background: rgba(20,18,16,0.7); border: 1px solid rgba(197,165,90,0.25);">
                            <x-icon name="eye" :size="14" /> Share link
                        </button>
                    </div>
                </div>
            </div>
        </div>

        {{-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ --}}
        {{-- BODY — items + (owner only) add-movie panel + reorder       --}}
        {{-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ --}}
        <div class="container mx-auto px-4 md:px-8 lg:px-16 max-w-[1600px] mt-8">

            @if($isOwner)
                {{-- ── Add-movie typeahead (owner-only) ── --}}
                <div class="mb-8 p-5 rounded-xl"
                     style="background: linear-gradient(180deg, #1a1a1a 0%, #141414 100%); border: 1px solid rgba(197,165,90,0.2);"
                     x-data="addMovieToList({
                         addUrl: @js(route('user-lists.items.add', ['user' => $userKey, 'list' => $list->slug])),
                         csrf: @js(csrf_token()),
                     })">
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-[#C5A55A]/80 mb-3">
                        <x-icon name="plus" :size="12" class="inline" /> Tambah Film ke List
                    </h3>
                    <div class="relative">
                        <input type="text" x-model="query" @input.debounce.250ms="search()"
                               placeholder="Cari judul film..."
                               class="w-full px-3 py-2.5 rounded-lg text-sm text-white placeholder-gray-500"
                               style="background: rgba(20,18,16,0.6); border: 1px solid rgba(197,165,90,0.18);">

                        <div x-show="results.length > 0" x-cloak
                             class="absolute z-20 mt-1 w-full rounded-lg overflow-hidden shadow-2xl max-h-72 overflow-y-auto"
                             style="background: #141414; border: 1px solid rgba(197,165,90,0.3);">
                            <template x-for="r in results" :key="r.id">
                                <button type="button" @click="add(r)" :disabled="busy"
                                        class="w-full flex items-center gap-3 px-3 py-2 text-left hover:bg-[#C5A55A]/10 transition-colors">
                                    <img :src="r.poster ?? '/images/no-poster.png'" alt="" class="h-10 w-7 object-cover rounded">
                                    <span class="text-sm text-white truncate flex-1" x-text="r.title"></span>
                                    <span class="text-[11px] text-[#C5A55A]">+ Add</span>
                                </button>
                            </template>
                        </div>
                    </div>
                    <p x-show="message" x-cloak x-text="message" class="mt-2 text-[11px] text-[#C5A55A]"></p>
                </div>
            @endif

            {{-- ── Items ── --}}
            @if($items->isEmpty())
                <div class="flex flex-col items-center justify-center py-24 text-center rounded-xl"
                     style="background: rgba(20,18,16,0.5); border: 1px solid rgba(197,165,90,0.15);">
                    <x-icon name="film" :size="36" class="text-gray-700 mb-3" />
                    <h2 class="text-lg font-heading font-semibold text-gray-400">List masih kosong</h2>
                    <p class="text-sm text-gray-600 mt-1">
                        @if($isOwner) Tambahkan film pertama di kotak pencarian di atas. @else Belum ada film di list ini. @endif
                    </p>
                </div>
            @else
                <div id="list-items"
                     x-data="listReorder({
                         reorderUrl: @js(route('user-lists.reorder', ['user' => $userKey, 'list' => $list->slug])),
                         csrf: @js(csrf_token()),
                         canReorder: @js($isOwner),
                     })"
                     x-init="init()"
                     class="space-y-2">
                    @foreach($items as $item)
                        @php $m = $item->movie; @endphp
                        @if($m === null) @continue @endif
                        <div class="list-item flex items-center gap-4 p-3 md:p-4 rounded-xl group"
                             data-movie-id="{{ $m->id }}"
                             style="background: linear-gradient(180deg, #1a1a1a 0%, #141414 100%); border: 1px solid rgba(197,165,90,0.15);">

                            {{-- Position number / drag handle --}}
                            <div class="flex flex-col items-center gap-1 w-8 flex-shrink-0">
                                <span class="font-heading text-2xl font-bold text-[#C5A55A]/60 leading-none">
                                    {{ $loop->iteration }}
                                </span>
                                @if($isOwner)
                                    <button type="button"
                                            class="drag-handle cursor-grab text-gray-600 hover:text-[#C5A55A] transition-colors"
                                            title="Drag untuk reorder">
                                        <x-icon name="menu" :size="14" />
                                    </button>
                                @endif
                            </div>

                            {{-- Poster --}}
                            <a href="{{ route('movies.show', $m->slug ?? $m->id) }}" class="flex-shrink-0">
                                <img src="{{ $m->poster_url }}" alt="{{ $m->title }}"
                                     class="h-24 md:h-28 w-16 md:w-20 object-cover rounded-md shadow-lg group-hover:scale-105 transition-transform"
                                     onerror="this.onerror=null; this.src='/images/no-poster.png';">
                            </a>

                            {{-- Body --}}
                            <div class="flex-1 min-w-0">
                                <a href="{{ route('movies.show', $m->slug ?? $m->id) }}"
                                   class="text-sm md:text-base font-semibold text-white hover:text-[#C5A55A] transition-colors line-clamp-1">
                                    {{ $m->title }}
                                </a>
                                <div class="mt-1 flex items-center gap-3 text-[11px] text-gray-500">
                                    @if($m->release_date)
                                        <span>{{ $m->release_date->format('Y') }}</span>
                                        <span class="text-gray-700">·</span>
                                    @endif
                                    @if($m->vote_average > 0)
                                        <span class="inline-flex items-center gap-1 text-amber-400">
                                            <x-icon name="star-solid" :size="11" /> {{ number_format($m->vote_average, 1) }}
                                        </span>
                                    @endif
                                </div>
                                @if($item->note)
                                    <p class="mt-2 text-xs text-gray-400 italic line-clamp-2">
                                        &ldquo;{{ $item->note }}&rdquo;
                                    </p>
                                @endif
                            </div>

                            {{-- Owner controls --}}
                            @if($isOwner)
                                <form method="POST"
                                      action="{{ route('user-lists.items.remove', ['user' => $userKey, 'list' => $list->slug, 'movie' => $m->id]) }}"
                                      class="flex-shrink-0"
                                      onsubmit="return confirm('Hapus film dari list?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit"
                                            class="p-2 rounded-lg text-gray-500 hover:text-red-400 hover:bg-red-500/10 transition-colors"
                                            title="Hapus dari list">
                                        <x-icon name="x" :size="16" />
                                    </button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <x-footer />

    <script>
    function listFollow(opts) {
        return {
            following: opts.following,
            followersCount: opts.followersCount,
            busy: false,
            async toggle() {
                if (this.busy) return;
                this.busy = true;
                const url = this.following ? opts.unfollowUrl : opts.followUrl;
                const method = this.following ? 'DELETE' : 'POST';
                // Optimistic UI — flip state immediately, roll back on error.
                const wasFollowing = this.following;
                this.following = !this.following;
                this.followersCount += this.following ? 1 : -1;
                window.__listFollowers = this.followersCount;
                try {
                    const res = await fetch(url, {
                        method,
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': opts.csrf,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    const json = await res.json();
                    if (typeof json.followers_count === 'number') {
                        this.followersCount = json.followers_count;
                        window.__listFollowers = json.followers_count;
                    }
                } catch (e) {
                    // Roll back.
                    this.following = wasFollowing;
                    this.followersCount += this.following ? 1 : -1;
                    window.__listFollowers = this.followersCount;
                } finally {
                    this.busy = false;
                }
            },
        };
    }

    function addMovieToList(opts) {
        return {
            query: '',
            results: [],
            busy: false,
            message: '',
            controller: null,
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
            async add(movie) {
                if (this.busy) return;
                this.busy = true;
                this.message = '';
                try {
                    const res = await fetch(opts.addUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': opts.csrf,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify({ movie_id: movie.id }),
                    });
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    this.message = `"${movie.title}" ditambahkan. Reload untuk melihat.`;
                    this.query = '';
                    this.results = [];
                    // Reload after a moment so the new item shows in order.
                    setTimeout(() => window.location.reload(), 800);
                } catch (e) {
                    this.message = 'Gagal menambahkan film.';
                } finally {
                    this.busy = false;
                }
            },
        };
    }

    function listReorder(opts) {
        return {
            init() {
                if (!opts.canReorder) return;
                // Lightweight reorder — drag handle + native HTML5 drag/drop.
                // (SortableJS would be nicer, but we don't pull it in here to
                // avoid an extra dependency. The handles still trigger HTML5
                // drag because the row's draggable attr is set on grab.)
                const root = document.getElementById('list-items');
                if (!root) return;
                const rows = root.querySelectorAll('.list-item');
                let dragSrc = null;
                rows.forEach(row => {
                    const handle = row.querySelector('.drag-handle');
                    if (!handle) return;
                    handle.addEventListener('mousedown', () => row.setAttribute('draggable', 'true'));
                    handle.addEventListener('mouseup', () => row.removeAttribute('draggable'));

                    row.addEventListener('dragstart', (e) => {
                        dragSrc = row;
                        row.style.opacity = '0.4';
                        e.dataTransfer.effectAllowed = 'move';
                    });
                    row.addEventListener('dragover', (e) => {
                        e.preventDefault();
                        e.dataTransfer.dropEffect = 'move';
                        return false;
                    });
                    row.addEventListener('dragenter', () => {
                        if (row !== dragSrc) row.style.outline = '2px dashed #C5A55A';
                    });
                    row.addEventListener('dragleave', () => row.style.outline = '');
                    row.addEventListener('drop', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        row.style.outline = '';
                        if (dragSrc && dragSrc !== row) {
                            const rect = row.getBoundingClientRect();
                            const after = (e.clientY - rect.top) > rect.height / 2;
                            row.parentNode.insertBefore(dragSrc, after ? row.nextSibling : row);
                            this.persistOrder();
                        }
                        return false;
                    });
                    row.addEventListener('dragend', () => {
                        row.style.opacity = '';
                        row.removeAttribute('draggable');
                        dragSrc = null;
                    });
                });
            },
            async persistOrder() {
                const ids = Array.from(document.querySelectorAll('#list-items .list-item'))
                    .map(el => parseInt(el.dataset.movieId, 10))
                    .filter(id => !isNaN(id));
                if (ids.length === 0) return;
                try {
                    await fetch(opts.reorderUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': opts.csrf,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify({ movie_ids: ids }),
                    });
                    // Refresh the position numerals to reflect new order.
                    document.querySelectorAll('#list-items .list-item').forEach((el, idx) => {
                        const numeral = el.querySelector('.font-heading');
                        if (numeral) numeral.textContent = String(idx + 1);
                    });
                } catch (e) { /* silent — next drop will retry */ }
            },
        };
    }
    </script>
</x-layout>
