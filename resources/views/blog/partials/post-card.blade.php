{{-- Reusable blog post card. Expects: $post (BlogPost with category eager-loaded). --}}
@php
    /** @var \App\Models\BlogPost $post */
    $cover = $post->cover_url;
@endphp
<a href="{{ $post->url }}"
   class="group block rounded-xl overflow-hidden bg-[#141414] border border-gray-800 hover:border-[#C5A55A]/60 transition-colors">
    <div class="aspect-[16/9] bg-gradient-to-br from-[#1a1a1a] to-[#0a0a0a] overflow-hidden">
        @if($cover)
            <img src="{{ $cover }}" alt="{{ $post->title }}"
                 class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500"
                 loading="lazy">
        @else
            <div class="w-full h-full flex items-center justify-center text-[#C5A55A]/30 text-5xl font-bold">FLiK</div>
        @endif
    </div>
    <div class="p-4">
        @if($post->category)
            <span class="inline-block px-2 py-0.5 rounded text-[10px] font-semibold uppercase tracking-wider mb-2"
                  style="background: {{ $post->category->color }}22; color: {{ $post->category->color }}">
                {{ $post->category->name }}
            </span>
        @endif
        <h3 class="font-bold text-base text-white group-hover:text-[#C5A55A] transition-colors leading-snug line-clamp-2">
            {{ $post->title }}
        </h3>
        @if($post->excerpt)
            <p class="mt-2 text-sm text-gray-400 line-clamp-2">{{ $post->excerpt }}</p>
        @endif
        <div class="mt-3 flex items-center gap-2 text-[11px] text-gray-500">
            <span>{{ $post->author?->name ?? 'Tim FLiK' }}</span>
            <span>·</span>
            <span>{{ $post->published_at?->isoFormat('D MMM YYYY') }}</span>
            <span>·</span>
            <span>{{ $post->reading_minutes }} min</span>
        </div>
    </div>
</a>
