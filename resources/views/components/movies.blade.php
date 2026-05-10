@props(['movies', 'density' => 'default'])

@php
    $genreMap = $genres ?? collect();

    // Density controls grid columns. 'default' = full-width compact, 'large' = for 3/4 sidebar areas
    $gridCols = match($density) {
        'large'   => 'grid-cols-2 sm:grid-cols-3 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6',
        'huge'    => 'grid-cols-2 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-4',
        default   => 'grid-cols-3 sm:grid-cols-4 md:grid-cols-5 lg:grid-cols-6 xl:grid-cols-7 2xl:grid-cols-8',
    };
@endphp

<style>
    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
    /* Hover Preview Card pattern (Netflix-style "Bob")  — pure CSS, no JS */
    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
    .film-grid { position: relative; }

    .film-card {
        position: relative;
        transition: transform 200ms ease;
    }

    /* Lift card slightly + raise z-index on hover */
    .film-card:hover {
        z-index: 50;
    }

    /* The thumbnail */
    .film-thumb {
        position: relative;
        aspect-ratio: 2/3;
        overflow: hidden;
        border-radius: 8px;
        background: #1a1a1a;
        cursor: pointer;
    }
    .film-thumb img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 400ms ease;
    }
    .film-card:hover .film-thumb img {
        transform: scale(1.05);
    }

    /* ━━━━━━━━━━ THE PREVIEW POPOVER ━━━━━━━━━━ */
    .film-popover {
        position: absolute;
        top: -16px;
        left: 50%;
        transform: translateX(-50%) scale(0.92);
        transform-origin: center top;

        width: 320px;
        max-width: calc(100vw - 32px);
        z-index: 100;

        background: linear-gradient(180deg, #1a1a1a 0%, #141210 100%);
        border-radius: 10px;
        overflow: hidden;
        box-shadow: 0 25px 60px -10px rgba(0, 0, 0, 0.85), 0 0 0 1px rgba(197, 165, 90, 0.3);

        opacity: 0;
        visibility: hidden;
        pointer-events: none;
        transition: opacity 200ms ease, transform 200ms ease, visibility 0s linear 200ms;
    }

    /* Show on hover with delay */
    .film-card:hover .film-popover {
        opacity: 1;
        visibility: visible;
        transform: translateX(-50%) scale(1);
        pointer-events: auto;
        transition: opacity 200ms ease 300ms, transform 200ms ease 300ms, visibility 0s linear 300ms;
    }

    /* Edge handling — JS adds .is-edge-left / .is-edge-right based on actual column position */
    .film-card.is-edge-left .film-popover {
        left: 0;
        right: auto;
        transform: scale(0.92);
        transform-origin: left top;
    }
    .film-card.is-edge-left:hover .film-popover {
        transform: scale(1);
    }

    .film-card.is-edge-right .film-popover {
        left: auto;
        right: 0;
        transform: scale(0.92);
        transform-origin: right top;
    }
    .film-card.is-edge-right:hover .film-popover {
        transform: scale(1);
    }

    /* Mobile: disable popover entirely */
    @media (max-width: 767px) {
        .film-popover { display: none !important; }
    }

    /* Reduce-motion respect */
    @media (prefers-reduced-motion: reduce) {
        .film-card:hover .film-popover { transition-delay: 0ms; }
        .film-thumb img, .film-card { transition: none !important; }
    }
</style>

<section class="film-row">
    <div class="flex items-center justify-between mb-3 md:mb-4">
        <h2 class="font-heading text-base md:text-lg font-semibold text-white tracking-wide flex items-center gap-2">
            {{ $category }}
        </h2>
    </div>

    <!-- Grid wrapper — overflow visible so popover can escape -->
    <div class="film-grid grid {{ $gridCols }} gap-3 md:gap-4">

        @foreach ($movies as $movie)
            @php
                $movieId = $movie['id'] ?? null;
                $movieSlug = $movie['slug'] ?? $movieId;
                $title = $movie['title'] ?? 'Untitled';
                $poster = $movie['poster_path'] ?? '';
                $rating = $movie['vote_average'] ?? null;
                $overview = $movie['overview'] ?? '';
                $genreIds = $movie['genre_ids'] ?? [];
                $progress = $movie['progress'] ?? null;
                $year = !empty($movie['release_date']) ? substr($movie['release_date'], 0, 4) : null;
            @endphp

            <div class="film-card">

                <!-- Thumbnail link -->
                <a href="{{ $movieSlug ? route('movies.show', $movieSlug) : '#' }}" class="block">
                    <div class="film-thumb">
                        <img
                            src="{{ $poster }}"
                            alt="{{ $title }}"
                            loading="lazy"
                            onerror="this.onerror=null"
                        >

                        <!-- Rating badge (top-left) -->
                        @if($rating)
                        <div class="absolute top-2 left-2 z-10 flex items-center gap-1 px-1.5 py-0.5 rounded-md backdrop-blur" style="background: rgba(0,0,0,0.7); border: 1px solid rgba(197,165,90,0.3)">
                            <x-icon name="star-solid" :size="10" class="text-[#C5A55A]" />
                            <span class="text-[10px] font-bold text-[#C5A55A]">{{ number_format($rating, 1) }}</span>
                        </div>
                        @endif

                        <!-- Progress bar -->
                        @if($progress)
                        <div class="absolute bottom-0 left-0 right-0 h-1 bg-black/60 z-10">
                            <div class="h-full" style="width: {{ $progress }}%; background: linear-gradient(90deg, #C5A55A, #E8D5A3)"></div>
                        </div>
                        @endif

                        <!-- Mobile: title bottom -->
                        <div class="md:hidden absolute inset-x-0 bottom-0 z-10 p-2 pt-6" style="background: linear-gradient(180deg, transparent, rgba(0,0,0,0.85))">
                            <h3 class="text-[11px] font-semibold text-white line-clamp-1">{{ $title }}</h3>
                        </div>
                    </div>
                </a>

                <!-- Title under poster (desktop) -->
                <h3 class="hidden md:block mt-2 text-[12px] font-medium text-gray-300 line-clamp-1 transition-colors">{{ $title }}</h3>

                <!-- ━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
                <!-- HOVER PREVIEW POPOVER (Netflix Bob) — pure CSS -->
                <!-- ━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
                <div class="film-popover">

                    <!-- Big poster banner -->
                    <a href="{{ $movieSlug ? route('movies.show', $movieSlug) : '#' }}" class="block relative">
                        <div class="aspect-video overflow-hidden bg-[#0a0a0a]">
                            <img src="{{ $poster }}"
                                 alt="{{ $title }}"
                                 class="w-full h-full object-cover"
                                 loading="lazy"
                                 onerror="this.onerror=null">
                            <div class="absolute inset-0" style="background: linear-gradient(180deg, transparent 40%, rgba(20,18,16,0.95) 100%)"></div>
                        </div>

                        <!-- Title overlay on banner -->
                        <div class="absolute inset-x-0 bottom-0 p-3.5">
                            <h3 class="text-base font-bold text-white line-clamp-2 leading-tight">{{ $title }}</h3>
                        </div>
                    </a>

                    <!-- Detail body -->
                    <div class="p-3.5 pt-2.5 space-y-2.5">

                        <!-- Meta row -->
                        <div class="flex items-center flex-wrap gap-x-2 gap-y-1 text-[11px]">
                            @if($rating)
                                <span class="inline-flex items-center gap-1 font-bold text-[#C5A55A]">
                                    <x-icon name="star-solid" :size="11" />
                                    {{ number_format($rating, 1) }}
                                </span>
                            @endif
                            @if($year)
                                <span class="text-gray-400">{{ $year }}</span>
                            @endif
                            <span class="px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wider rounded" style="background: rgba(197,165,90,0.15); color: #C5A55A; border: 1px solid rgba(197,165,90,0.3)">HD</span>
                        </div>

                        <!-- Genre pills -->
                        @if(count($genreIds) > 0 && $genreMap->count() > 0)
                        <div class="flex flex-wrap gap-1 items-center">
                            @foreach(array_slice($genreIds, 0, 3) as $gid)
                                @if($genreMap->has($gid))
                                <span class="text-[10px] text-gray-300">{{ $genreMap[$gid] }}</span>
                                @if(!$loop->last)
                                    <span class="text-gray-600 text-[10px]">·</span>
                                @endif
                                @endif
                            @endforeach
                        </div>
                        @endif

                        <!-- Synopsis -->
                        @if($overview)
                        <p class="text-[11.5px] text-gray-400 line-clamp-3 leading-relaxed">{{ $overview }}</p>
                        @endif

                        <!-- Action buttons -->
                        <div class="flex items-center gap-2 pt-1">
                            <a href="{{ $movieSlug ? route('movies.show', $movieSlug) : '#' }}"
                               class="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-md text-xs font-bold text-black transition-all hover:opacity-95"
                               style="background: linear-gradient(135deg, #C5A55A, #E8D5A3)">
                                <x-icon name="play-solid" :size="12" />
                                Play
                            </a>
                            @auth
                            <form action="{{ route('watchlist.toggle') }}" method="POST" class="contents">
                                @csrf
                                <input type="hidden" name="movie_id" value="{{ $movieId }}">
                                <button type="submit"
                                        class="inline-flex items-center justify-center w-9 h-9 rounded-md transition-colors hover:border-[#C5A55A]"
                                        style="background: rgba(255,255,255,0.04); border: 1px solid rgba(197,165,90,0.25)"
                                        title="Add to My List">
                                    <x-icon name="plus" :size="14" class="text-[#C5A55A]" :stroke="2.5" />
                                </button>
                            </form>
                            @endauth
                            <a href="{{ $movieSlug ? route('movies.show', $movieSlug) : '#' }}"
                               class="inline-flex items-center justify-center w-9 h-9 rounded-md transition-colors hover:border-[#C5A55A]"
                               style="background: rgba(255,255,255,0.04); border: 1px solid rgba(197,165,90,0.25)"
                               title="More info">
                                <x-icon name="info" :size="14" class="text-[#C5A55A]" />
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</section>

@once
<script>
    // ━━━ Edge detection for hover popovers ━━━
    // Detects which film cards are in the first/last column based on actual layout,
    // works across all responsive breakpoints (3/4/5/6/7/8 cols).
    (function () {
        function detectEdges() {
            document.querySelectorAll('.film-grid').forEach(function (grid) {
                var cards = grid.querySelectorAll(':scope > .film-card');
                if (!cards.length) return;

                // Group cards by row (same offsetTop)
                var rowsByTop = {};
                cards.forEach(function (card) {
                    card.classList.remove('is-edge-left', 'is-edge-right');
                    var top = card.offsetTop;
                    if (!rowsByTop[top]) rowsByTop[top] = [];
                    rowsByTop[top].push(card);
                });

                // For each row, mark first as edge-left, last as edge-right
                Object.keys(rowsByTop).forEach(function (top) {
                    var row = rowsByTop[top];
                    if (row.length === 0) return;
                    row[0].classList.add('is-edge-left');
                    row[row.length - 1].classList.add('is-edge-right');
                });
            });
        }

        // Run on load + resize
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', detectEdges);
        } else {
            detectEdges();
        }

        var resizeTimer = null;
        window.addEventListener('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(detectEdges, 150);
        });

        // Re-run after images load (since layout can shift)
        window.addEventListener('load', detectEdges);
    })();
</script>
@endonce
