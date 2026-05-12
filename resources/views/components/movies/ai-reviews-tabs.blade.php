@props(['movie'])

{{--
    AI Reviews — 4 perspective tabs (critic / casual / family / academic).
    Renders only the perspectives that actually have content. Tab switching
    handled with Alpine.js.
--}}

@php
    /** @var \Illuminate\Database\Eloquent\Collection<\App\Models\MovieAiReview> $reviews */
    $reviews = ($movie->aiReviews ?? collect())->keyBy('perspective');

    // Preserve canonical perspective order, but filter to only those present.
    $orderedPerspectives = collect(\App\Models\MovieAiReview::PERSPECTIVES)
        ->filter(fn ($p) => $reviews->has($p))
        ->values();

    $labels = \App\Models\MovieAiReview::PERSPECTIVE_LABELS;
    $initialTab = $orderedPerspectives->first();
@endphp

@if($orderedPerspectives->isNotEmpty())
<section class="mt-8 md:mt-10" aria-labelledby="ai-reviews-heading">
    <div class="flex items-center justify-between flex-wrap gap-2 mb-3">
        <div class="flex items-center gap-2.5">
            <x-icon name="sparkles" :size="20" class="text-[#C5A55A]" />
            <h2 id="ai-reviews-heading"
                class="font-heading text-lg md:text-xl font-semibold text-white">
                Reviews
            </h2>
        </div>
        <span class="inline-flex items-center gap-1.5 text-[10px] uppercase tracking-wider px-2.5 py-1 rounded-full"
              style="background: rgba(197,165,90,0.1); color: #C5A55A; border: 1px solid rgba(197,165,90,0.25)">
            <x-icon name="info" :size="11" />
            Reviewed by FLiK AI
        </span>
    </div>

    <div x-data="{ tab: '{{ $initialTab }}' }" class="rounded-xl overflow-hidden"
         style="background: rgba(20,18,16,0.55); border: 1px solid rgba(197,165,90,0.15)">
        {{-- Tab buttons --}}
        <div class="flex overflow-x-auto scrollbar-hide"
             style="border-bottom: 1px solid rgba(197,165,90,0.15)"
             role="tablist"
             aria-label="AI review perspectives">
            @foreach($orderedPerspectives as $perspective)
                <button
                    type="button"
                    role="tab"
                    :aria-selected="tab === '{{ $perspective }}' ? 'true' : 'false'"
                    @click="tab = '{{ $perspective }}'"
                    class="flex-shrink-0 px-4 md:px-5 py-3 text-xs md:text-sm font-medium transition-all whitespace-nowrap"
                    :class="tab === '{{ $perspective }}'
                        ? 'text-[#C5A55A]'
                        : 'text-gray-500 hover:text-gray-300'"
                    :style="tab === '{{ $perspective }}'
                        ? 'border-bottom: 2px solid #C5A55A; background: rgba(197,165,90,0.05);'
                        : 'border-bottom: 2px solid transparent;'">
                    {{ $labels[$perspective] ?? ucfirst($perspective) }}
                </button>
            @endforeach
        </div>

        {{-- Tab panels --}}
        @foreach($orderedPerspectives as $perspective)
            @php $review = $reviews[$perspective]; @endphp
            <div x-show="tab === '{{ $perspective }}'"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 translate-y-1"
                 x-transition:enter-end="opacity-100 translate-y-0"
                 x-cloak
                 role="tabpanel"
                 class="p-5 md:p-6">
                <div class="flex items-start justify-between gap-3 flex-wrap mb-3">
                    <h3 class="font-heading text-base md:text-lg font-semibold text-white leading-snug">
                        {{ $review->title }}
                    </h3>
                    @if(!is_null($review->rating))
                        <div class="flex items-center gap-1.5 flex-shrink-0 px-2.5 py-1 rounded-md"
                             style="background: rgba(197,165,90,0.1); border: 1px solid rgba(197,165,90,0.25)">
                            <x-icon name="star-solid" :size="12" class="text-[#C5A55A]" />
                            <span class="text-sm font-bold text-[#C5A55A]">{{ rtrim(rtrim(number_format((float) $review->rating, 1), '0'), '.') }}</span>
                            <span class="text-[10px] text-gray-500">/10</span>
                        </div>
                    @endif
                </div>

                <div class="text-sm md:text-[15px] text-gray-300 leading-relaxed whitespace-pre-line">
                    {{ $review->body }}
                </div>

                <div class="mt-4 pt-3 flex items-center justify-between gap-2 flex-wrap text-[10px] text-gray-500"
                     style="border-top: 1px solid rgba(197,165,90,0.1)">
                    <span class="inline-flex items-center gap-1.5">
                        <x-icon name="user" :size="11" class="text-[#C5A55A]/70" />
                        {{ $labels[$perspective] ?? ucfirst($perspective) }} perspective
                    </span>
                    @if($review->generated_at)
                        <span>Generated {{ $review->generated_at->diffForHumans() }}</span>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</section>
@endif
