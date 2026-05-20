<x-layout title="My Lists — FLiK">
    <div class="min-h-screen bg-black pt-20 pb-16">
        <div class="container mx-auto px-4 md:px-8 lg:px-16 max-w-[1400px]">

            <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4 mb-8">
                <div>
                    <h1 class="font-heading text-2xl md:text-4xl font-bold" style="color: #C5A55A">My Lists</h1>
                    <p class="mt-1 text-sm text-gray-400">List yang kamu kurasi sendiri.</p>
                </div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('user-lists.index') }}"
                       class="inline-flex items-center gap-2 px-3 py-2 rounded-lg text-sm text-gray-300 hover:text-[#C5A55A] transition-colors"
                       style="background: rgba(197,165,90,0.06); border: 1px solid rgba(197,165,90,0.18);">
                        <x-icon name="search" :size="14" /> Discover
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
            </div>

            @if($lists->total() === 0)
                <div class="flex flex-col items-center justify-center py-24 text-center rounded-xl"
                     style="background: rgba(20,18,16,0.5); border: 1px solid rgba(197,165,90,0.15);">
                    <x-icon name="film" :size="36" class="text-gray-700 mb-3" />
                    <h2 class="text-lg font-heading font-semibold text-gray-400">Belum ada list</h2>
                    <p class="text-sm text-gray-600 mt-1">Mulai kurasi film favorit kamu.</p>
                    <a href="{{ route('user-lists.create') }}"
                       class="mt-5 inline-flex items-center gap-2 px-5 py-2.5 rounded-lg font-semibold text-black"
                       style="background: linear-gradient(135deg, #C5A55A, #E8D5A3);">
                        <x-icon name="plus" :size="14" /> Buat List Pertama
                    </a>
                </div>
            @else
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                    @foreach($lists as $list)
                        <x-lists.card :list="$list" :showOwner="false" />
                    @endforeach
                </div>
                <div class="mt-8">{{ $lists->links() }}</div>
            @endif
        </div>
    </div>
    <x-footer />
</x-layout>
