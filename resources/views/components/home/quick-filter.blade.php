@props(['options'])

@php
    // Has any active filter?
    $hasActive = request()->hasAny(['q', 'genre', 'country', 'year', 'type', 'status', 'language', 'sort', 'letter']);
@endphp

<aside class="quick-filter rounded-xl overflow-hidden h-full"
       style="background: linear-gradient(180deg, #1a1a1a 0%, #141210 100%); border: 1px solid rgba(197,165,90,0.18)"
       x-data="{
            expanded: window.innerWidth >= 1024 || {{ $hasActive ? 'true' : 'false' }},
            init() {
                window.addEventListener('resize', () => {
                    if (window.innerWidth >= 1024) this.expanded = true;
                });
            }
       }">

    <!-- Header (clickable to toggle on mobile) -->
    <button type="button"
            @click="expanded = !expanded"
            class="w-full px-4 py-3 flex items-center justify-between border-b lg:cursor-default lg:pointer-events-none"
            style="border-color: rgba(197,165,90,0.15)">
        <div class="flex items-center gap-2">
            <x-icon name="search" :size="16" class="text-[#C5A55A]" />
            <h3 class="font-heading text-sm font-bold text-white">Quick Filter</h3>
            @if($hasActive)
                <span class="lg:hidden inline-flex items-center justify-center min-w-[18px] h-[18px] px-1.5 text-[9px] font-bold rounded-full text-black"
                      style="background: linear-gradient(135deg, #C5A55A, #E8D5A3)">
                    {{ collect(['q', 'genre', 'country', 'year', 'type', 'status', 'language', 'sort', 'letter'])->filter(fn($k) => request()->filled($k))->count() }}
                </span>
            @endif
        </div>
        <!-- Toggle icon (hidden on lg+) -->
        <span class="lg:hidden text-[#C5A55A] transition-transform duration-300"
              :class="expanded ? '-rotate-180' : ''">
            <x-icon name="chevron-down" :size="16" :stroke="2.5" />
        </span>
    </button>

    <!-- Filter Form (collapsible on mobile, always expanded on lg+) -->
    <form x-show="expanded"
          x-collapse.duration.300ms
          method="GET" action="{{ route('velflix.index') }}" class="p-4 space-y-3">

        <!-- Search -->
        <div>
            <label class="block text-[10px] uppercase tracking-wider text-gray-500 font-semibold mb-1.5">Search</label>
            <div class="relative">
                <input type="text" name="q" placeholder="Title, actor..."
                       value="{{ request('q') }}"
                       class="w-full pl-8 pr-3 py-2 text-xs text-white placeholder-gray-600 rounded-md focus:outline-none focus:border-[#C5A55A] transition-colors"
                       style="background: rgba(255,255,255,0.04); border: 1px solid rgba(197,165,90,0.2)">
                <x-icon name="search" :size="12" class="absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-500" />
            </div>
        </div>

        <!-- Genre -->
        <div>
            <label class="block text-[10px] uppercase tracking-wider text-gray-500 font-semibold mb-1.5">Genre</label>
            <select name="genre" class="filter-select">
                <option value="">All Genres</option>
                @foreach($options['genres'] as $g)
                    <option value="{{ $g->slug }}" {{ request('genre') === $g->slug ? 'selected' : '' }}>{{ $g->name }}</option>
                @endforeach
            </select>
        </div>

        <!-- Country -->
        <div>
            <label class="block text-[10px] uppercase tracking-wider text-gray-500 font-semibold mb-1.5">Country</label>
            <select name="country" class="filter-select">
                <option value="">All Countries</option>
                @foreach($options['countries'] as $c)
                    <option value="{{ $c }}" {{ request('country') === $c ? 'selected' : '' }}>{{ $c }}</option>
                @endforeach
            </select>
        </div>

        <!-- Two-col: Year + Type -->
        <div class="grid grid-cols-2 gap-2">
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-gray-500 font-semibold mb-1.5">Year</label>
                <select name="year" class="filter-select">
                    <option value="">All</option>
                    @foreach(array_slice($options['years'], 0, 30) as $y)
                        <option value="{{ $y }}" {{ request('year') == $y ? 'selected' : '' }}>{{ $y }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-gray-500 font-semibold mb-1.5">Type</label>
                <select name="type" class="filter-select">
                    <option value="">All</option>
                    @foreach($options['types'] as $t)
                        <option value="{{ $t }}" {{ request('type') === $t ? 'selected' : '' }}>{{ $t }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <!-- Two-col: Status + Language -->
        <div class="grid grid-cols-2 gap-2">
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-gray-500 font-semibold mb-1.5">Status</label>
                <select name="status" class="filter-select">
                    <option value="">All</option>
                    @foreach($options['statuses'] as $s)
                        <option value="{{ $s }}" {{ request('status') === $s ? 'selected' : '' }}>{{ $s }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-gray-500 font-semibold mb-1.5">Language</label>
                <select name="language" class="filter-select">
                    <option value="">All</option>
                    @foreach($options['languages'] as $l)
                        <option value="{{ $l }}" {{ request('language') === $l ? 'selected' : '' }}>{{ $l }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <!-- Sort -->
        <div>
            <label class="block text-[10px] uppercase tracking-wider text-gray-500 font-semibold mb-1.5">Sort by</label>
            <select name="sort" class="filter-select">
                @foreach($options['sortOptions'] as $s)
                    <option value="{{ \Illuminate\Support\Str::slug($s) }}" {{ request('sort') === \Illuminate\Support\Str::slug($s) ? 'selected' : '' }}>{{ $s }}</option>
                @endforeach
            </select>
        </div>

        <!-- Submit -->
        <button type="submit" class="w-full py-2.5 mt-2 rounded-md text-xs font-bold text-black hover:opacity-95 transition-opacity flex items-center justify-center gap-1.5"
                style="background: linear-gradient(135deg, #C5A55A, #E8D5A3)">
            <x-icon name="search" :size="13" />
            Apply Filter
        </button>

        @if(request()->hasAny(['q', 'genre', 'country', 'year', 'type', 'status', 'language', 'sort']))
        <a href="{{ route('velflix.index') }}" class="block text-center text-[11px] text-gray-500 hover:text-[#C5A55A] transition-colors">Reset filter</a>
        @endif
    </form>

    <style>
        .filter-select {
            width: 100%;
            padding: 6px 8px;
            font-size: 11px;
            color: #fff;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(197,165,90,0.2);
            border-radius: 6px;
            cursor: pointer;
            transition: border-color 200ms;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%23C5A55A' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 6px center;
            background-size: 14px;
            padding-right: 22px;
        }
        .filter-select:focus { outline: none; border-color: #C5A55A; }
        .filter-select option { background: #1a1a1a; color: #fff; }
    </style>
</aside>
