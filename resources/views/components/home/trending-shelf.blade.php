@props([
    'window' => '24h',
    'limit'  => 12,
])

@php
    use App\Models\TrendingMovie;

    /**
     * Trending shelf — pre-aggregated cache from `trending_movies`.
     *
     * Reads are O(limit) thanks to (window, rank) covering index.
     * Empty state renders nothing so the home page doesn't show an
     * awkward "no trending yet" stub on first deploy / after a
     * cache wipe.
     */

    $allowedWindows = ['1h', '24h', '7d', '30d'];
    $window = in_array($window, $allowedWindows, true) ? $window : '24h';
    $limit  = max(1, min(50, (int) $limit));

    try {
        $rows = TrendingMovie::forWindow($window)
            ->with(['movie.genres'])
            ->take($limit)
            ->get()
            ->filter(fn ($r) => $r->movie !== null) // skip rows whose movie was deleted before cache rebuild
            ->values();
    } catch (\Throwable $e) {
        \Log::warning('trending-shelf: query failed', ['error' => $e->getMessage()]);
        $rows = collect();
    }

    $title = match ($window) {
        '1h'  => '🔥 Trending Sekarang',
        '24h' => '🔥 Trending Hari Ini',
        '7d'  => '🔥 Trending Minggu Ini',
        '30d' => '🔥 Trending Bulan Ini',
        default => '🔥 Trending',
    };
@endphp

@if($rows->isNotEmpty())
<section class="trending-shelf mb-8 md:mb-10" data-window="{{ $window }}">
    <div class="flex items-center gap-3 mb-3 md:mb-4">
        <h2 class="font-heading text-base md:text-lg font-bold text-white tracking-wide">
            {{ $title }}
        </h2>
        <span class="text-[11px] text-gray-400 px-2 py-0.5 rounded-full"
              style="background: rgba(197,165,90,0.08); border: 1px solid rgba(197,165,90,0.2)">
            Top {{ $rows->count() }}
        </span>
        <a href="{{ url('/trending') }}#window-{{ $window }}"
           class="ml-auto text-[11px] text-gray-500 hover:text-[#C5A55A] transition-colors">
            Lihat semua &rsaquo;
        </a>
    </div>

    <div class="trending-scroll" style="overflow-x:auto; scrollbar-width:thin;">
        <div class="flex gap-3 md:gap-4 pb-3 pr-3" style="min-width:max-content;">
            @foreach($rows as $row)
                @php
                    $movie = $row->movie;
                    $slugOrId = $movie->slug ?? $movie->id;
                    $rank = $row->rank;
                    $poster = $movie->effective_poster_url ?? $movie->poster_url ?? '/images/no-poster.png';
                @endphp

                <a href="{{ route('movies.show', $slugOrId) }}"
                   class="trending-card group block flex-shrink-0"
                   title="{{ $movie->title }}">
                    <div class="relative">
                        {{-- Giant Netflix-style rank numeral --}}
                        <span class="trending-rank" aria-hidden="true">{{ $rank }}</span>
                        <div class="trending-thumb">
                            <img src="{{ $poster }}"
                                 alt="{{ $movie->title }}"
                                 loading="lazy"
                                 onerror="this.onerror=null; this.src='/images/no-poster.png';">
                        </div>
                    </div>
                    <div class="mt-2 max-w-[180px]">
                        <div class="text-xs md:text-sm font-semibold text-white truncate group-hover:text-[#C5A55A] transition-colors">
                            {{ $movie->title }}
                        </div>
                        <div class="flex items-center gap-2 mt-0.5 text-[10px] text-gray-500">
                            <span>{{ number_format($row->view_count) }} views</span>
                            @if($row->unique_viewer_count > 0)
                                <span class="text-gray-700">·</span>
                                <span>{{ number_format($row->unique_viewer_count) }} unik</span>
                            @endif
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
    </div>

    <style>
        .trending-scroll::-webkit-scrollbar { height: 6px; }
        .trending-scroll::-webkit-scrollbar-track { background: rgba(255,255,255,0.03); border-radius: 3px; }
        .trending-scroll::-webkit-scrollbar-thumb { background: rgba(197,165,90,0.3); border-radius: 3px; }
        .trending-scroll::-webkit-scrollbar-thumb:hover { background: rgba(197,165,90,0.5); }

        .trending-card { width: 180px; }
        .trending-thumb {
            position: relative;
            width: 180px;
            aspect-ratio: 2/3;
            border-radius: 10px;
            overflow: hidden;
            background: #141210;
            margin-left: 44px; /* leave room for the rank numeral peeking out left */
            box-shadow: 0 4px 14px rgba(0,0,0,0.45);
            transition: transform 200ms ease, box-shadow 200ms ease;
        }
        .trending-card:hover .trending-thumb {
            transform: translateY(-2px);
            box-shadow: 0 8px 22px rgba(197,165,90,0.25);
        }
        .trending-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        /* Giant rank numeral — Netflix style. Sits half-behind the poster. */
        .trending-rank {
            position: absolute;
            left: -10px;
            bottom: -22px;
            font-family: 'Bebas Neue', 'Oswald', 'Inter', sans-serif;
            font-weight: 900;
            font-size: 156px;
            line-height: 1;
            color: #0a0a0a;
            -webkit-text-stroke: 3px #C5A55A;
            text-stroke: 3px #C5A55A;
            z-index: 1;
            user-select: none;
            pointer-events: none;
            letter-spacing: -0.05em;
        }
        @media (max-width: 768px) {
            .trending-card { width: 140px; }
            .trending-thumb { width: 140px; margin-left: 34px; }
            .trending-rank { font-size: 116px; left: -8px; bottom: -16px; }
        }
    </style>
</section>
@endif
