@props(['movies'])

<div class="">
    <div class="mb-4 text-lg antialiased font-bold tracking-wider text-gray-200">
        {{ $category }}
    </div>

    <div class="carousel" data-flickity='{ "freeScroll": true, "wrapAround": true }'
        class="carousel flex flex-nowrap">
        @foreach ($movies as $movie)
        <div @click="open = true" class="mr-3 flex flex-col overflow-hidden rounded-md" style="background-color:  #181818">
            <div @click="open = true" class="w-72">
                <a href="{{ route('movies.show', $movie['id']) }}">
                <img class="h-56 w-full cursor-pointer object-cover"
                    src="{{ $movie['poster_path'] }}"
                    alt="{{ $movie['title'] ?? 'poster' }}"
                    onerror="this.onerror=null;this.src='https://via.placeholder.com/500x750/1a1a2e/e94560?text={{ urlencode($movie['title'] ?? 'No+Poster') }}'">
                </a>
            </div>
        </div>
        @endforeach
    </div>
</div>
