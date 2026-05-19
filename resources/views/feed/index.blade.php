@php
    /** @var \App\Models\User $viewer */
    /** @var \Illuminate\Support\Collection $items */
@endphp

<x-layout title="Feed — FLiK">
    <div class="min-h-screen bg-black pt-20 pb-16">
        <div class="container mx-auto px-4 md:px-16 max-w-3xl">
            <div class="mb-6">
                <h1 class="font-heading text-2xl md:text-3xl font-bold text-white">Activity Feed</h1>
                <p class="text-sm text-gray-500 mt-1">Latest from people you follow &mdash; past 7 days.</p>
            </div>

            @if($items->isEmpty())
                <div class="p-12 rounded-xl text-center" style="background:#1a1a1a;border:1px solid #2a2a2a">
                    <div class="text-5xl mb-3">👀</div>
                    <h2 class="font-heading text-lg font-semibold text-white">Nothing here yet</h2>
                    <p class="text-sm text-gray-500 mt-2">
                        Follow some accounts and their ratings, comments, and watchlist adds will show up here.
                    </p>
                    <a href="{{ route('velflix.index') }}"
                       class="inline-block mt-5 px-5 py-2 rounded-lg text-sm font-semibold text-black"
                       style="background:#C5A55A">Browse films</a>
                </div>
            @else
                <div class="space-y-3">
                    @foreach($items as $item)
                        @php
                            $u       = $item['user'];
                            $movie   = $item['movie'];
                            $when    = $item['created_at']?->diffForHumans();
                            $profile = $u?->publicProfileUrl() ?? '#';
                            $avatar  = $u?->avatar_url;
                            $initial = strtoupper(substr($u?->name ?? '?', 0, 1));
                        @endphp
                        <div class="p-4 rounded-xl flex gap-3" style="background:#1a1a1a;border:1px solid #2a2a2a">
                            <a href="{{ $profile }}" class="shrink-0">
                                @if($avatar)
                                    <img src="{{ $avatar }}" alt="{{ $u?->name }}" class="w-10 h-10 rounded-full object-cover">
                                @else
                                    <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-semibold text-black"
                                         style="background: linear-gradient(135deg, #C5A55A, #E8D5A3)">
                                        {{ $initial }}
                                    </div>
                                @endif
                            </a>

                            <div class="flex-1 min-w-0">
                                <div class="text-sm text-gray-300">
                                    <a href="{{ $profile }}" class="font-semibold text-white hover:text-[#C5A55A]">{{ $u?->name }}</a>
                                    @switch($item['type'])
                                        @case('rating')
                                            rated
                                            @if($movie)
                                                <a href="{{ route('movies.show', $movie->slug ?? $movie->id) }}"
                                                   class="text-[#C5A55A] hover:underline">{{ $movie->title }}</a>
                                            @endif
                                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 ml-1 rounded text-[10px] font-bold"
                                                  style="background:rgba(197,165,90,0.2);color:#C5A55A">
                                                &starf; {{ $item['meta']['score'] }}
                                            </span>
                                            @break
                                        @case('comment')
                                            commented on
                                            @if($movie)
                                                <a href="{{ route('movies.show', $movie->slug ?? $movie->id) }}"
                                                   class="text-[#C5A55A] hover:underline">{{ $movie->title }}</a>
                                            @endif
                                            @break
                                        @case('watchlist')
                                            added
                                            @if($movie)
                                                <a href="{{ route('movies.show', $movie->slug ?? $movie->id) }}"
                                                   class="text-[#C5A55A] hover:underline">{{ $movie->title }}</a>
                                            @endif
                                            to their list
                                            @break
                                    @endswitch
                                </div>
                                @if($item['type'] === 'comment' && ! empty($item['meta']['body']))
                                    <p class="text-sm text-gray-400 mt-1 line-clamp-2">{!! $item['meta']['body'] !!}</p>
                                @endif
                                @if($item['type'] === 'rating' && ! empty($item['meta']['review']))
                                    <p class="text-xs italic text-gray-500 mt-1 line-clamp-2">"{{ $item['meta']['review'] }}"</p>
                                @endif
                                <div class="text-xs text-gray-600 mt-1">{{ $when }}</div>
                            </div>

                            @if($movie)
                                <a href="{{ route('movies.show', $movie->slug ?? $movie->id) }}" class="shrink-0">
                                    <img src="{{ $movie->poster_url }}" alt="{{ $movie->title }}"
                                         class="w-12 h-16 rounded object-cover" style="background:#333"
                                         onerror="this.onerror=null">
                                </a>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-layout>
