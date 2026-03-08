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
                        <svg class="h-5 w-5 md:h-6 md:w-6 text-black" fill="currentColor" viewBox="0 0 16 16"><path d="m12.14 8.753-5.482 4.796c-.646.566-1.658.106-1.658-.753V3.204a1 1 0 0 1 1.659-.753l5.48 4.796a1 1 0 0 1 0 1.506z"/></svg>
                        <span class="font-semibold text-black text-sm md:text-base">Play</span>
                    </button>
                    <button
                        class="flex items-center justify-center gap-2 rounded-md bg-gray-500/50 px-4 md:px-6 py-2 md:py-2.5 shadow-md hover:bg-gray-500/70 transition-colors">
                        <svg class="h-4 w-4 md:h-5 md:w-5 font-bold text-white" fill="currentColor" viewBox="0 0 16 16"><path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/><path d="m8.93 6.588-2.29.287-.082.38.45.083c.294.07.352.176.288.469l-.738 3.468c-.194.897.105 1.319.808 1.319.545 0 1.178-.252 1.465-.598l.088-.416c-.2.176-.492.246-.686.246-.275 0-.375-.193-.304-.533L8.93 6.588zM9 4.5a1 1 0 1 1-2 0 1 1 0 0 1 2 0z"/></svg>
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
