@props(['sections'])

@php
    /**
     * @var \Illuminate\Support\Collection<int, \App\Models\MovieBehindScenes> $sections
     * Expected: ordered collection of MovieBehindScenes for a single movie.
     */
    $sections = collect($sections ?? []);
@endphp

@if($sections->isNotEmpty())
@php
    // Aggregate all source URLs across sections, de-duplicated.
    $allSources = $sections
        ->flatMap(fn ($s) => is_array($s->source_urls) ? $s->source_urls : [])
        ->filter()
        ->unique()
        ->values();

    $firstKey = $sections->first()->section;
@endphp

<section class="mt-10 md:mt-12"
         x-data="{ activeTab: '{{ $firstKey }}', expanded: {} }">

    <!-- Header -->
    <div class="flex items-center gap-2 mb-5">
        <div class="w-1 h-6 rounded-full" style="background: #C5A55A"></div>
        <h2 class="font-heading text-xl md:text-2xl font-bold text-white">
            Behind the Scenes
        </h2>
        <span class="ml-2 text-[10px] uppercase tracking-widest font-semibold px-2 py-0.5 rounded"
              style="background: rgba(197,165,90,0.15); color: #C5A55A; border: 1px solid rgba(197,165,90,0.3)">
            AI&nbsp;Curated
        </span>
    </div>

    <div class="rounded-2xl overflow-hidden"
         style="background: linear-gradient(180deg, #141210 0%, #0d0c0a 100%); border: 1px solid rgba(197,165,90,0.18)">

        <!-- ── Tabs (md and up) ──────────────────────────────────── -->
        <div class="hidden md:block border-b" style="border-color: rgba(197,165,90,0.15)">
            <nav class="flex flex-wrap gap-1 px-3 py-2">
                @foreach($sections as $sec)
                    <button type="button"
                            @click="activeTab = '{{ $sec->section }}'"
                            :class="activeTab === '{{ $sec->section }}'
                                ? 'text-[#C5A55A] bg-[#C5A55A]/10 border-[#C5A55A]/40'
                                : 'text-gray-400 hover:text-white border-transparent hover:bg-white/5'"
                            class="px-3 py-1.5 text-xs font-medium rounded-md border transition-all">
                        {{ \App\Models\MovieBehindScenes::SECTION_LABELS[$sec->section] ?? ucfirst($sec->section) }}
                    </button>
                @endforeach
            </nav>
        </div>

        <!-- ── Tab Panels (md and up) ────────────────────────────── -->
        <div class="hidden md:block p-6 lg:p-8">
            @foreach($sections as $sec)
                @php
                    $key = $sec->section;
                    $isLong = mb_strlen($sec->content) > 320;
                    $shortText = $isLong ? mb_substr($sec->content, 0, 320) . '...' : $sec->content;
                @endphp
                <div x-show="activeTab === '{{ $key }}'" x-cloak x-transition.opacity.duration.250ms>
                    <h3 class="font-heading text-lg lg:text-xl font-semibold text-white mb-1">
                        {{ $sec->title }}
                    </h3>
                    <div class="text-[11px] uppercase tracking-widest font-semibold mb-4"
                         style="color: #C5A55A">
                        {{ \App\Models\MovieBehindScenes::SECTION_LABELS[$key] ?? ucfirst($key) }}
                    </div>

                    @if($isLong)
                        <p class="text-gray-300 text-sm md:text-base leading-relaxed whitespace-pre-line"
                           x-show="!expanded['{{ $key }}']">{{ $shortText }}</p>
                        <p class="text-gray-300 text-sm md:text-base leading-relaxed whitespace-pre-line"
                           x-show="expanded['{{ $key }}']" x-cloak>{{ $sec->content }}</p>

                        <button type="button"
                                @click="expanded['{{ $key }}'] = !expanded['{{ $key }}']"
                                class="mt-3 inline-flex items-center gap-1 text-xs font-semibold transition-colors hover:underline"
                                style="color: #C5A55A">
                            <span x-show="!expanded['{{ $key }}']">Baca selengkapnya</span>
                            <span x-show="expanded['{{ $key }}']" x-cloak>Sembunyikan</span>
                            <svg class="w-3 h-3 transition-transform"
                                 :class="expanded['{{ $key }}'] ? 'rotate-180' : ''"
                                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>
                    @else
                        <p class="text-gray-300 text-sm md:text-base leading-relaxed whitespace-pre-line">{{ $sec->content }}</p>
                    @endif
                </div>
            @endforeach
        </div>

        <!-- ── Accordion (mobile) ───────────────────────────────── -->
        <div class="md:hidden divide-y" style="--tw-divide-opacity: 1; border-color: rgba(197,165,90,0.12)">
            @foreach($sections as $sec)
                @php
                    $key = 'm_' . $sec->section;
                    $isLong = mb_strlen($sec->content) > 220;
                    $shortText = $isLong ? mb_substr($sec->content, 0, 220) . '...' : $sec->content;
                @endphp
                <div class="px-4 py-3" style="border-color: rgba(197,165,90,0.12); border-top: 1px solid rgba(197,165,90,0.12);"
                     x-data="{ open: {{ $loop->first ? 'true' : 'false' }} }">
                    <button type="button"
                            @click="open = !open"
                            class="w-full flex items-center justify-between gap-3 text-left">
                        <div class="min-w-0">
                            <div class="text-[10px] uppercase tracking-widest font-semibold"
                                 style="color: #C5A55A">
                                {{ \App\Models\MovieBehindScenes::SECTION_LABELS[$sec->section] ?? ucfirst($sec->section) }}
                            </div>
                            <div class="font-heading text-sm font-semibold text-white truncate mt-0.5">
                                {{ $sec->title }}
                            </div>
                        </div>
                        <svg class="w-4 h-4 flex-shrink-0 transition-transform text-gray-500"
                             :class="open ? 'rotate-180 text-[#C5A55A]' : ''"
                             fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>

                    <div x-show="open" x-collapse x-cloak class="mt-3"
                         x-data="{ expandedM: false }">
                        @if($isLong)
                            <p class="text-gray-300 text-sm leading-relaxed whitespace-pre-line"
                               x-show="!expandedM">{{ $shortText }}</p>
                            <p class="text-gray-300 text-sm leading-relaxed whitespace-pre-line"
                               x-show="expandedM" x-cloak>{{ $sec->content }}</p>
                            <button type="button"
                                    @click="expandedM = !expandedM"
                                    class="mt-2 text-xs font-semibold hover:underline"
                                    style="color: #C5A55A">
                                <span x-show="!expandedM">Baca selengkapnya</span>
                                <span x-show="expandedM" x-cloak>Sembunyikan</span>
                            </button>
                        @else
                            <p class="text-gray-300 text-sm leading-relaxed whitespace-pre-line">{{ $sec->content }}</p>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        <!-- ── Source citations footer ──────────────────────────── -->
        @if($allSources->isNotEmpty())
            <div class="px-4 md:px-6 py-3 border-t bg-black/30"
                 style="border-color: rgba(197,165,90,0.15)">
                <div class="flex items-start gap-2 text-[11px] text-gray-500">
                    <svg class="w-3 h-3 mt-0.5 flex-shrink-0" style="color: #C5A55A"
                         fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                    </svg>
                    <div class="flex-1 min-w-0">
                        <span class="font-semibold uppercase tracking-wider mr-1.5"
                              style="color: #C5A55A">Sumber:</span>
                        @foreach($allSources as $i => $url)
                            <a href="{{ $url }}" target="_blank" rel="noopener noreferrer"
                               class="hover:text-[#C5A55A] hover:underline transition-colors break-all">
                                {{ parse_url($url, PHP_URL_HOST) ?: $url }}
                            </a>@if(!$loop->last)<span class="mx-1 text-gray-700">·</span>@endif
                        @endforeach
                    </div>
                </div>
            </div>
        @endif
    </div>
</section>
@endif
