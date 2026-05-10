@props(['movies', 'currentLetter', 'genres'])

@php
    $letters = array_merge(['All', '0-9'], range('A', 'Z'));
@endphp

<section class="az-list mb-8" id="az-list">
    <!-- Header -->
    <div class="flex items-center justify-between mb-3">
        <div class="flex items-center gap-2">
            <x-icon name="film" :size="16" class="text-[#C5A55A]" />
            <h2 class="font-heading text-sm md:text-base font-bold text-white">Browse by A-Z</h2>
        </div>
        <span class="text-[10px] text-gray-500 hidden md:inline">Pilih huruf untuk filter</span>
    </div>

    <!-- Alphabet filter strip — pure navigation, centered & wrappable -->
    <div class="rounded-xl p-2.5 md:p-3"
         style="background: linear-gradient(180deg, #1a1a1a 0%, #141210 100%); border: 1px solid rgba(197,165,90,0.18)">
        <div class="flex flex-wrap gap-1.5 justify-center">
            @foreach($letters as $letter)
                <a href="{{ route('velflix.index', ['letter' => $letter]) }}#trending-now"
                   class="az-btn {{ $currentLetter === $letter ? 'az-active' : '' }}">
                    {{ $letter }}
                </a>
            @endforeach
        </div>
    </div>
</section>

<style>
    .az-btn {
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
    }
    .az-btn:hover {
        color: #C5A55A;
        border-color: rgba(197,165,90,0.4);
        background: rgba(197,165,90,0.08);
    }
    .az-active {
        color: #000 !important;
        background: linear-gradient(135deg, #C5A55A, #E8D5A3) !important;
        border-color: #C5A55A !important;
    }
</style>
