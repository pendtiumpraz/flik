@props(['movie'])

{{--
    AI-generated soundtrack analysis (FIX #7 — wired SoundtrackAnalyzer).

    Reads movies.soundtrack_analysis (JSON cast to array) and renders a
    collapsible card with composer / style / mood / key_tracks /
    era_authenticity / recommendation. Self-hides when the column is
    NULL or the payload is structurally empty.

    Generated lazily by the admin "Generate soundtrack analysis" button
    (POST /admin/movies/{movie}/soundtrack) → AnalyzeSoundtrack job →
    SoundtrackAnalyzer::analyze().
--}}

@php
    /** @var \App\Models\Movie $movie */
    $data = is_array($movie->soundtrack_analysis ?? null)
        ? $movie->soundtrack_analysis
        : null;

    $hasContent = $data && (
        !empty($data['composer'])
        || !empty($data['style'])
        || !empty($data['mood'])
        || !empty($data['key_tracks'])
        || !empty($data['era_authenticity'])
        || !empty($data['recommendation'])
    );
@endphp

@if($hasContent)
    <section x-data="{ open: false }" class="mt-8 md:mt-10">
        <button type="button"
                @click="open = !open"
                class="w-full flex items-center justify-between p-4 md:p-5 rounded-xl transition-colors hover:border-[#C5A55A]"
                style="background: linear-gradient(135deg, rgba(20,18,16,0.7) 0%, rgba(15,12,10,0.7) 100%); border: 1px solid rgba(197,165,90,0.25)">
            <div class="flex items-center gap-3 text-left">
                <div class="w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0"
                     style="background: rgba(197,165,90,0.15); border: 1px solid rgba(197,165,90,0.35)">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="#C5A55A" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 18V5l12-2v13"/>
                        <circle cx="6" cy="18" r="3"/>
                        <circle cx="18" cy="16" r="3"/>
                    </svg>
                </div>
                <div>
                    <div class="text-[10px] uppercase tracking-widest font-semibold text-[#C5A55A] mb-0.5">AI Soundtrack Analysis</div>
                    <div class="text-sm md:text-base font-heading font-semibold text-white">Analisis Musik & Soundtrack</div>
                </div>
            </div>
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-[#C5A55A] transition-transform" :class="open ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M6 9l6 6 6-6"/>
            </svg>
        </button>

        <div x-show="open" x-collapse x-cloak
             class="mt-2 p-4 md:p-5 rounded-xl space-y-3"
             style="background: rgba(20,18,16,0.5); border: 1px solid rgba(197,165,90,0.15)">

            @if(!empty($data['composer']))
                <div class="flex flex-col sm:flex-row sm:items-baseline gap-1 sm:gap-3">
                    <div class="text-[10px] uppercase tracking-wider text-[#C5A55A]/80 font-semibold w-32 flex-shrink-0">Composer</div>
                    <div class="text-sm text-gray-200">{{ $data['composer'] }}</div>
                </div>
            @endif

            @if(!empty($data['style']))
                <div class="flex flex-col sm:flex-row sm:items-baseline gap-1 sm:gap-3">
                    <div class="text-[10px] uppercase tracking-wider text-[#C5A55A]/80 font-semibold w-32 flex-shrink-0">Style</div>
                    <div class="text-sm text-gray-200">{{ $data['style'] }}</div>
                </div>
            @endif

            @if(!empty($data['mood']))
                <div class="flex flex-col sm:flex-row sm:items-baseline gap-1 sm:gap-3">
                    <div class="text-[10px] uppercase tracking-wider text-[#C5A55A]/80 font-semibold w-32 flex-shrink-0">Mood</div>
                    <div class="text-sm text-gray-200">{{ $data['mood'] }}</div>
                </div>
            @endif

            @if(!empty($data['key_tracks']) && is_array($data['key_tracks']))
                <div class="flex flex-col sm:flex-row sm:items-baseline gap-1 sm:gap-3">
                    <div class="text-[10px] uppercase tracking-wider text-[#C5A55A]/80 font-semibold w-32 flex-shrink-0">Key Tracks</div>
                    <div class="text-sm text-gray-200 flex flex-wrap gap-1.5">
                        @foreach($data['key_tracks'] as $track)
                            <span class="inline-block px-2 py-0.5 text-xs rounded" style="background: rgba(197,165,90,0.1); color: #E8D5A3; border: 1px solid rgba(197,165,90,0.25)">{{ $track }}</span>
                        @endforeach
                    </div>
                </div>
            @endif

            @if(!empty($data['era_authenticity']))
                <div class="flex flex-col sm:flex-row sm:items-baseline gap-1 sm:gap-3">
                    <div class="text-[10px] uppercase tracking-wider text-[#C5A55A]/80 font-semibold w-32 flex-shrink-0">Era Authenticity</div>
                    <div class="text-sm text-gray-200">{{ $data['era_authenticity'] }}</div>
                </div>
            @endif

            @if(!empty($data['recommendation']))
                <div class="flex flex-col sm:flex-row sm:items-baseline gap-1 sm:gap-3 pt-3 mt-3" style="border-top: 1px dashed rgba(197,165,90,0.2)">
                    <div class="text-[10px] uppercase tracking-wider text-[#C5A55A]/80 font-semibold w-32 flex-shrink-0">Mirip ini?</div>
                    <div class="text-sm text-gray-200 italic">{{ $data['recommendation'] }}</div>
                </div>
            @endif
        </div>
    </section>
@endif
