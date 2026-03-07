<x-layout>
    <div class="min-h-screen bg-black pt-20 pb-16">
        <div class="container mx-auto px-4 md:px-16">

            <!-- Profile Header -->
            <div class="relative rounded-2xl overflow-hidden mb-8" style="background: linear-gradient(135deg, #1a1a1a 0%, #2a2520 50%, #1a1a1a 100%); border: 1px solid #2a2a2a;">
                <div class="p-6 md:p-10">
                    <div class="flex flex-col md:flex-row items-center gap-6">
                        <!-- Avatar -->
                        <div class="w-24 h-24 rounded-full flex items-center justify-center text-3xl font-bold text-black" style="background: linear-gradient(135deg, #C5A55A, #E8D5A3);">
                            {{ strtoupper(substr($user->name, 0, 1)) }}
                        </div>
                        <div class="text-center md:text-left">
                            <h1 class="font-heading text-2xl md:text-3xl font-bold text-white">{{ $user->name }}</h1>
                            <p class="text-gray-400 mt-1">{{ $user->email }}</p>
                            <div class="flex items-center gap-4 mt-3 flex-wrap justify-center md:justify-start">
                                <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-semibold" style="background:rgba(197,165,90,0.2);color:#C5A55A">
                                    ⭐ Level {{ $stats['level'] }}
                                </span>
                                <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-semibold" style="background:rgba(234,179,8,0.2);color:#eab308">
                                    🪙 {{ number_format($stats['coins']) }} Coins
                                </span>
                                <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-semibold" style="background:rgba(239,68,68,0.2);color:#ef4444">
                                    🔥 {{ $stats['streak'] }} Day Streak
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- XP Bar -->
                    <div class="mt-6 max-w-md">
                        <div class="flex justify-between text-xs text-gray-500 mb-1">
                            <span>XP: {{ $stats['xp'] }}/{{ $stats['xp_next'] }}</span>
                            <span>Level {{ $stats['level'] }} → {{ $stats['level'] + 1 }}</span>
                        </div>
                        <div class="w-full h-2 rounded-full bg-gray-800 overflow-hidden">
                            <div class="h-full rounded-full transition-all" style="width: {{ $stats['xp_progress'] }}%; background: linear-gradient(90deg, #C5A55A, #E8D5A3);"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
                <div class="p-5 rounded-xl text-center" style="background:#1a1a1a;border:1px solid #2a2a2a">
                    <div class="text-2xl font-bold font-heading text-white">{{ $stats['watchlist_count'] }}</div>
                    <div class="text-xs text-gray-500 mt-1">Watchlist</div>
                </div>
                <div class="p-5 rounded-xl text-center" style="background:#1a1a1a;border:1px solid #2a2a2a">
                    <div class="text-2xl font-bold font-heading text-white">{{ $stats['ratings_count'] }}</div>
                    <div class="text-xs text-gray-500 mt-1">Ratings</div>
                </div>
                <div class="p-5 rounded-xl text-center" style="background:#1a1a1a;border:1px solid #2a2a2a">
                    <div class="text-2xl font-bold font-heading text-white">{{ $stats['comments_count'] }}</div>
                    <div class="text-xs text-gray-500 mt-1">Comments</div>
                </div>
                <div class="p-5 rounded-xl text-center" style="background:#1a1a1a;border:1px solid #2a2a2a">
                    <div class="text-2xl font-bold font-heading text-white">{{ $stats['achievements_count'] }}</div>
                    <div class="text-xs text-gray-500 mt-1">Achievements</div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- Recent Watchlist -->
                <div class="rounded-xl overflow-hidden" style="background:#1a1a1a;border:1px solid #2a2a2a">
                    <div class="p-4 flex justify-between items-center" style="border-bottom:1px solid #2a2a2a">
                        <h3 class="font-heading font-semibold text-white">My Watchlist</h3>
                        <a href="{{ route('watchlist.index') }}" class="text-xs" style="color:#C5A55A">View All →</a>
                    </div>
                    <div class="p-4">
                        @if($recentWatchlist->count())
                            <div class="grid grid-cols-3 gap-3">
                                @foreach($recentWatchlist as $movie)
                                <a href="{{ route('movies.show', $movie->id) }}">
                                    <img src="{{ $movie->poster_url }}" alt="{{ $movie->title }}" class="w-full rounded-lg hover:scale-105 transition-transform" style="aspect-ratio:2/3;object-fit:cover;background:#333"
                                        onerror="this.style.background='#333';this.src='https://via.placeholder.com/150x225/333/C5A55A?text='">
                                </a>
                                @endforeach
                            </div>
                        @else
                            <p class="text-gray-600 text-sm text-center py-8">Belum ada film di watchlist</p>
                        @endif
                    </div>
                </div>

                <!-- Recent Ratings -->
                <div class="rounded-xl overflow-hidden" style="background:#1a1a1a;border:1px solid #2a2a2a">
                    <div class="p-4" style="border-bottom:1px solid #2a2a2a">
                        <h3 class="font-heading font-semibold text-white">Recent Ratings</h3>
                    </div>
                    <div class="p-4 space-y-3">
                        @forelse($recentRatings as $rating)
                        <div class="flex items-center gap-3 p-3 rounded-lg" style="background:#252525">
                            <img src="{{ $rating->movie->poster_url }}" alt="{{ $rating->movie->title }}" class="w-10 h-14 rounded object-cover" style="background:#333"
                                onerror="this.style.background='#333';this.src='https://via.placeholder.com/40x56/333/C5A55A?text='">
                            <div class="flex-1 min-w-0">
                                <div class="text-sm font-medium text-white truncate">{{ $rating->movie->title }}</div>
                                <div class="text-xs text-gray-500 mt-0.5">{{ $rating->created_at->diffForHumans() }}</div>
                            </div>
                            <div class="flex items-center gap-1 px-2 py-1 rounded text-xs font-bold" style="background:rgba(197,165,90,0.2);color:#C5A55A">
                                ★ {{ $rating->score }}
                            </div>
                        </div>
                        @empty
                        <p class="text-gray-600 text-sm text-center py-8">Belum ada rating</p>
                        @endforelse
                    </div>
                </div>
            </div>

            <!-- Achievements Section -->
            @if($achievements->count())
            <div class="mt-8 rounded-xl overflow-hidden" style="background:#1a1a1a;border:1px solid #2a2a2a">
                <div class="p-4" style="border-bottom:1px solid #2a2a2a">
                    <h3 class="font-heading font-semibold text-white">🏆 Achievements</h3>
                </div>
                <div class="p-4 grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3">
                    @foreach($achievements as $achievement)
                    <div class="text-center p-3 rounded-lg" style="background:#252525;border:1px solid {{ $achievement->tier_color }}22">
                        <div class="text-3xl mb-2">{{ $achievement->icon }}</div>
                        <div class="text-xs font-semibold text-white">{{ $achievement->name }}</div>
                        <div class="text-xs mt-1" style="color:{{ $achievement->tier_color }}">{{ ucfirst($achievement->tier) }}</div>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif

            <!-- Edit Profile -->
            <div class="mt-8 rounded-xl overflow-hidden" style="background:#1a1a1a;border:1px solid #2a2a2a">
                <div class="p-4" style="border-bottom:1px solid #2a2a2a">
                    <h3 class="font-heading font-semibold text-white">Edit Profile</h3>
                </div>
                <form method="POST" action="{{ route('profile.update') }}" class="p-6 max-w-md">
                    @csrf @method('PUT')
                    <div class="mb-4">
                        <label class="block text-sm text-gray-400 mb-1">Name</label>
                        <input type="text" name="name" value="{{ $user->name }}" class="w-full p-3 rounded-lg bg-black border text-white text-sm focus:outline-none" style="border-color:#2a2a2a" required>
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm text-gray-400 mb-1">Email</label>
                        <input type="email" name="email" value="{{ $user->email }}" class="w-full p-3 rounded-lg bg-black border text-white text-sm focus:outline-none" style="border-color:#2a2a2a" required>
                    </div>
                    <button type="submit" class="px-6 py-2 rounded-lg font-semibold text-black text-sm" style="background:#C5A55A">Save Changes</button>
                </form>
            </div>
        </div>
    </div>
</x-layout>
