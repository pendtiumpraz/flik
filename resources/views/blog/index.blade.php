<x-layout :title="'Blog — ' . config('app.name', 'FLiK')"
          description="Editorial blog FLiK — review, list, dan berita seputar sinema Indonesia & dunia.">
    <div class="bg-black min-h-screen pt-20 pb-16">
        <div class="container mx-auto px-4 lg:px-8 max-w-6xl">

            {{-- Page header --}}
            <div class="mb-8 md:mb-12">
                <p class="text-[#C5A55A] text-xs font-semibold tracking-[0.3em] uppercase mb-2">FLiK Editorial</p>
                <h1 class="text-3xl md:text-5xl font-bold text-white">Blog</h1>
                <p class="text-gray-400 mt-2 max-w-2xl">Catatan, review, dan rekomendasi tontonan terkurasi tim FLiK.</p>
            </div>

            {{-- Featured spotlight --}}
            @if($featured)
                @php
                    $cover = $featured->cover_image
                        ? (str_starts_with($featured->cover_image, 'http') ? $featured->cover_image : asset('storage/' . $featured->cover_image))
                        : null;
                @endphp
                <a href="{{ $featured->url }}"
                   class="block mb-10 group relative overflow-hidden rounded-2xl border border-[#C5A55A]/30 bg-gradient-to-br from-[#141414] to-[#0a0a0a]"
                   style="aspect-ratio: 21/9">
                    @if($cover)
                        <img src="{{ $cover }}" alt="{{ $featured->title }}"
                             class="absolute inset-0 w-full h-full object-cover opacity-60 group-hover:opacity-75 group-hover:scale-105 transition-all duration-700">
                    @endif
                    <div class="absolute inset-0 bg-gradient-to-t from-black via-black/70 to-transparent"></div>
                    <div class="absolute bottom-0 left-0 right-0 p-6 md:p-10">
                        <span class="inline-block px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-[0.2em] mb-3"
                              style="background: #C5A55A; color: #000">★ Featured</span>
                        @if($featured->category)
                            <span class="inline-block ml-2 px-2 py-1 rounded text-[10px] font-semibold uppercase tracking-wider"
                                  style="background: {{ $featured->category->color }}33; color: {{ $featured->category->color }}">
                                {{ $featured->category->name }}
                            </span>
                        @endif
                        <h2 class="mt-3 text-2xl md:text-4xl font-bold text-white group-hover:text-[#C5A55A] transition-colors leading-tight max-w-3xl">
                            {{ $featured->title }}
                        </h2>
                        @if($featured->excerpt)
                            <p class="mt-2 text-gray-300 max-w-2xl line-clamp-2">{{ $featured->excerpt }}</p>
                        @endif
                        <div class="mt-4 flex items-center gap-3 text-xs text-gray-400">
                            <span>{{ $featured->author?->name ?? 'Tim FLiK' }}</span>
                            <span>·</span>
                            <span>{{ $featured->published_at?->isoFormat('D MMM YYYY') }}</span>
                            <span>·</span>
                            <span>{{ $featured->reading_minutes }} min read</span>
                        </div>
                    </div>
                </a>
            @endif

            {{-- Categories + search --}}
            <div class="flex flex-wrap items-center gap-2 mb-6">
                <a href="{{ route('blog.index') }}"
                   class="px-3 py-1.5 rounded-full text-xs font-semibold border transition-colors {{ $activeCategory === '' ? 'bg-[#C5A55A] text-black border-[#C5A55A]' : 'text-gray-300 border-gray-700 hover:border-[#C5A55A] hover:text-[#C5A55A]' }}">
                    All
                </a>
                @foreach($categories as $cat)
                    <a href="{{ route('blog.category', $cat) }}"
                       class="px-3 py-1.5 rounded-full text-xs font-semibold border transition-colors {{ $activeCategory === $cat->slug ? 'text-black' : 'text-gray-300 border-gray-700 hover:text-white' }}"
                       style="{{ $activeCategory === $cat->slug ? 'background:'.$cat->color.';border-color:'.$cat->color.';color:#000' : 'border-color: '.$cat->color.'66' }}">
                        {{ $cat->name }}
                    </a>
                @endforeach
                <div class="ml-auto flex items-center gap-2">
                    <form method="get" action="{{ route('blog.index') }}" class="flex items-center gap-2">
                        <input type="text" name="q" value="{{ $q }}" placeholder="Cari artikel..."
                               class="px-3 py-1.5 rounded-full bg-[#141414] border border-gray-700 text-sm text-white focus:outline-none focus:border-[#C5A55A]">
                        <button type="submit" class="px-3 py-1.5 rounded-full bg-[#C5A55A] text-black text-xs font-semibold">Search</button>
                    </form>
                    <a href="{{ route('blog.rss') }}" title="RSS Feed"
                       class="px-2.5 py-1.5 rounded-full border border-gray-700 text-gray-400 hover:text-[#C5A55A] hover:border-[#C5A55A] transition-colors" target="_blank">
                        RSS
                    </a>
                </div>
            </div>

            {{-- Grid --}}
            @if($posts->isEmpty())
                <div class="text-center py-16 text-gray-500">
                    <p>Tidak ada artikel ditemukan.</p>
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
