@props(['popular', 'genres', 'trending', 'comedies', 'action', 'western', 'horror', 'thriller', 'animation', 'continueWatching', 'topRated'])

<div class="container mx-auto px-4 md:px-8 lg:px-16 py-6 md:py-10 space-y-10 md:space-y-14 max-w-[1600px]">

    <!-- Continue Watching -->
    @auth
    @if($continueWatching->count() > 0)
    <x-movies :movies="$continueWatching" :genres="$genres">
        <x-slot:category>
            <x-icon name="play-solid" :size="16" class="text-[#C5A55A]" />
            <span>Continue Watching</span>
        </x-slot:category>
    </x-movies>
    @endif
    @endauth

    <!-- Trending -->
    @if($trending->count() > 0)
    <x-movies :movies="$trending" :genres="$genres">
        <x-slot:category>
            <x-icon name="lightning" :size="16" class="text-[#C5A55A]" />
            <span>Trending Now</span>
        </x-slot:category>
    </x-movies>
    @endif

    <!-- Popular -->
    <x-movies :movies="$popular" :genres="$genres">
        <x-slot:category>
            <x-icon name="sparkles" :size="16" class="text-[#C5A55A]" />
            <span>Popular on FLiK</span>
        </x-slot:category>
    </x-movies>

    <!-- Top Rated -->
    @if($topRated->count() > 0)
    <x-movies :movies="$topRated" :genres="$genres">
        <x-slot:category>
            <x-icon name="star-solid" :size="16" class="text-[#C5A55A]" />
            <span>Top Rated</span>
        </x-slot:category>
    </x-movies>
    @endif

    <!-- Genre rows -->
    @if($comedies->count() > 0)
    <x-movies :movies="$comedies" :genres="$genres">
        <x-slot:category><span>Comedies</span></x-slot:category>
    </x-movies>
    @endif

    @if($action->count() > 0)
    <x-movies :movies="$action" :genres="$genres">
        <x-slot:category><span>Action</span></x-slot:category>
    </x-movies>
    @endif

    @if($horror->count() > 0)
    <x-movies :movies="$horror" :genres="$genres">
        <x-slot:category><span>Horror</span></x-slot:category>
    </x-movies>
    @endif

    @if($thriller->count() > 0)
    <x-movies :movies="$thriller" :genres="$genres">
        <x-slot:category><span>Thriller</span></x-slot:category>
    </x-movies>
    @endif

    @if($western->count() > 0)
    <x-movies :movies="$western" :genres="$genres">
        <x-slot:category><span>Western</span></x-slot:category>
    </x-movies>
    @endif

    @if($animation->count() > 0)
    <x-movies :movies="$animation" :genres="$genres">
        <x-slot:category><span>Animation</span></x-slot:category>
    </x-movies>
    @endif
</div>
