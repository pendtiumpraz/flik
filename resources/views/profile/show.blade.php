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
                            @if($user->username)
                                <div class="mt-1">
                                    <a href="{{ route('profile.public.show', $user->username) }}"
                                       class="text-xs font-semibold inline-flex items-center gap-1 hover:underline" style="color:#C5A55A">
                                        View Public Profile &rarr;
                                    </a>
                                </div>
                            @endif
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
                                <a href="{{ route('movies.show', $movie->slug ?? $movie->id) }}">
                                    <img src="{{ $movie->poster_url }}" alt="{{ $movie->title }}" class="w-full rounded-lg hover:scale-105 transition-transform" style="aspect-ratio:2/3;object-fit:cover;background:#333"
                                        onerror="this.onerror=null">
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
                                onerror="this.onerror=null">
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
            <div class="mt-8 rounded-xl overflow-hidden" style="background:#1a1a1a;border:1px solid #2a2a2a">
                <div class="p-4 flex items-center justify-between gap-4" style="border-bottom:1px solid #2a2a2a">
                    <h3 class="font-heading font-semibold text-white">🏆 Achievements</h3>
                    <a href="{{ route('profile.achievements') }}" class="text-xs" style="color:#C5A55A">View All →</a>
                </div>
                @if($achievements->count())
                    <div class="p-4 grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3">
                        @foreach($achievements->take(12) as $achievement)
                        <div class="text-center p-3 rounded-lg" style="background:#252525;border:1px solid {{ $achievement->tier_color }}22">
                            <div class="text-3xl mb-2">{{ $achievement->icon }}</div>
                            <div class="text-xs font-semibold text-white">{{ $achievement->name }}</div>
                            <div class="text-xs mt-1" style="color:{{ $achievement->tier_color }}">{{ ucfirst($achievement->tier) }}</div>
                        </div>
                        @endforeach
                    </div>
                @else
                    <div class="p-8 text-center">
                        <p class="text-sm text-gray-500">Belum ada achievement terbuka.</p>
                        <a href="{{ route('profile.achievements') }}" class="inline-block mt-3 text-xs" style="color:#C5A55A">Lihat semua achievement →</a>
                    </div>
                @endif
            </div>

            <!-- Leaderboards Quick Links -->
            <div class="mt-8 rounded-xl overflow-hidden" style="background:#1a1a1a;border:1px solid #2a2a2a">
                <div class="p-4" style="border-bottom:1px solid #2a2a2a">
                    <h3 class="font-heading font-semibold text-white">📈 Leaderboards</h3>
                </div>
                <div class="p-4 grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <a href="{{ route('leaderboards.streaks') }}" class="p-4 rounded-lg hover:scale-[1.02] transition-transform" style="background:#252525;border:1px solid rgba(197,165,90,0.2)">
                        <div class="text-2xl">🔥</div>
                        <div class="text-sm font-semibold text-white mt-1">Streaks</div>
                        <div class="text-[11px] text-gray-500">Top 50 streak harian</div>
                    </a>
                    <a href="{{ route('leaderboards.xp') }}" class="p-4 rounded-lg hover:scale-[1.02] transition-transform" style="background:#252525;border:1px solid rgba(197,165,90,0.2)">
                        <div class="text-2xl">⭐</div>
                        <div class="text-sm font-semibold text-white mt-1">XP</div>
                        <div class="text-[11px] text-gray-500">Top 50 level + XP</div>
                    </a>
                    <a href="{{ route('leaderboards.watches') }}" class="p-4 rounded-lg hover:scale-[1.02] transition-transform" style="background:#252525;border:1px solid rgba(197,165,90,0.2)">
                        <div class="text-2xl">🎬</div>
                        <div class="text-sm font-semibold text-white mt-1">Watches</div>
                        <div class="text-[11px] text-gray-500">Top 50 most-watched</div>
                    </a>
                </div>
            </div>

            <!-- Security & Sessions -->
            <div class="mt-8 rounded-xl overflow-hidden" style="background:#1a1a1a;border:1px solid #2a2a2a">
                <div class="p-4 flex items-center justify-between gap-4" style="border-bottom:1px solid #2a2a2a">
                    <h3 class="font-heading font-semibold text-white">Security</h3>
                </div>
                <div class="p-6 flex flex-col sm:flex-row sm:items-center gap-4 sm:justify-between">
                    <div>
                        <p class="text-sm font-semibold text-white">Active Sessions</p>
                        <p class="text-xs text-gray-500 mt-1">Lihat & cabut device yang saat ini login ke akun Anda.</p>
                    </div>
                    <a href="{{ route('profile.sessions.index') }}"
                       class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-semibold text-black whitespace-nowrap"
                       style="background:#C5A55A">
                        <x-icon name="server" :size="16" />
                        Manage Sessions
                    </a>
                </div>

                {{-- "View My Permissions" — self-service role + permission audit --}}
                <div class="p-6 flex flex-col sm:flex-row sm:items-center gap-4 sm:justify-between" style="border-top:1px solid #2a2a2a">
                    <div>
                        <p class="text-sm font-semibold text-white">My Roles &amp; Permissions</p>
                        <p class="text-xs text-gray-500 mt-1">Lihat semua role + permission yang melekat ke akun Anda.</p>
                    </div>
                    <a href="{{ route('profile.permissions') }}"
                       class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-semibold whitespace-nowrap"
                       style="background:transparent;border:1px solid #C5A55A;color:#C5A55A">
                        <x-icon name="shield" :size="16" />
                        View My Permissions
                    </a>
                </div>
            </div>

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

            {{-- Public-profile editor (peer SOCIAL #1) ────────────────
                 Drives the /u/{username} page. Multipart form because of
                 avatar + cover uploads. Image bytes go through
                 FileUploadValidator (EXIF strip + magic-byte sniff) on
                 the server before they reach disk. --}}
            <div class="mt-8 rounded-xl overflow-hidden" style="background:#1a1a1a;border:1px solid #2a2a2a">
                <div class="p-4 flex items-center justify-between" style="border-bottom:1px solid #2a2a2a">
                    <h3 class="font-heading font-semibold text-white">Public Profile</h3>
                    @if($user->username)
                        <a href="{{ route('profile.public.show', $user->username) }}"
                           class="text-xs font-semibold" style="color:#C5A55A">View Public Profile &rarr;</a>
                    @endif
                </div>
                <form method="POST" action="{{ route('profile.public.update') }}" class="p-6 max-w-2xl" enctype="multipart/form-data">
                    @csrf

                    @error('username')
                        <div class="mb-4 px-3 py-2 rounded text-xs" style="background:rgba(239,68,68,0.1);color:#fca5a5">{{ $message }}</div>
                    @enderror

                    <div class="mb-4">
                        <label class="block text-sm text-gray-400 mb-1">Username (handle)</label>
                        <div class="flex items-center gap-2 p-3 rounded-lg bg-black border text-white text-sm" style="border-color:#2a2a2a">
                            <span class="text-gray-500 select-none">/u/</span>
                            <input type="text" name="username" value="{{ old('username', $user->username) }}"
                                   class="flex-1 bg-transparent focus:outline-none" placeholder="your_handle"
                                   pattern="[A-Za-z0-9_\.]+" maxlength="32">
                        </div>
                        <p class="text-xs text-gray-600 mt-1">Letters, digits, underscore, period. Lower-cased automatically.</p>
                    </div>

                    <div class="mb-4">
                        <label class="block text-sm text-gray-400 mb-1">Bio</label>
                        <textarea name="bio" rows="3" maxlength="500"
                                  class="w-full p-3 rounded-lg bg-black border text-white text-sm focus:outline-none"
                                  style="border-color:#2a2a2a"
                                  placeholder="Tell people what you love watching...">{{ old('bio', $user->bio) }}</textarea>
                        <p class="text-xs text-gray-600 mt-1">Max 500 characters.</p>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm text-gray-400 mb-1">Avatar (JPG / PNG / WebP, max 5 MB)</label>
                            <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp"
                                   class="w-full p-2 rounded-lg bg-black border text-white text-sm" style="border-color:#2a2a2a">
                            @if($user->avatar_url)
                                <img src="{{ $user->avatar_url }}" alt="" class="w-16 h-16 rounded-full mt-2 object-cover">
                            @endif
                        </div>
                        <div>
                            <label class="block text-sm text-gray-400 mb-1">Cover banner (JPG / PNG / WebP, max 8 MB)</label>
                            <input type="file" name="cover" accept="image/jpeg,image/png,image/webp"
                                   class="w-full p-2 rounded-lg bg-black border text-white text-sm" style="border-color:#2a2a2a">
                            @if($user->cover_url)
                                <img src="{{ $user->cover_url }}" alt="" class="w-full h-16 rounded mt-2 object-cover">
                            @endif
                        </div>
                    </div>

                    <div class="space-y-3 mb-5 pt-2" style="border-top:1px solid #2a2a2a">
                        <label class="flex items-start gap-3 pt-3 cursor-pointer">
                            <input type="hidden" name="is_public" value="0">
                            <input type="checkbox" name="is_public" value="1"
                                   {{ old('is_public', $user->is_public ?? true) ? 'checked' : '' }}
                                   class="mt-0.5 w-4 h-4 accent-[#C5A55A]">
                            <div>
                                <div class="text-sm font-semibold text-white">Public profile</div>
                                <div class="text-xs text-gray-500 mt-0.5">Allow anyone to see your /u/handle page and stats. Off = only your name and avatar are visible.</div>
                            </div>
                        </label>

                        <label class="flex items-start gap-3 cursor-pointer">
                            <input type="hidden" name="allow_dm" value="0">
                            <input type="checkbox" name="allow_dm" value="1"
                                   {{ old('allow_dm', $user->allow_dm ?? true) ? 'checked' : '' }}
                                   class="mt-0.5 w-4 h-4 accent-[#C5A55A]">
                            <div>
                                <div class="text-sm font-semibold text-white">Allow direct messages</div>
                                <div class="text-xs text-gray-500 mt-0.5">Let other users send you messages.</div>
                            </div>
                        </label>
                    </div>

                    <button type="submit" class="px-6 py-2 rounded-lg font-semibold text-black text-sm" style="background:#C5A55A">
                        Save Public Profile
                    </button>
                </form>
            </div>
        </div>
    </div>
</x-layout>
