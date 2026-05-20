@props(['cast'])

{{-- ━━━ Reusable cast/director card ━━━
     Used by the listing grid and the "related people" sidebar. Renders a
     square portrait + name + role badge + movie count. --}}
<a href="{{ route('public.cast.show', ['cast' => $cast->id, 'slug' => $cast->slug]) }}"
   class="group block rounded-xl overflow-hidden border border-white/5 bg-[#141210]/60 hover:border-[#C5A55A]/40 transition-all duration-300 hover:-translate-y-0.5">
    <div class="relative aspect-square overflow-hidden bg-black/40">
        <img src="{{ $cast->profile_image }}"
             alt="{{ $cast->name }}"
             loading="lazy"
             class="absolute inset-0 w-full h-full object-cover group-hover:scale-105 transition-transform duration-500">
        <div class="absolute inset-x-0 bottom-0 h-2/3 bg-gradient-to-t from-black/90 via-black/40 to-transparent"></div>

        @php($role = $cast->role)
        <span class="absolute top-2 right-2 px-2 py-0.5 text-[10px] uppercase tracking-wider rounded-full font-semibold
                     {{ $role === 'director' ? 'bg-[#C5A55A] text-black' : 'bg-black/60 text-[#C5A55A] border border-[#C5A55A]/40' }}">
            {{ $role === 'director' ? 'Director' : 'Actor' }}
        </span>
    </div>
    <div class="p-3">
        <h3 class="text-sm font-semibold text-white line-clamp-1 group-hover:text-[#C5A55A] transition-colors">
            {{ $cast->name }}
        </h3>
        @if(isset($cast->movies_count))
            <div class="mt-0.5 text-[11px] text-gray-400">
                {{ $cast->movies_count }} {{ Str::plural('film', $cast->movies_count) }}
            </div>
        @endif
        @if(! empty($cast->nationality))
            <div class="mt-0.5 text-[10px] text-gray-500">{{ $cast->nationality }}</div>
        @endif
    </div>
</a>
