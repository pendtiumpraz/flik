<div class="relative">
    <div class="relative">
        <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        <input wire:model.live.debounce.300ms="search"
               type="text"
               placeholder="Cari film, genre, pemain..."
               class="w-full pl-10 pr-4 py-2 rounded-lg bg-white/10 border border-white/10 text-white text-sm placeholder-gray-500 focus:outline-none focus:border-gold-500 focus:ring-1"
               style="focus:ring-color:rgba(197,165,90,0.5)">
    </div>

    @if (strlen($search) >= 3)
    <div class="absolute mt-2 left-0 right-0 rounded-xl overflow-hidden shadow-2xl z-50" style="background:rgba(20,20,20,0.95);backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,0.1)">
        @if ($results->count() > 0)
        <ul class="divide-y" style="divide-color:rgba(255,255,255,0.05)">
            @foreach ($results as $result)
            <li>
                <a href="{{ route('movies.show', $result['id']) }}" class="flex items-center gap-3 px-4 py-3 hover:bg-white/5 transition-colors">
                    @if ($result['poster_url'])
                    <img src="{{ $result['poster_url'] }}" alt="" class="w-8 h-12 rounded object-cover flex-shrink-0">
                    @endif
                    <span class="text-sm text-white">{{ $result['title'] }}</span>
                </a>
            </li>
            @endforeach
        </ul>
        @else
        <div class="px-4 py-6 text-center">
            <p class="text-sm text-gray-400">Tidak ada hasil untuk "<span class="text-white">{{ $search }}</span>"</p>
            <p class="text-xs text-gray-600 mt-2">Coba kata kunci lain atau cari genre, pemain, sutradara</p>
        </div>
        @endif
    </div>
    @endif
</div>
