@props(['movie', 'genres'])

<div class="card-movie group mr-3 w-[200px] lg:w-[220px]" x-data="{ open: false }">
    <a href="{{ route('movies.show', $movie['id']) }}">
        <div class="relative aspect-[2/3] overflow-hidden rounded-lg">
            <img class="h-full w-full object-cover transition-transform duration-500 group-hover:scale-110"
                 src="{{ $movie['poster_path'] }}"
                 alt="{{ $movie['title'] ?? 'poster' }}"
                 onerror="this.onerror=null;this.src='https://via.placeholder.com/500x750/1a1a2e/e94560?text={{ urlencode($movie['title'] ?? 'No+Poster') }}'"
                 loading="lazy">
            
            <!-- Hover Overlay -->
            <div class="card-movie-overlay">
                <!-- Play Button -->
                <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 opacity-0 group-hover:opacity-100 transition-opacity duration-300">
                    <div class="h-12 w-12 rounded-full bg-gold-500/90 flex items-center justify-center shadow-gold-glow">
                        <svg class="h-5 w-5 text-black ml-0.5" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M6.3 2.841A1.5 1.5 0 004 4.11V15.89a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z"/>
                        </svg>
                    </div>
                </div>

                <!-- Bottom Info -->
                <div>
                    <h3 class="text-sm font-semibold text-white line-clamp-2">{{ $movie['title'] }}</h3>
                    <div class="mt-1 flex items-center gap-2 text-xs">
                        <span class="text-gold-400 font-bold">★ {{ number_format($movie['vote_average'], 1) }}</span>
                        @if(isset($movie['release_date']))
                            <span class="text-gray-400">{{ \Carbon\Carbon::parse($movie['release_date'])->year }}</span>
                        @endif
                    </div>
                    @if(!empty($movie['genre_ids']))
                        <div class="mt-1 flex flex-wrap gap-1">
                            @foreach(array_slice($movie['genre_ids'], 0, 2) as $genre)
                                @if($genres->has($genre))
                                    <span class="text-[10px] px-1.5 py-0.5 rounded-full bg-white/10 text-gray-300">{{ $genres->get($genre) }}</span>
                                @endif
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            <!-- Premium Badge (if applicable) -->
            @if(isset($movie['is_premium']) && $movie['is_premium'])
                <div class="absolute top-2 right-2">
                    <span class="badge-premium text-[9px]">🪙 PREMIUM</span>
                </div>
            @endif
        </div>
    </a>
</div>
