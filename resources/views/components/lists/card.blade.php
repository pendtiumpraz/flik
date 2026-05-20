@props([
    /** @var \App\Models\UserList $list */
    'list',
    'showOwner' => true,
])

@php
    /**
     * UserList card — used on /lists, /lists/mine, /lists/following, and
     * the home-page curated-lists shelf. Renders a hero (explicit cover
     * OR a 4-poster mosaic of the first items) + title + owner + counters.
     *
     * Mosaic source: first 4 items (any) → poster_url. When the list is
     * empty we render a placeholder badge instead. The component never
     * fires extra queries — the caller is expected to eager-load
     * `cover` (and `items.movie` for the mosaic).
     */
    $owner = $list->user;
    $coverUrl = $list->cover?->backdrop_url
        ?? $list->cover?->poster_url;

    $mosaicMovies = collect();
    if (! $coverUrl) {
        // Use already-loaded items if present; otherwise fetch up to 4.
        $items = $list->relationLoaded('items')
            ? $list->items
            : $list->items()->with('movie:id,title,poster_path,backdrop_path')->limit(4)->get();
        $mosaicMovies = $items->take(4)->map(fn ($i) => $i->movie)->filter()->values();
    }

    $ownerUrl = $owner?->username ? url('/u/' . $owner->username) : null;
    $listUrl = route('user-lists.show', [
        'user' => $owner?->username ?? (string) ($list->user_id),
        'list' => $list->slug,
    ]);

    $visibilityLabel = match ($list->visibility) {
        \App\Models\UserList::VISIBILITY_PRIVATE => 'Private',
        \App\Models\UserList::VISIBILITY_UNLISTED => 'Unlisted',
        default => null, // public — no badge
    };
@endphp

<a href="{{ $listUrl }}"
   class="block group rounded-xl overflow-hidden transition-transform hover:-translate-y-0.5"
   style="background: linear-gradient(180deg, #1a1a1a 0%, #141414 100%); border: 1px solid rgba(197,165,90,0.18);">

    {{-- ── Cover ─────────────────────────────────────────────── --}}
    <div class="relative w-full aspect-[16/9] bg-[#0a0a0a] overflow-hidden">
        @if($coverUrl)
            <img src="{{ $coverUrl }}"
                 alt="{{ $list->title }}"
                 loading="lazy"
                 class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105"
                 onerror="this.onerror=null; this.src='/images/no-poster.png';">
        @elseif($mosaicMovies->isNotEmpty())
            <div class="grid grid-cols-2 grid-rows-2 w-full h-full gap-0.5">
                @foreach($mosaicMovies as $m)
                    <div class="overflow-hidden bg-[#0a0a0a]">
                        <img src="{{ $m->poster_url }}"
                             alt=""
                             loading="lazy"
                             class="w-full h-full object-cover"
                             onerror="this.onerror=null; this.src='/images/no-poster.png';">
                    </div>
                @endforeach
                @for($i = $mosaicMovies->count(); $i < 4; $i++)
                    <div class="bg-[#1a1a1a] flex items-center justify-center">
                        <x-icon name="film" :size="20" class="text-gray-700" />
                    </div>
                @endfor
            </div>
        @else
            {{-- Empty list — gold-tinted placeholder so the card doesn't look broken --}}
            <div class="w-full h-full flex items-center justify-center" style="background: radial-gradient(circle at 50% 35%, rgba(197,165,90,0.12), transparent 70%);">
                <x-icon name="film" :size="36" class="text-[#C5A55A]/60" />
            </div>
        @endif

        {{-- Visibility badge (only when not public) --}}
        @if($visibilityLabel)
            <span class="absolute top-2 right-2 px-2 py-0.5 text-[10px] font-semibold rounded uppercase tracking-wider text-white"
                  style="background: rgba(0,0,0,0.7); border: 1px solid rgba(197,165,90,0.4);">
                {{ $visibilityLabel }}
            </span>
        @endif

        @if($list->is_featured)
            <span class="absolute top-2 left-2 px-2 py-0.5 text-[10px] font-semibold rounded uppercase tracking-wider text-black"
                  style="background: linear-gradient(135deg, #C5A55A, #E8D5A3);">
                Featured
            </span>
        @endif
    </div>

    {{-- ── Body ──────────────────────────────────────────────── --}}
    <div class="p-3 md:p-4">
        <h3 class="text-sm md:text-base font-semibold text-white truncate group-hover:text-[#C5A55A] transition-colors">
            {{ $list->title }}
        </h3>

        @if($list->description)
            <p class="mt-1 text-xs text-gray-500 line-clamp-2">{{ $list->description }}</p>
        @endif

        @if($showOwner && $owner)
            <div class="mt-2.5 flex items-center gap-2 text-[11px] text-gray-400">
                <div class="h-5 w-5 rounded-full flex items-center justify-center text-[10px] font-semibold text-black"
                     style="background: linear-gradient(135deg, #C5A55A, #E8D5A3);">
                    {{ strtoupper(substr($owner->name ?? '?', 0, 1)) }}
                </div>
                <span class="truncate">by {{ $owner->name }}</span>
            </div>
        @endif

        <div class="mt-2.5 flex items-center gap-3 text-[11px] text-gray-500">
            <span class="inline-flex items-center gap-1">
                <x-icon name="film" :size="11" class="text-[#C5A55A]/80" />
                {{ number_format($list->items_count) }} {{ \Illuminate\Support\Str::plural('film', $list->items_count) }}
            </span>
            <span class="inline-flex items-center gap-1">
                <x-icon name="user" :size="11" class="text-[#C5A55A]/80" />
                {{ number_format($list->followers_count) }}
            </span>
            @if($list->views_count > 0)
                <span class="inline-flex items-center gap-1">
                    <x-icon name="eye" :size="11" class="text-[#C5A55A]/80" />
                    {{ number_format($list->views_count) }}
                </span>
            @endif
        </div>
    </div>
</a>
