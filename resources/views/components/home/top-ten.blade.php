@props(['today', 'week', 'month'])

<aside class="rounded-xl overflow-hidden h-full"
       style="background: linear-gradient(180deg, #1a1a1a 0%, #141210 100%); border: 1px solid rgba(197,165,90,0.18)"
       x-data="{ tab: 'week' }">

    <!-- Header -->
    <div class="px-4 py-3 border-b" style="border-color: rgba(197,165,90,0.15)">
        <div class="flex items-center gap-2 mb-3">
            <x-icon name="trophy" :size="16" class="text-[#C5A55A]" />
            <h3 class="font-heading text-sm font-bold text-white">Top 10</h3>
        </div>

        <!-- Tabs -->
        <div class="flex gap-1 bg-black/30 rounded-md p-0.5">
            <button @click="tab = 'today'" :class="tab === 'today' ? 'tab-active' : 'tab-inactive'" class="tab-btn">Today</button>
            <button @click="tab = 'week'" :class="tab === 'week' ? 'tab-active' : 'tab-inactive'" class="tab-btn">Week</button>
            <button @click="tab = 'month'" :class="tab === 'month' ? 'tab-active' : 'tab-inactive'" class="tab-btn">Month</button>
        </div>
    </div>

    <!-- Lists (one per tab) -->
    @foreach (['today' => $today, 'week' => $week, 'month' => $month] as $period => $list)
    <div x-show="tab === '{{ $period }}'" x-cloak class="p-2">
        @forelse($list as $idx => $movie)
            @php $movieSlug = $movie['slug'] ?? $movie['id']; @endphp
            <a href="{{ route('movies.show', $movieSlug) }}"
               class="group flex items-center gap-2.5 p-2 rounded-md hover:bg-[#C5A55A]/8 transition-colors">
                <!-- Rank -->
                <div class="font-heading font-black text-2xl flex-shrink-0 w-7 text-center"
                     style="color: {{ $idx === 0 ? '#C5A55A' : ($idx <= 2 ? '#a08544' : '#3a3a3a') }}; -webkit-text-stroke: 1px {{ $idx === 0 ? '#C5A55A' : ($idx <= 2 ? '#a08544' : '#444') }}">
                    {{ $idx + 1 }}
                </div>
                <!-- Poster thumbnail -->
                <img src="{{ $movie['poster_path'] }}" alt="{{ $movie['title'] }}"
                     class="w-9 h-12 rounded object-cover flex-shrink-0"
                     onerror="this.onerror=null">
                <!-- Title + rating -->
                <div class="flex-1 min-w-0">
                    <div class="text-xs font-medium text-white truncate group-hover:text-[#C5A55A] transition-colors">{{ $movie['title'] }}</div>
                    <div class="flex items-center gap-1 mt-0.5">
                        <x-icon name="star-solid" :size="9" class="text-[#C5A55A]" />
                        <span class="text-[10px] text-gray-500">{{ number_format($movie['vote_average'], 1) }}</span>
                        @if(!empty($movie['release_date']))
                            <span class="text-gray-700">·</span>
                            <span class="text-[10px] text-gray-600">{{ substr($movie['release_date'], 0, 4) }}</span>
                        @endif
                    </div>
                </div>
            </a>
        @empty
            <p class="text-center text-gray-600 py-8 text-xs">No data yet</p>
        @endforelse
    </div>
    @endforeach

    <style>
        .tab-btn {
            flex: 1;
            padding: 5px 8px;
            font-size: 11px;
            font-weight: 600;
            border-radius: 4px;
            transition: all 200ms;
            border: none;
            cursor: pointer;
        }
        .tab-active {
            background: linear-gradient(135deg, #C5A55A, #E8D5A3);
            color: #000;
        }
        .tab-inactive {
            background: transparent;
            color: #888;
        }
        .tab-inactive:hover { color: #C5A55A; }
    </style>
</aside>
