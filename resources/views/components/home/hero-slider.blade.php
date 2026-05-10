@props(['movies', 'genres'])

@php
    $genreMap = $genres ?? collect();
@endphp

<div class="hero-slider relative rounded-xl overflow-hidden h-full"
     style="background: #0a0a0a; border: 1px solid rgba(197,165,90,0.15); min-height: 320px;"
     x-data="{
        active: 0,
        total: {{ $movies->count() }},
        timer: null,
        autoplay() {
            this.timer = setInterval(() => { this.next(); }, 6000);
        },
        next() { this.active = (this.active + 1) % this.total; },
        prev() { this.active = (this.active - 1 + this.total) % this.total; },
        goto(i) { this.active = i; clearInterval(this.timer); this.autoplay(); }
     }"
     x-init="autoplay()"
     @mouseenter="clearInterval(timer)"
     @mouseleave="autoplay()">

    @foreach($movies as $idx => $movie)
        @php
            $movieSlug = $movie['slug'] ?? $movie['id'];
            $movieGenres = collect($movie['genre_ids'] ?? [])
                ->map(fn ($gid) => $genreMap[$gid] ?? null)
                ->filter()
                ->take(3);
        @endphp

        <div x-show="active === {{ $idx }}"
             x-transition:enter="transition-opacity ease-out duration-700"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             class="absolute inset-0">

            <!-- Slider image (cinematic ~2.4:1) — fallback to backdrop_path → poster_path -->
            <img src="{{ $movie['slider_path'] ?? $movie['backdrop_path'] ?? $movie['poster_path'] }}"
                 alt="{{ $movie['title'] }}"
                 class="w-full h-full object-cover"
                 onerror="this.onerror=null">

            <!-- Gradient overlays -->
            <div class="absolute inset-0" style="background: linear-gradient(90deg, rgba(0,0,0,0.95) 0%, rgba(0,0,0,0.7) 40%, transparent 70%)"></div>
            <div class="absolute inset-0" style="background: linear-gradient(180deg, transparent 50%, rgba(0,0,0,0.85) 100%)"></div>

            <!-- Content -->
            <div class="absolute inset-0 flex flex-col justify-end p-5 md:p-8 lg:p-10 max-w-2xl">
                <!-- Meta -->
                <div class="flex items-center gap-2 mb-2 text-xs">
                    @if($movie['vote_average'])
                        <span class="inline-flex items-center gap-1 font-bold text-[#C5A55A]">
                            <x-icon name="star-solid" :size="12" />
                            {{ number_format($movie['vote_average'], 1) }}
                        </span>
                    @endif
                    @if(!empty($movie['release_date']))
                        <span class="text-gray-400">{{ substr($movie['release_date'], 0, 4) }}</span>
                    @endif
                    <span class="px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wider rounded" style="background: rgba(197,165,90,0.15); color: #C5A55A; border: 1px solid rgba(197,165,90,0.3)">HD</span>
                    @if($movieGenres->count())
                        <span class="text-gray-400">·</span>
                        <span class="text-gray-300">{{ $movieGenres->join(', ') }}</span>
                    @endif
                </div>

                <!-- Title -->
                <h1 class="font-heading text-2xl md:text-4xl lg:text-5xl font-bold text-white leading-tight mb-2 md:mb-3">
                    {{ $movie['title'] }}
                </h1>

                <!-- Synopsis -->
                @if($movie['overview'])
                <p class="text-xs md:text-sm text-gray-300 line-clamp-2 md:line-clamp-3 mb-3 md:mb-4 leading-relaxed">
                    {{ $movie['overview'] }}
                </p>
                @endif

                <!-- CTAs -->
                <div class="flex items-center gap-2">
                    <a href="{{ route('movies.show', $movieSlug) }}"
                       class="inline-flex items-center gap-1.5 md:gap-2 px-4 md:px-5 py-2 md:py-2.5 rounded-md text-xs md:text-sm font-bold text-black hover:opacity-95 transition-opacity"
                       style="background: linear-gradient(135deg, #C5A55A, #E8D5A3)">
                        <x-icon name="play-solid" :size="14" />
                        Play Now
                    </a>
                    <a href="{{ route('movies.show', $movieSlug) }}"
                       class="inline-flex items-center gap-1.5 md:gap-2 px-4 md:px-5 py-2 md:py-2.5 rounded-md text-xs md:text-sm font-medium text-white hover:border-[#C5A55A] transition-colors"
                       style="background: rgba(255,255,255,0.08); border: 1px solid rgba(197,165,90,0.25); backdrop-filter: blur(8px)">
                        <x-icon name="info" :size="14" class="text-[#C5A55A]" />
                        More Info
                    </a>
                </div>
            </div>
        </div>
    @endforeach

    <!-- Prev / Next nav -->
    @if($movies->count() > 1)
    <button @click="prev(); clearInterval(timer); autoplay()"
            class="absolute left-2 md:left-3 top-1/2 -translate-y-1/2 z-10 w-9 h-9 md:w-10 md:h-10 rounded-full flex items-center justify-center backdrop-blur transition-all hover:scale-110"
            style="background: rgba(0,0,0,0.5); border: 1px solid rgba(197,165,90,0.3)"
            aria-label="Previous">
        <x-icon name="chevron-left" :size="16" class="text-[#C5A55A]" :stroke="2.5" />
    </button>
    <button @click="next(); clearInterval(timer); autoplay()"
            class="absolute right-2 md:right-3 top-1/2 -translate-y-1/2 z-10 w-9 h-9 md:w-10 md:h-10 rounded-full flex items-center justify-center backdrop-blur transition-all hover:scale-110"
            style="background: rgba(0,0,0,0.5); border: 1px solid rgba(197,165,90,0.3)"
            aria-label="Next">
        <x-icon name="chevron-right" :size="16" class="text-[#C5A55A]" :stroke="2.5" />
    </button>

    <!-- Dots -->
    <div class="absolute bottom-3 md:bottom-4 left-1/2 -translate-x-1/2 z-10 flex gap-1.5">
        @foreach($movies as $idx => $movie)
            <button @click="goto({{ $idx }})"
                    class="rounded-full transition-all"
                    :class="active === {{ $idx }} ? 'w-6 h-1.5' : 'w-1.5 h-1.5'"
                    :style="active === {{ $idx }} ? 'background: #C5A55A' : 'background: rgba(255,255,255,0.4)'"></button>
        @endforeach
    </div>
    @endif
</div>
