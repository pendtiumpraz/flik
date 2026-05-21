@php
    /** @var \App\Models\BlogCategory $category */
    /** @var \Illuminate\Contracts\Pagination\LengthAwarePaginator $posts */
    $categoryColor = $category->color ?: '#C5A55A';
@endphp

<x-layout :title="$category->name . ' — Blog ' . config('app.name', 'FLiK')"
          :description="'Artikel kategori ' . $category->name . ' dari blog editorial FLiK.'">
    <div class="bg-black min-h-screen pt-20 pb-16">
        <div class="container mx-auto px-4 lg:px-8 max-w-6xl">

            {{-- ── Back link ────────────────────────────────────── --}}
            <a href="{{ route('blog.index') }}"
               class="inline-flex items-center gap-2 text-xs text-gray-400 hover:text-[#C5A55A] transition-colors mb-6">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                <span>Kembali ke semua artikel</span>
            </a>

            {{-- ── Category header ──────────────────────────────── --}}
            <header class="mb-8 md:mb-12 pb-6 border-b border-white/5">
                <p class="text-xs font-semibold tracking-[0.3em] uppercase mb-2"
                   style="color: {{ $categoryColor }}">Kategori</p>
                <div class="flex flex-wrap items-baseline justify-between gap-4">
                    <h1 class="text-3xl md:text-5xl font-bold text-white font-heading">{{ $category->name }}</h1>
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold"
                          style="background: {{ $categoryColor }}1A; color: {{ $categoryColor }}; border: 1px solid {{ $categoryColor }}55">
                        {{ $posts->total() }} {{ $posts->total() === 1 ? 'artikel' : 'artikel' }}
                    </span>
                </div>
                @if(! empty($category->description))
                    <p class="text-gray-400 mt-3 max-w-2xl">{{ $category->description }}</p>
                @endif
            </header>

            {{-- ── Other categories (jump nav) ──────────────────── --}}
            @if(($categories ?? collect())->isNotEmpty())
                <div class="flex flex-wrap items-center gap-2 mb-8">
                    <a href="{{ route('blog.index') }}"
                       class="px-3 py-1.5 rounded-full text-xs font-semibold border text-gray-300 border-gray-700 hover:border-[#C5A55A] hover:text-[#C5A55A] transition-colors">
                        All
                    </a>
                    @foreach($categories as $cat)
                        <a href="{{ route('blog.category', $cat) }}"
                           class="px-3 py-1.5 rounded-full text-xs font-semibold border transition-colors {{ $cat->id === $category->id ? 'text-black' : 'text-gray-300 hover:text-white' }}"
                           style="{{ $cat->id === $category->id
                                ? 'background:'.$cat->color.';border-color:'.$cat->color.';color:#000'
                                : 'border-color: '.$cat->color.'66' }}">
                            {{ $cat->name }}
                        </a>
                    @endforeach
                </div>
            @endif

            {{-- ── Grid ─────────────────────────────────────────── --}}
            @if($posts->isEmpty())
                <div class="text-center py-20 text-gray-500">
                    <p class="text-lg mb-2">Belum ada artikel di kategori ini.</p>
                    <a href="{{ route('blog.index') }}" class="inline-block mt-3 text-sm text-[#C5A55A] hover:text-[#E8D5A3]">← Kembali ke semua artikel</a>
                </div>
            @else
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                    @foreach($posts as $post)
                        @include('blog.partials.post-card', ['post' => $post])
                    @endforeach
                </div>

                <div class="mt-10">{{ $posts->links() }}</div>
            @endif
        </div>
    </div>
</x-layout>
