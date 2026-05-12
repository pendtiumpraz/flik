@props(['movie'])

{{--
    "Memorable Quotes" — up to 3 AI-curated quotes.
    Each rendered as italic large text with attribution to character_name when available.
    Decorative oversized gold quotation marks in the background.
--}}

@php
    /** @var \Illuminate\Database\Eloquent\Collection<\App\Models\MovieQuote> $quotes */
    $quotes = $movie->quotes ?? collect();
@endphp

@if($quotes->isNotEmpty())
<section class="mt-8 md:mt-10" aria-labelledby="ai-quotes-heading">
    <div class="flex items-center gap-2.5 mb-5">
        <x-icon name="sparkles" :size="20" class="text-[#C5A55A]" />
        <h2 id="ai-quotes-heading"
            class="font-heading text-lg md:text-xl font-semibold text-white">
            Memorable Quotes
        </h2>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 md:gap-5">
        @foreach($quotes as $quote)
        <figure class="relative overflow-hidden p-5 md:p-6 rounded-xl flex flex-col"
                style="background: linear-gradient(160deg, rgba(20,18,16,0.75) 0%, rgba(15,12,10,0.7) 100%);
                       border: 1px solid rgba(197,165,90,0.18)">
            {{-- Decorative oversized open-quote SVG (gold, low opacity) --}}
            <svg class="absolute -top-2 -left-1 w-20 h-20 md:w-24 md:h-24 pointer-events-none"
                 viewBox="0 0 24 24" fill="currentColor"
                 style="color: rgba(197,165,90,0.12)"
                 aria-hidden="true">
                <path d="M9.983 3v7.391c0 5.704-3.731 9.57-8.983 10.609l-.995-2.151c2.432-.917 3.995-3.638 3.995-5.849h-4v-10h9.983zm14.017 0v7.391c0 5.704-3.748 9.571-9 10.609l-.996-2.151c2.433-.917 3.996-3.638 3.996-5.849h-3.983v-10h9.983z"/>
            </svg>

            <blockquote class="relative z-10 flex-1">
                <p class="text-base md:text-lg text-gray-100 italic font-light leading-relaxed">
                    {{ $quote->quote }}
                </p>
                @if(filled($quote->translation))
                    <p class="mt-2 text-xs text-gray-500 not-italic">
                        {{ $quote->translation }}
                    </p>
                @endif
            </blockquote>

            <figcaption class="relative z-10 mt-4 pt-3 flex items-center justify-between gap-2"
                        style="border-top: 1px solid rgba(197,165,90,0.12)">
                <div class="flex items-center gap-2 min-w-0">
                    <span class="w-1 h-4 rounded-full flex-shrink-0"
                          style="background: linear-gradient(180deg, #C5A55A, #E8D5A3)"></span>
                    <cite class="not-italic text-xs md:text-sm font-medium text-[#C5A55A] truncate">
                        @if(filled($quote->character_name))
                            {{ $quote->character_name }}
                        @else
                            Anonymous
                        @endif
                    </cite>
                </div>

                @if(!empty($quote->timestamp_seconds))
                    @php
                        $secs = (int) $quote->timestamp_seconds;
                        $mm = floor($secs / 60);
                        $ss = str_pad((string) ($secs % 60), 2, '0', STR_PAD_LEFT);
                    @endphp
                    <span class="inline-flex items-center gap-1 text-[10px] text-gray-500 flex-shrink-0">
                        <x-icon name="clock" :size="11" /> {{ $mm }}:{{ $ss }}
                    </span>
                @endif
            </figcaption>
        </figure>
        @endforeach
    </div>
</section>
@endif
