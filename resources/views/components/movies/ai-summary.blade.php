@props(['movie'])

{{--
    AI-generated short summary (3 sentences).
    Renders only if `ai_short_summary` is populated. Distinct from the full
    `overview` synopsis — meant as a quick "elevator pitch" near the top of
    the detail page.
--}}

@if(filled($movie->ai_short_summary))
<section class="mt-6 md:mt-8" aria-labelledby="ai-summary-heading">
    <div class="relative p-5 md:p-6 rounded-xl overflow-hidden"
         style="background: linear-gradient(135deg, rgba(197,165,90,0.08) 0%, rgba(20,18,16,0.7) 60%); border: 1px solid rgba(197,165,90,0.25)">
        {{-- Gold accent bar --}}
        <div class="absolute top-0 left-0 h-full w-1" style="background: linear-gradient(180deg, #C5A55A, #E8D5A3)"></div>

        <div class="flex items-center gap-2 mb-2.5">
            <x-icon name="sparkles" :size="16" class="text-[#C5A55A]" />
            <h2 id="ai-summary-heading"
                class="text-[10px] uppercase tracking-[0.18em] font-semibold text-[#C5A55A]">
                AI Quick Take
            </h2>
        </div>

        <p class="text-sm md:text-base text-gray-200 leading-relaxed font-light italic">
            {{ $movie->ai_short_summary }}
        </p>
    </div>
</section>
@endif
