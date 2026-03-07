@props(['popular', 'genres', 'trending', 'comedies', 'action', 'western', 'horror', 'thriller', 'animation', 'continueWatching', 'topRated'])

<div class="container my-6 mx-auto space-y-8 px-4">
    <!-- Continue Watching (logged-in users with history) -->
    @auth
    @if($continueWatching->count() > 0)
    <div>
        <h2 class="mb-3 text-lg font-bold font-heading" style="color:#C5A55A">▶️ Lanjutkan Menonton</h2>
        <div class="flex gap-3 overflow-x-auto pb-4" style="scrollbar-width:none">
            @foreach($continueWatching as $movie)
            <a href="/movie/{{ $movie['id'] }}" class="flex-shrink-0 relative group" style="width:160px">
                <div class="relative overflow-hidden rounded-lg">
                    <img src="{{ $movie['poster_path'] }}" alt="{{ $movie['title'] }}" class="w-full aspect-[2/3] object-cover rounded-lg transition-transform group-hover:scale-105" onerror="this.src='https://via.placeholder.com/300x450/1a1a1a/333?text=No+Poster'">
                    <div class="absolute bottom-0 left-0 right-0 h-1 bg-gray-800">
                        <div class="h-full rounded-full" style="width:{{ $movie['progress'] }}%;background:#C5A55A"></div>
                    </div>
                    <div class="absolute inset-0 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity bg-black/40 rounded-lg">
                        <div class="w-10 h-10 rounded-full flex items-center justify-center" style="background:rgba(197,165,90,0.9)">
                            <svg class="w-5 h-5 text-black ml-0.5" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                        </div>
                    </div>
                </div>
                <p class="mt-1 text-xs text-gray-400 truncate">{{ $movie['title'] }}</p>
                <p class="text-xs text-gray-600">{{ $movie['progress'] }}% selesai</p>
            </a>
            @endforeach
        </div>
    </div>
    @endif
    @endauth

    <!-- Popular Movies -->
    <x-movies :movies='$popular'>
        <x-slot:category> Popular on FLiK &rsaquo; </x-slot:category>
    </x-movies>
    <!-- End Popular Movies -->

    <!-- Trending Movies -->
    <x-movies :movies='$trending'>
        <x-slot:category> Trending on FLiK &rsaquo; </x-slot:category>
    </x-movies>
    <!-- End Trending Movies -->

    <!-- Top Rated -->
    @if($topRated->count() > 0)
    <x-movies :movies='$topRated'>
        <x-slot:category> ⭐ Top Rated &rsaquo; </x-slot:category>
    </x-movies>
    @endif
    <!-- End Top Rated -->

    <!-- Comedies Movies -->
    <x-movies :movies='$comedies'>
        <x-slot:category> Comedies &rsaquo; </x-slot:category>
    </x-movies>
    <!-- End Comedies Movies -->

    <!-- Action Movies -->
    <x-movies :movies='$action'>
        <x-slot:category> Action &rsaquo; </x-slot:category>
    </x-movies>
    <!-- End Action Movies -->

    <!-- Western Movies -->
    <x-movies :movies='$western'>
        <x-slot:category> Western &rsaquo; </x-slot:category>
    </x-movies>
    <!-- End Wester Movies -->

    <!-- Horror Movies -->
    <x-movies :movies=$horror>
        <x-slot:category> Horror &rsaquo; </x-slot:category>
    </x-movies>
    <!-- End Horror Movies -->

    <!-- Animation Movies -->
    <x-movies :movies='$animation'>
        <x-slot:category> Animation &rsaquo; </x-slot:category>
    </x-movies>
    <!-- End Animation Movies -->
</div>
