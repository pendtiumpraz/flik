@props(['movie'])

{{--
    "Did You Know?" — carousel/grid of AI-generated trivia facts.
    Mobile: horizontal swipeable strip (snap scroll).
    Desktop (md+): 2-column grid.
    Each card shows the fact + a category badge.
--}}

@php
    /** @var \Illuminate\Database\Eloquent\Collection<\App\Models\MovieTrivia> $items */
    $items = $movie->trivia ?? collect();

    $categoryLabels = [
        'production'    => 'Produksi',
        'cast'          => 'Pemeran',
        'reception'     => 'Resepsi',
        'behind_scenes' => 'Behind The Scenes',
        'easter_egg'    => 'Easter Egg',
        'cultural'      => 'Kultural',
    ];
@endphp

@if($items->isNotEmpty())
<section class="mt-8 md:mt-10" aria-labelledby="ai-trivia-heading">
    <div class="flex items-center gap-2.5 mb-4">
        <x-icon name="sparkles" :size="20" class="text-[#C5A55A]" />
        <h2 id="ai-trivia-heading"
            class="font-heading text-lg md:text-xl font-semibold text-white">
            Did You Know?
        </h2>
        <span class="text-[10px] px-2 py-0.5 rounded-full"
              style="background: rgba(197,165,90,0.1); color: #C5A55A">
            {{ $items->count() }} facts
        </span>
    </div>

    {{-- Mobile: swipeable strip / Desktop: 2-col grid --}}
    <div class="flex gap-3 overflow-x-auto snap-x snap-mandatory pb-3 -mx-4 px-4
                md:grid md:grid-cols-2 md:gap-4 md:overflow-visible md:mx-0 md:px-0 md:pb-0
                scrollbar-hide">
        @foreach($items as $trivia)
        <article class="flex-shrink-0 w-[85%] sm:w-[60%] md:w-auto snap-start
                        p-4 md:p-5 rounded-xl flex flex-col gap-3"
                 style="background: rgba(20,18,16,0.6); border: 1px solid rgba(197,165,90,0.15)">
            <div class="flex items-start gap-2">
                <span class="flex-shrink-0 inline-flex items-center justify-center
                             w-7 h-7 rounded-full text-[11px] font-bold text-black"
                      style="background: linear-gradient(135deg, #C5A55A, #E8D5A3)">
                    {{ $loop->iteration }}
                </span>
                <p class="flex-1 text-sm md:text-[15px] text-gray-200 leading-relaxed">
                    {{ $trivia->fact }}
                </p>
            </div>

            <div class="flex items-center gap-2 mt-auto">
                <span class="inline-block px-2 py-0.5 text-[10px] uppercase tracking-wider rounded font-medium"
                      style="background: rgba(197,165,90,0.12); color: #C5A55A; border: 1px solid rgba(197,165,90,0.25)">
                    {{ $categoryLabels[$trivia->category] ?? ucfirst(str_replace('_', ' ', (string) $trivia->category)) }}
                </span>
                @if($trivia->is_verified)
                    <span class="inline-flex items-center gap-1 text-[10px] text-green-400/80">
                        <x-icon name="check" :size="11" :stroke="3" /> Verified
                    </span>
                @endif
                @if(!empty($trivia->source_url))
                    <a href="{{ $trivia->source_url }}" target="_blank" rel="noopener noreferrer"
                       class="ml-auto text-[10px] text-gray-500 hover:text-[#C5A55A] transition-colors underline-offset-2 hover:underline">
                        Sumber
                    </a>
                @endif
            </div>
        </article>
        @endforeach
    </div>
</section>
@endif
