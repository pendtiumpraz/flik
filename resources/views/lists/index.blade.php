<x-layout title="Discover Lists — FLiK">
    <div class="min-h-screen bg-black pt-20 pb-16">
        <div class="container mx-auto px-4 md:px-8 lg:px-16 max-w-[1600px]">

            {{-- ── Header ──────────────────────────────────────── --}}
            <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4 mb-8">
                <div>
                    <h1 class="font-heading text-2xl md:text-4xl font-bold" style="color: #C5A55A">
                        Discover Lists
                    </h1>
                    <p class="mt-1 text-sm text-gray-400">
                        Koleksi film hasil kurasi komunitas FLiK.
                    </p>
                </div>

                @auth
                    <div class="flex items-center gap-2">
                        <a href="{{ route('user-lists.mine') }}"
                           class="inline-flex items-center gap-2 px-3 py-2 rounded-lg text-sm text-gray-300 hover:text-[#C5A55A] transition-colors"
                           style="background: rgba(197,165,90,0.06); border: 1px solid rgba(197,165,90,0.18);">
                            <x-icon name="user" :size="14" /> My Lists
                        </a>
                        <a href="{{ route('user-lists.following') }}"
                           class="inline-flex items-center gap-2 px-3 py-2 rounded-lg text-sm text-gray-300 hover:text-[#C5A55A] transition-colors"
                           style="background: rgba(197,165,90,0.06); border: 1px solid rgba(197,165,90,0.18);">
                            <x-icon name="heart" :size="14" /> Following
                        </a>
                        <a href="{{ route('user-lists.create') }}"
                           class="inline-flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-semibold text-black"
                           style="background: linear-gradient(135deg, #C5A55A, #E8D5A3);">
                            <x-icon name="plus" :size="14" /> New List
                        </a>
                    </div>
                @endauth
            </div>

            {{-- ── Filters ─────────────────────────────────────── --}}
            <form method="GET" action="{{ route('user-lists.index') }}"
                  class="mb-8 flex flex-col md:flex-row gap-2 md:items-center">

                <div class="relative flex-1">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500">
                        <x-icon name="search" :size="16" />
                    </span>
                    <input type="text" name="q" value="{{ $filters['q'] }}"
                           placeholder="Cari list berdasarkan judul..."
                           class="w-full pl-10 pr-3 py-2.5 rounded-lg text-sm text-white placeholder-gray-500"
                           style="background: rgba(20,18,16,0.6); border: 1px solid rgba(197,165,90,0.18);">
                </div>

                <input type="text" name="user" value="{{ $filters['user'] }}"
                       placeholder="@username"
                       class="md:w-56 px-3 py-2.5 rounded-lg text-sm text-white placeholder-gray-500"
                       style="background: rgba(20,18,16,0.6); border: 1px solid rgba(197,165,90,0.18);">

                <select name="sort"
                        class="md:w-56 px-3 py-2.5 rounded-lg text-sm text-white"
                        style="background: rgba(20,18,16,0.6); border: 1px solid rgba(197,165,90,0.18);"
                        onchange="this.form.submit()">
                    <option value="most-followed" @selected($filters['sort'] === 'most-followed')>Most followed</option>
                    <option value="most-items"    @selected($filters['sort'] === 'most-items')>Most items</option>
                    <option value="newest"        @selected($filters['sort'] === 'newest')>Newest</option>
                </select>

                <button type="submit"
                        class="px-4 py-2.5 rounded-lg text-sm font-semibold text-black"
                        style="background: linear-gradient(135deg, #C5A55A, #E8D5A3);">
                    Apply
                </button>
            </form>

            {{-- ── Grid ────────────────────────────────────────── --}}
            @if($lists->total() === 0)
                <div class="flex flex-col items-center justify-center py-24 text-center rounded-xl"
                     style="background: rgba(20,18,16,0.5); border: 1px solid rgba(197,165,90,0.15);">
                    <x-icon name="film" :size="36" class="text-gray-700 mb-3" />
                    <h2 class="text-lg font-heading font-semibold text-gray-400">
                        Belum ada list yang cocok
                    </h2>
                    <p class="text-sm text-gray-600 mt-1">Coba ubah filter atau jadilah yang pertama membuat list.</p>
                    @auth
                        <a href="{{ route('user-lists.create') }}"
                           class="mt-5 inline-flex items-center gap-2 px-5 py-2.5 rounded-lg font-semibold text-black"
                           style="background: linear-gradient(135deg, #C5A55A, #E8D5A3);">
                            <x-icon name="plus" :size="14" /> Buat List Baru
                        </a>
                    @endauth
                </div>
            @else
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                    @foreach($lists as $list)
                        <x-lists.card :list="$list" />
                    @endforeach
                </div>

                <div class="mt-8">
                    {{ $lists->links() }}
                </div>
            @endif
        </div>
    </div>

    <x-footer />
</x-layout>
