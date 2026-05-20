@php
    /** @var \App\Models\User $user */
    $pageTitle = '@' . ($user->username ?? $user->name) . ' — FLiK';
    $pageDescription = $user->bio ? \Illuminate\Support\Str::limit($user->bio, 160) : ($user->name . ' on FLiK');
    $avatar = $user->avatar_url;
    $cover  = $user->cover_url;
    $initial = strtoupper(substr($user->name ?? '?', 0, 1));
@endphp

<x-layout :title="$pageTitle" :description="$pageDescription" :ogImage="$avatar">
    <div class="min-h-screen bg-black pb-16" x-data="{
            tab: 'activity',
            following: {{ $isFollowing ? 'true' : 'false' }},
            followersCount: {{ (int) $followersCount }},
            optimisticFollow() {
                this.following = true;
                this.followersCount++;
            },
            optimisticUnfollow() {
                this.following = false;
                if (this.followersCount > 0) this.followersCount--;
            }
        }">

        {{-- Cover banner --}}
        <div class="relative h-48 md:h-72 overflow-hidden"
             @if($cover)
                style="background-image:url('{{ $cover }}');background-size:cover;background-position:center"
             @else
                style="background: linear-gradient(135deg, #2a2520 0%, #C5A55A22 50%, #1a1a1a 100%)"
             @endif>
            <div class="absolute inset-0" style="background: linear-gradient(180deg, transparent 0%, #000000cc 100%)"></div>
        </div>

        <div class="container mx-auto px-4 md:px-16 -mt-16 md:-mt-20 relative z-10">

            {{-- Avatar + identity strip --}}
            <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4 mb-6">
                <div class="flex items-end gap-4">
                    @if($avatar)
                        <img src="{{ $avatar }}" alt="{{ $user->name }}"
                             class="w-24 h-24 md:w-32 md:h-32 rounded-full object-cover ring-4 ring-black"
                             style="background:#1a1a1a">
                    @else
                        <div class="w-24 h-24 md:w-32 md:h-32 rounded-full flex items-center justify-center text-4xl md:text-5xl font-bold text-black ring-4 ring-black"
                             style="background: linear-gradient(135deg, #C5A55A, #E8D5A3)">
                            {{ $initial }}
                        </div>
                    @endif
                    <div class="pb-1">
                        <h1 class="font-heading text-2xl md:text-3xl font-bold text-white">{{ $user->name }}</h1>
                        @if($user->username)
                            <p class="text-sm text-gray-400">&commat;{{ $user->username }}</p>
                        @endif
                    </div>
                </div>

                {{-- Follow / Unfollow / Edit button --}}
                <div class="flex items-center gap-2">
                    @if($isOwner)
                        <a href="{{ route('profile.show') }}"
                           class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-semibold"
                           style="background:transparent;border:1px solid #C5A55A;color:#C5A55A">
                            Edit Profile
                        </a>
                    @elseif($isAuthed)
                        {{-- Follow form: Alpine performs an optimistic UI flip and lets the
                             real POST go through the normal redirect so server state stays
                             authoritative. --}}
                        <form method="POST"
                              :action="following
                                  ? '{{ route('profile.public.unfollow', $user) }}'
                                  : '{{ route('profile.public.follow', $user) }}'"
                              @submit="following ? optimisticUnfollow() : optimisticFollow()">
                            @csrf
                            <template x-if="following">
                                <input type="hidden" name="_method" value="DELETE">
                            </template>
                            <button type="submit"
                                    class="inline-flex items-center gap-2 px-5 py-2 rounded-lg text-sm font-semibold transition"
                                    :class="following
                                        ? 'border'
                                        : 'text-black'"
                                    :style="following
                                        ? 'background:transparent;border-color:#2a2a2a;color:#e5e7eb'
                                        : 'background:#C5A55A;color:#000'">
                                <span x-text="following ? 'Following' : '{{ $isFollowedBy ? 'Follow back' : 'Follow' }}'"></span>
                            </button>
                        </form>
                        @if($isFollowedBy)
                            <span class="text-[10px] font-semibold uppercase tracking-wider px-1.5 py-0.5 rounded"
                                  style="background:rgba(107,114,128,0.18);color:#9ca3af">Follows you</span>
                        @endif
                    @else
                        <a href="{{ route('login') }}"
                           class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-semibold text-black"
                           style="background:#C5A55A">
                            Sign in to follow
                        </a>
                    @endif
                </div>
            </div>

            {{-- Bio --}}
            @if($user->bio)
                <p class="text-sm md:text-base text-gray-300 max-w-2xl whitespace-pre-line mb-6">{{ $user->bio }}</p>
            @endif

            {{-- Stats --}}
            <div class="grid grid-cols-4 gap-3 max-w-2xl mb-8">
                <a href="{{ route('profile.public.followers', $user) }}" class="block p-3 rounded-xl text-center hover:bg-[#1f1f1f] transition" style="background:#1a1a1a;border:1px solid #2a2a2a">
                    <div class="text-lg md:text-xl font-bold font-heading text-white" x-text="followersCount">{{ $followersCount }}</div>
                    <div class="text-[11px] uppercase tracking-wider text-gray-500 mt-0.5">Followers</div>
                </a>
                <a href="{{ route('profile.public.following', $user) }}" class="block p-3 rounded-xl text-center hover:bg-[#1f1f1f] transition" style="background:#1a1a1a;border:1px solid #2a2a2a">
                    <div class="text-lg md:text-xl font-bold font-heading text-white">{{ $followingCount }}</div>
                    <div class="text-[11px] uppercase tracking-wider text-gray-500 mt-0.5">Following</div>
                </a>
                <div class="p-3 rounded-xl text-center" style="background:#1a1a1a;border:1px solid #2a2a2a">
                    <div class="text-lg md:text-xl font-bold font-heading text-white">{{ $moviesWatched }}</div>
                    <div class="text-[11px] uppercase tracking-wider text-gray-500 mt-0.5">Watched</div>
                </div>
                <div class="p-3 rounded-xl text-center" style="background:#1a1a1a;border:1px solid #2a2a2a">
                    <div class="text-lg md:text-xl font-bold font-heading text-white">{{ $watchlistMovies->count() }}</div>
                    <div class="text-[11px] uppercase tracking-wider text-gray-500 mt-0.5">In List</div>
                </div>
            </div>

            {{-- Tabs --}}
            <div class="flex items-center gap-1 mb-4 border-b" style="border-color:#2a2a2a">
                <button @click="tab = 'activity'"
                        :class="tab === 'activity' ? 'text-white' : 'text-gray-500 hover:text-gray-300'"
                        :style="tab === 'activity' ? 'border-color:#C5A55A' : 'border-color:transparent'"
                        class="px-4 py-2.5 text-sm font-semibold border-b-2 transition">
                    Activity
                </button>
                @if($user->is_public || $isOwner)
                    <button @click="tab = 'watchlist'"
                            :class="tab === 'watchlist' ? 'text-white' : 'text-gray-500 hover:text-gray-300'"
                            :style="tab === 'watchlist' ? 'border-color:#C5A55A' : 'border-color:transparent'"
                            class="px-4 py-2.5 text-sm font-semibold border-b-2 transition">
                        Watchlist
                    </button>
                @endif
                @if(($publicListsCount ?? 0) > 0)
                    <button @click="tab = 'lists'"
                            :class="tab === 'lists' ? 'text-white' : 'text-gray-500 hover:text-gray-300'"
                            :style="tab === 'lists' ? 'border-color:#C5A55A' : 'border-color:transparent'"
                            class="px-4 py-2.5 text-sm font-semibold border-b-2 transition">
                        Lists
                        <span class="ml-1 text-[10px] px-1.5 py-0.5 rounded" style="background:rgba(197,165,90,0.15);color:#C5A55A">{{ $publicListsCount }}</span>
                    </button>
                @endif
                <button @click="tab = 'achievements'"
                        :class="tab === 'achievements' ? 'text-white' : 'text-gray-500 hover:text-gray-300'"
                        :style="tab === 'achievements' ? 'border-color:#C5A55A' : 'border-color:transparent'"
                        class="px-4 py-2.5 text-sm font-semibold border-b-2 transition">
                    Achievements
                </button>
            </div>

            {{-- Activity tab --}}
            <div x-show="tab === 'activity'" x-cloak>
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    {{-- Recent ratings --}}
                    <div class="rounded-xl overflow-hidden" style="background:#1a1a1a;border:1px solid #2a2a2a">
                        <div class="p-4" style="border-bottom:1px solid #2a2a2a">
                            <h3 class="font-heading font-semibold text-white">Recent Ratings</h3>
                        </div>
                        <div class="p-4 space-y-3">
                            @forelse($recentRatings as $rating)
                                <div class="flex items-center gap-3 p-3 rounded-lg" style="background:#252525">
                                    <a href="{{ route('movies.show', $rating->movie->slug ?? $rating->movie->id) }}" class="shrink-0">
                                        <img src="{{ $rating->movie->poster_url }}" alt="{{ $rating->movie->title }}"
                                             class="w-10 h-14 rounded object-cover" style="background:#333" onerror="this.onerror=null">
                                    </a>
                                    <div class="flex-1 min-w-0">
                                        <a href="{{ route('movies.show', $rating->movie->slug ?? $rating->movie->id) }}"
                                           class="text-sm font-medium text-white truncate hover:text-[#C5A55A]">{{ $rating->movie->title }}</a>
                                        <div class="text-xs text-gray-500 mt-0.5">{{ $rating->created_at->diffForHumans() }}</div>
                                    </div>
                                    <div class="flex items-center gap-1 px-2 py-1 rounded text-xs font-bold" style="background:rgba(197,165,90,0.2);color:#C5A55A">
                                        &starf; {{ $rating->score }}
                                    </div>
                                </div>
                            @empty
                                <p class="text-gray-600 text-sm text-center py-8">No ratings yet</p>
                            @endforelse
                        </div>
                    </div>

                    {{-- Recent comments --}}
                    <div class="rounded-xl overflow-hidden" style="background:#1a1a1a;border:1px solid #2a2a2a">
                        <div class="p-4" style="border-bottom:1px solid #2a2a2a">
                            <h3 class="font-heading font-semibold text-white">Recent Comments</h3>
                        </div>
                        <div class="p-4 space-y-3">
                            @forelse($recentComments as $comment)
                                <div class="p-3 rounded-lg" style="background:#252525">
                                    <a href="{{ route('movies.show', $comment->movie->slug ?? $comment->movie->id) }}"
                                       class="text-xs font-semibold text-[#C5A55A]">{{ $comment->movie->title }}</a>
                                    <div class="text-xs text-gray-500 mt-0.5">{{ $comment->created_at->diffForHumans() }}</div>
                                    <p class="text-sm text-gray-200 mt-2 line-clamp-3">{!! $comment->body !!}</p>
                                </div>
                            @empty
                                <p class="text-gray-600 text-sm text-center py-8">No comments yet</p>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>

            {{-- Watchlist tab --}}
            @if($user->is_public || $isOwner)
                <div x-show="tab === 'watchlist'" x-cloak>
                    <div class="rounded-xl p-4" style="background:#1a1a1a;border:1px solid #2a2a2a">
                        @if($watchlistMovies->count())
                            <div class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-6 gap-3">
                                @foreach($watchlistMovies as $movie)
                                    <a href="{{ route('movies.show', $movie->slug ?? $movie->id) }}" class="group">
                                        <img src="{{ $movie->poster_url }}" alt="{{ $movie->title }}"
                                             class="w-full rounded-lg group-hover:scale-105 transition-transform"
                                             style="aspect-ratio:2/3;object-fit:cover;background:#333"
                                             onerror="this.onerror=null">
                                        <div class="text-xs text-gray-300 mt-1.5 truncate group-hover:text-[#C5A55A]">{{ $movie->title }}</div>
                                    </a>
                                @endforeach
                            </div>
                        @else
                            <p class="text-gray-600 text-sm text-center py-12">Watchlist is empty</p>
                        @endif
                    </div>
                </div>
            @endif

            {{-- Lists tab (peer LISTS) — curated user-lists owned by this user --}}
            @if(($publicListsCount ?? 0) > 0)
                <div x-show="tab === 'lists'" x-cloak>
                    <div class="flex items-center justify-between mb-3">
                        <p class="text-xs text-gray-500">
                            {{ $publicLists->count() }} dari {{ $publicListsCount }} list ditampilkan
                        </p>
                        @if($isOwner)
                            <a href="{{ route('user-lists.mine') }}"
                               class="text-xs text-[#C5A55A] hover:underline">Kelola semua list &rsaquo;</a>
                        @elseif($user->username)
                            <a href="{{ route('user-lists.index', ['user' => $user->username]) }}"
                               class="text-xs text-[#C5A55A] hover:underline">Lihat semua list &rsaquo;</a>
                        @endif
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        @foreach($publicLists as $list)
                            <x-lists.card :list="$list" :showOwner="false" />
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Achievements tab --}}
            <div x-show="tab === 'achievements'" x-cloak>
                <div class="rounded-xl p-4" style="background:#1a1a1a;border:1px solid #2a2a2a">
                    @if($achievements->count())
                        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3">
                            @foreach($achievements as $achievement)
                                <div class="text-center p-3 rounded-lg"
                                     style="background:#252525;border:1px solid {{ $achievement->tier_color ?? '#2a2a2a' }}22">
                                    <div class="text-3xl mb-2">{{ $achievement->icon ?? '🏆' }}</div>
                                    <div class="text-xs font-semibold text-white">{{ $achievement->name }}</div>
                                    @if(! empty($achievement->tier))
                                        <div class="text-xs mt-1" style="color:{{ $achievement->tier_color ?? '#C5A55A' }}">{{ ucfirst($achievement->tier) }}</div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-gray-600 text-sm text-center py-12">No achievements unlocked</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-layout>
