@props([
    'limit' => 8,
])

@php
    use App\Models\UserList;

    /**
     * Curated-lists shelf — home-page strip showing featured + most-followed
     * public lists. Empty-state-friendly (renders nothing on a fresh install
     * with no public lists yet, so the home page stays clean).
     *
     * Preference order: featured first, then most-followed; ties broken by id
     * desc so newer lists float above older ones at equal popularity.
     */
    $limit = max(1, min(20, (int) $limit));

    try {
        $lists = UserList::query()
            ->public()
            ->with([
                'user:id,name,username,avatar_path',
                'cover:id,title,slug,poster_path,backdrop_path',
                // Cheap eager-load for the mosaic fallback — capped at 4 per list
                // by the partial that consumes it.
                'items' => fn ($q) => $q->orderBy('position')->limit(4),
                'items.movie:id,title,poster_path,backdrop_path',
            ])
            ->orderByDesc('is_featured')
            ->orderByDesc('followers_count')
            ->orderByDesc('id')
            ->take($limit)
            ->get();
    } catch (\Throwable $e) {
        \Log::warning('curated-lists-shelf: query failed', ['error' => $e->getMessage()]);
        $lists = collect();
    }
@endphp

@if($lists->isNotEmpty())
<section class="curated-lists-shelf mb-8 md:mb-10">
    <div class="flex items-center gap-3 mb-3 md:mb-4">
        <x-icon name="bookmark" :size="16" class="text-[#C5A55A]" />
        <h2 class="font-heading text-base md:text-lg font-bold text-white tracking-wide">
            Curated by the Community
        </h2>
        <span class="text-[11px] text-gray-400 px-2 py-0.5 rounded-full"
              style="background: rgba(197,165,90,0.08); border: 1px solid rgba(197,165,90,0.2)">
            Top {{ $lists->count() }}
        </span>
        <a href="{{ route('user-lists.index') }}"
           class="ml-auto text-[11px] text-gray-500 hover:text-[#C5A55A] transition-colors">
            Lihat semua &rsaquo;
        </a>
    </div>

    <div class="curated-lists-scroll" style="overflow-x:auto; scrollbar-width:thin;">
        <div class="flex gap-3 md:gap-4 pb-3 pr-3" style="min-width:max-content;">
            @foreach($lists as $list)
                <div class="flex-shrink-0" style="width: 280px;">
                    <x-lists.card :list="$list" />
                </div>
            @endforeach
        </div>
    </div>

    <style>
        .curated-lists-scroll::-webkit-scrollbar { height: 6px; }
        .curated-lists-scroll::-webkit-scrollbar-track { background: rgba(255,255,255,0.03); border-radius: 3px; }
        .curated-lists-scroll::-webkit-scrollbar-thumb { background: rgba(197,165,90,0.3); border-radius: 3px; }
        .curated-lists-scroll::-webkit-scrollbar-thumb:hover { background: rgba(197,165,90,0.5); }
    </style>
</section>
@endif
