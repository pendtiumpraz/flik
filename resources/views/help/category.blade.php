<x-layout :title="$category->name . ' — Pusat Bantuan ' . config('app.name', 'FLiK')"
          :description="$category->description ?? 'Artikel bantuan untuk ' . $category->name">
    <div class="bg-black min-h-screen pt-24 pb-20">
        <div class="container mx-auto px-4 lg:px-8 max-w-5xl">

            {{-- Breadcrumb --}}
            <nav class="text-sm text-gray-500 mb-6">
                <a href="{{ route('help.index') }}" class="hover:text-[#C5A55A]">Pusat Bantuan</a>
                <span class="mx-2 text-gray-700">&rsaquo;</span>
                <span class="text-gray-300">{{ $category->name }}</span>
            </nav>

            {{-- Header --}}
            <div class="mb-10">
                <div class="flex items-start gap-4">
                    @if($category->icon)
                        <div class="w-14 h-14 rounded-xl bg-[#C5A55A]/10 flex items-center justify-center text-[#C5A55A] flex-shrink-0">
                            <x-icon :name="$category->icon" :size="28" />
                        </div>
                    @endif
                    <div>
                        <h1 class="text-3xl md:text-4xl font-bold text-white font-heading">{{ $category->name }}</h1>
                        @if($category->description)
                            <p class="text-gray-400 mt-2">{{ $category->description }}</p>
                        @endif
                        <p class="text-xs text-gray-500 mt-2">{{ $category->articles_count }} artikel</p>
                    </div>
                </div>
            </div>

            {{-- Inline search --}}
            <div class="mb-8">
                @include('help.partials.search-bar', ['size' => 'compact'])
            </div>

            {{-- Article list --}}
            @if($articles->isEmpty())
                <div class="text-center py-20 text-gray-500">
                    <p>Belum ada artikel di kategori ini.</p>
                </div>
            @else
                <div class="space-y-3">
                    @foreach($articles as $art)
                        <a href="{{ route('help.show', $art->slug) }}"
                           class="block bg-[#141414] hover:bg-[#1a1a1a] border border-[#2a2a2a] hover:border-[#C5A55A]/40 rounded-xl p-5 transition-all">
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex-1 min-w-0">
                                    <h2 class="text-lg font-semibold text-white">{{ $art->title }}</h2>
                                    @if($art->excerpt)
                                        <p class="text-sm text-gray-400 mt-2 line-clamp-2">{{ $art->excerpt }}</p>
                                    @endif
                                </div>
                                <div class="text-[#C5A55A] flex-shrink-0 text-xl">&rarr;</div>
                            </div>
                        </a>
                    @endforeach
                </div>

                @if($articles->hasPages())
                    <div class="mt-8">{{ $articles->links() }}</div>
                @endif
            @endif

        </div>
    </div>
</x-layout>
