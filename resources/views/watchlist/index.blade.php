<x-layout>
    <div class="min-h-screen bg-black pt-20 pb-16">
        <div class="container mx-auto px-4 md:px-16">
            <h1 class="font-heading text-2xl md:text-4xl font-bold mb-8" style="color: #C5A55A">My List</h1>

            @if($movies->count() > 0)
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-4">
                    @foreach($movies as $movie)
                    <div class="group relative">
                        <a href="{{ route('movies.show', $movie->id) }}">
                            <img src="{{ $movie->poster_url }}"
                                alt="{{ $movie->title }}"
                                class="w-full rounded-lg shadow-lg transition-transform group-hover:scale-105"
                                style="aspect-ratio: 2/3; object-fit: cover;"
                                onerror="this.onerror=null;this.style.background='linear-gradient(135deg,#1a1a1a,#333)';this.src='https://via.placeholder.com/300x450/1a1a1a/C5A55A?text={{ urlencode($movie->title) }}'">
                        </a>
                        <div class="mt-2">
                            <h3 class="text-sm font-medium text-white truncate">{{ $movie->title }}</h3>
                            <div class="flex items-center gap-2 mt-1">
                                <span class="text-xs text-green-500">★ {{ number_format($movie->vote_average, 1) }}</span>
                                <span class="text-xs text-gray-600">{{ $movie->release_date?->format('Y') }}</span>
                            </div>
                        </div>
                        <!-- Remove from watchlist -->
                        <form method="POST" action="{{ route('watchlist.toggle') }}" class="absolute top-2 right-2">
                            @csrf
                            <input type="hidden" name="movie_id" value="{{ $movie->id }}">
                            <button type="submit" class="w-8 h-8 rounded-full bg-black/70 flex items-center justify-center hover:bg-red-600 transition-colors" title="Remove from list">
                                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </form>
                    </div>
                    @endforeach
                </div>
            @else
                <div class="flex flex-col items-center justify-center py-32 text-center">
                    <svg class="w-16 h-16 text-gray-700 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                    <h2 class="text-xl font-heading font-semibold text-gray-400">Watchlist Kosong</h2>
                    <p class="text-gray-600 mt-2">Tambahkan film favorit ke daftar tonton kamu</p>
                    <a href="{{ route('velflix.index') }}" class="mt-6 inline-flex items-center gap-2 px-6 py-3 rounded-lg font-semibold text-black" style="background:#C5A55A">
                        Jelajahi Film
                    </a>
                </div>
            @endif
        </div>
    </div>
</x-layout>
