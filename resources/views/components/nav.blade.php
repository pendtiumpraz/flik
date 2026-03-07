@props(['popular'])

@if (count($popular) > 0)
<section class="relative h-[60vh] md:h-[75vh] lg:h-screen w-full bg-black">
    <div class="absolute z-10 h-full w-full">
        <div class="flex h-full items-end md:items-center justify-start px-4 pb-16 md:pb-0 md:px-16">
            <div class="w-full md:w-3/5 lg:w-2/5 flex-col space-y-3 md:space-y-4">
                <h1 class="font-heading text-3xl md:text-5xl lg:text-6xl font-semibold" style="color: #C5A55A">
                    {{ $popular[0]['title'] }}
                </h1>
                <p class="text-sm md:text-base lg:text-lg font-medium text-gray-200 line-clamp-3 md:line-clamp-none">
                    {{ $popular[0]['overview'] }}
                </p>
                <div class="flex w-full flex-row gap-3 pt-2">
                    <button
                        class="flex items-center justify-center gap-2 rounded-md bg-white px-4 md:px-6 py-2 md:py-2.5 shadow-md hover:bg-gray-200 transition-colors">
                        <x-bi-caret-right-fill class="h-5 w-5 md:h-6 md:w-6 text-black" />
                        <span class="font-semibold text-black text-sm md:text-base">Play</span>
                    </button>
                    <button
                        class="flex items-center justify-center gap-2 rounded-md bg-gray-500/50 px-4 md:px-6 py-2 md:py-2.5 shadow-md hover:bg-gray-500/70 transition-colors">
                        <x-bi-info-circle class="h-4 w-4 md:h-5 md:w-5 font-bold text-white" />
                        <span class="font-semibold text-white text-sm md:text-base">More Info</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="absolute bottom-0 h-32 md:h-64 w-full bg-gradient-to-t from-black"></div>

    <div class="-mt-8 object-cover h-full lg:h-screen">
        <img class="h-full w-full object-cover md:object-contain"
        src="{{ $popular[0]['poster_path'] }}"
        alt="{{ $popular[0]['title'] }}"
        onerror="this.onerror=null;this.style.background='#141414';this.src='https://via.placeholder.com/1280x720/141414/C5A55A?text={{ urlencode($popular[0]['title'] ?? 'Featured') }}'">
    </div>
</section>
@else
<section class="relative flex h-[50vh] md:h-[60vh] w-full items-center justify-center bg-black">
    <div class="text-center px-4">
        <h1 class="text-2xl md:text-4xl font-semibold" style="color: #C5A55A">Welcome to FLiK</h1>
        <p class="mt-4 text-base md:text-lg text-gray-400">No movies available. Please run <code class="px-2 py-1 rounded bg-gray-800 text-sm" style="color: #C5A55A">php artisan db:seed</code> to populate the database.</p>
    </div>
</section>
@endif
