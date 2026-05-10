<x-layout>
    <div class="bg-black min-h-screen pt-16">
        <div class="container mx-auto px-4 md:px-6 lg:px-8 max-w-[1600px] py-6 md:py-8">

            <!-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
            <!-- SECTION 1: Hero Slider (3/4) + Quick Filter Sidebar (1/4) -->
            <!-- Equal height via items-stretch + h-full on children -->
            <!-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
            <div class="grid grid-cols-1 lg:grid-cols-4 gap-4 md:gap-6 mb-8 md:mb-12 items-stretch">

                <!-- Hero Slider — 3/4 -->
                <div class="lg:col-span-3 h-full">
                    <x-home.hero-slider :movies="$heroSlider" :genres="$genres" />
                </div>

                <!-- Quick Filter Sidebar — 1/4 -->
                <div class="lg:col-span-1 h-full">
                    <x-home.quick-filter :options="$filterOptions" />
                </div>
            </div>

            <!-- Continue Watching (auth) -->
            @auth
            @if($continueWatching->count() > 0)
            <div class="mb-8 md:mb-10">
                <x-movies :movies="$continueWatching" :genres="$genres">
                    <x-slot:category>
                        <x-icon name="play-solid" :size="16" class="text-[#C5A55A]" />
                        <span>Continue Watching</span>
                    </x-slot:category>
                </x-movies>
            </div>
            @endif
            @endauth

            <!-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
            <!-- SECTION 2: Trending+ComingSoon (3/4) + Top 10 Sidebar (1/4) -->
            <!-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
            <div class="grid grid-cols-1 lg:grid-cols-4 gap-4 md:gap-6 mb-8 md:mb-12">

                <!-- Main column 3/4 -->
                <div class="lg:col-span-3 space-y-8 md:space-y-10">

                    <!-- Trending Now (paginated, or filtered results) -->
                    <div id="trending-now">
                        @if($hasFilter)
                            <div class="flex items-center gap-3 mb-3 md:mb-4">
                                <div class="flex items-center gap-2">
                                    <x-icon name="search" :size="16" class="text-[#C5A55A]" />
                                    <h2 class="font-heading text-base md:text-lg font-semibold text-white">{{ $trendingTitle }}</h2>
                                    <span class="text-[11px] text-gray-500">({{ $trendingPaginator->total() }} films)</span>
                                </div>
                                <a href="{{ route('velflix.index') }}#trending-now" class="ml-auto inline-flex items-center gap-1 text-[11px] text-gray-500 hover:text-[#C5A55A] transition-colors">
                                    <x-icon name="x" :size="11" />
                                    Reset filter
                                </a>
                            </div>
                            @if($trending->count() > 0)
                                <x-movies :movies="$trending" :genres="$genres" density="large">
                                    <x-slot:category><span class="hidden">{{ $trendingTitle }}</span></x-slot:category>
                                </x-movies>
                            @else
                                <div class="text-center py-12 rounded-xl" style="background: rgba(20,18,16,0.5); border: 1px solid rgba(197,165,90,0.15)">
                                    <x-icon name="film" :size="32" class="mx-auto text-gray-700 mb-2" />
                                    <p class="text-sm text-gray-500">Tidak ada film yang cocok dengan filter</p>
                                    <a href="{{ route('velflix.index') }}" class="inline-block mt-3 text-xs text-[#C5A55A] hover:underline">Reset filter</a>
                                </div>
                            @endif
                        @else
                            <x-movies :movies="$trending" :genres="$genres" density="large">
                                <x-slot:category>
                                    <x-icon name="lightning" :size="16" class="text-[#C5A55A]" />
                                    <span>{{ $trendingTitle }}</span>
                                </x-slot:category>
                            </x-movies>
                        @endif

                        <!-- Pagination -->
                        @if($trendingPaginator->lastPage() > 1)
                        <div class="mt-5 flex items-center justify-between">
                            <div class="text-[11px] text-gray-500">
                                Showing <span class="text-[#C5A55A] font-semibold">{{ $trendingPaginator->firstItem() }}-{{ $trendingPaginator->lastItem() }}</span>
                                of <span class="text-[#C5A55A] font-semibold">{{ $trendingPaginator->total() }}</span>
                            </div>
                            <nav class="flex items-center gap-1">
                                {{-- Prev --}}
                                @if($trendingPaginator->onFirstPage())
                                    <span class="page-btn page-disabled" aria-label="Previous">
                                        <x-icon name="chevron-left" :size="14" />
                                    </span>
                                @else
                                    <a href="{{ $trendingPaginator->previousPageUrl() }}#trending-now" class="page-btn" aria-label="Previous">
                                        <x-icon name="chevron-left" :size="14" />
                                    </a>
                                @endif

                                {{-- Page numbers (smart: max 5 visible) --}}
                                @php
                                    $cur = $trendingPaginator->currentPage();
                                    $last = $trendingPaginator->lastPage();
                                    $start = max(1, $cur - 2);
                                    $end = min($last, $start + 4);
                                    if ($end - $start < 4) $start = max(1, $end - 4);
                                @endphp

                                @if($start > 1)
                                    <a href="{{ $trendingPaginator->url(1) }}#trending-now" class="page-btn">1</a>
                                    @if($start > 2)<span class="page-dots">…</span>@endif
                                @endif

                                @for($p = $start; $p <= $end; $p++)
                                    @if($p == $cur)
                                        <span class="page-btn page-active">{{ $p }}</span>
                                    @else
                                        <a href="{{ $trendingPaginator->url($p) }}#trending-now" class="page-btn">{{ $p }}</a>
                                    @endif
                                @endfor

                                @if($end < $last)
                                    @if($end < $last - 1)<span class="page-dots">…</span>@endif
                                    <a href="{{ $trendingPaginator->url($last) }}#trending-now" class="page-btn">{{ $last }}</a>
                                @endif

                                {{-- Next --}}
                                @if($trendingPaginator->hasMorePages())
                                    <a href="{{ $trendingPaginator->nextPageUrl() }}#trending-now" class="page-btn" aria-label="Next">
                                        <x-icon name="chevron-right" :size="14" />
                                    </a>
                                @else
                                    <span class="page-btn page-disabled" aria-label="Next">
                                        <x-icon name="chevron-right" :size="14" />
                                    </span>
                                @endif
                            </nav>
                        </div>
                        @endif
                    </div>

                    <!-- Coming Soon -->
                    <x-movies :movies="$comingSoon" :genres="$genres">
                        <x-slot:category>
                            <x-icon name="clock" :size="16" class="text-[#C5A55A]" />
                            <span>Coming Soon</span>
                        </x-slot:category>
                    </x-movies>
                </div>

                <!-- Top 10 Sidebar 1/4 -->
                <div class="lg:col-span-1">
                    <x-home.top-ten :today="$topToday" :week="$topWeek" :month="$topMonth" />
                </div>
            </div>

            <!-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
            <!-- SECTION 3: A-Z List (alphabet navigation only — no movie grid) -->
            <!-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
            <x-home.az-list :currentLetter="$currentLetter" :genres="$genres" />

        </div>
    </div>

    <!-- Floating Chatbot Widget -->
    <x-home.chatbot-widget />

    <x-footer />

    <style>
        /* Pagination buttons */
        .page-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            height: 32px;
            padding: 0 8px;
            font-size: 12px;
            font-weight: 600;
            color: #888;
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(197,165,90,0.15);
            border-radius: 6px;
            transition: all 200ms;
            text-decoration: none;
            cursor: pointer;
        }
        a.page-btn:hover {
            color: #C5A55A;
            border-color: rgba(197,165,90,0.4);
            background: rgba(197,165,90,0.08);
        }
        .page-active {
            color: #000 !important;
            background: linear-gradient(135deg, #C5A55A, #E8D5A3) !important;
            border-color: #C5A55A !important;
        }
        .page-disabled {
            opacity: 0.3;
            cursor: not-allowed;
        }
        .page-dots {
            color: #555;
            padding: 0 4px;
            font-size: 12px;
        }
    </style>
</x-layout>
