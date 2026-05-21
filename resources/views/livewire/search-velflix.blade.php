<div class="relative border border-white bg-black p-1 text-white">
    {{-- Legacy magnifying-glass icon (was <x-bi-search>; that component was
         never registered and broke view:cache. The smart-bar component
         (x-search.smart-bar in components/header.blade.php) is the canonical
         search now; this Livewire view is kept only for backward-compat. --}}
    <svg class="absolute m-1 h-5 w-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z"/>
    </svg>
    <input wire:model.debounce.500ms="searchVelflix" type="text" placeholder="Titles, people, genres" class="ml-8 bg-black text-white placeholder-gray-500 focus:outline-none">

    @if (strlen($searchVelflix ?? '') >= 3)
    <div class="absolute mt-2 -ml-2 w-64 bg-gray-700 p-2 text-sm">
        @if ($searchVelflixResults->count() > 0 )
        <ul>
            @foreach ($searchVelflixResults as $searchResults)
            <a href="{{ route('movies.show', $searchResults['slug'] ?? $searchResults['id']) }}" class="hover:bg-gray-400">
            <li class="border-b border-gray-500 p-1">
                    {{ $searchResults['title'] }}
                </li>
            </a>
            @endforeach
        </ul>
        @else
        <div class="px-3 py-3">
            <span> Your search for "{{ $searchVelflix }}" did not have any matches</span>
            <span>Suggestions: </span>
                <ul>
                    <li>Try different keywords</li>
                    <li>Looking for a film or TV programme ? </li>
                    <li>Try using a film, TV programme title, an actor or director.</li>
                    <li>Try a genre, such as comedy, romance, sports or drama.</li>
                </ul>
        </div>
        @endif
    </div>
    @endif
</div>
