@php
    $user = auth()->user();
@endphp

<div class="body-font fixed top-0 z-50 w-full bg-black/95 backdrop-blur-sm text-white border-b border-gray-800/50"
     x-data="{ mobileOpen: false }">
    <div class="flex items-center justify-between px-4 py-3 lg:px-16 lg:py-4">
        <!-- Logo -->
        <a href="/" class="flex items-center">
            <img src="{{ asset('img/flik-logo.png') }}" alt="FLiK" class="h-7 lg:h-9">
        </a>

        @auth
            <!-- Desktop Nav Links -->
            <ul class="ml-10 hidden flex-row items-center gap-6 text-sm lg:flex">
                <li><a href="{{ route('velflix.index') }}" class="font-medium text-white hover:text-[#C5A55A] transition-colors">{{ __('Home') }}</a></li>
                <li><a href="{{ route('velflix.index') }}" class="text-gray-300 hover:text-[#C5A55A] transition-colors">{{ __('Films') }}</a></li>
                <li><a href="{{ route('blog.index') }}" class="text-gray-300 hover:text-[#C5A55A] transition-colors">{{ __('Blog') }}</a></li>

                <!-- Discover dropdown (AI discovery features) -->
                <li x-data="{ openDiscover: false }" class="relative">
                    <button @click="openDiscover = !openDiscover" @click.away="openDiscover = false"
                            class="flex items-center gap-1 text-gray-300 hover:text-[#C5A55A] transition-colors">
                        <span>{{ __('Discover') }}</span>
                        <span :class="openDiscover ? '-rotate-180' : ''" class="transform transition-transform duration-300">
                            <x-icon name="chevron-down" :size="14" />
                        </span>
                    </button>
                    <div x-cloak x-show="openDiscover"
                         x-transition:enter="transition ease-out duration-200"
                         x-transition:enter-start="opacity-0 scale-95"
                         x-transition:enter-end="opacity-100 scale-100"
                         x-transition:leave="transition ease-in duration-150"
                         x-transition:leave-start="opacity-100 scale-100"
                         x-transition:leave-end="opacity-0 scale-95"
                         class="absolute left-0 mt-3 w-64 rounded-xl shadow-2xl overflow-hidden z-50"
                         style="background: linear-gradient(180deg, #1a1a1a 0%, #141414 100%); border: 1px solid rgba(197,165,90,0.25)">
                        <div class="px-4 py-2 text-[10px] font-semibold uppercase tracking-wider text-[#C5A55A]/80 border-b" style="border-color: rgba(197,165,90,0.15)">Discovery</div>
                        <div class="py-1">
                            <a href="{{ route('discovery.mood.form') }}" class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-200 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] transition-colors group">
                                <x-icon name="sparkles" :size="16" class="text-[#C5A55A]/80 group-hover:text-[#C5A55A]" />
                                <span>Discover by Mood</span>
                            </a>
                            <a href="{{ route('family-night.form') }}" class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-200 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] transition-colors group">
                                <x-icon name="heart" :size="16" class="text-[#C5A55A]/80 group-hover:text-[#C5A55A]" />
                                <span>Family Night</span>
                            </a>
                            <a href="{{ route('year-in-review.show') }}" class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-200 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] transition-colors group">
                                <x-icon name="calendar" :size="16" class="text-[#C5A55A]/80 group-hover:text-[#C5A55A]" />
                                <span>Year in Review</span>
                            </a>
                            <a href="{{ route('compare.form') }}" class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-200 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] transition-colors group">
                                <x-icon name="film" :size="16" class="text-[#C5A55A]/80 group-hover:text-[#C5A55A]" />
                                <span>Bandingkan Film</span>
                            </a>
                        </div>
                        <div class="px-4 py-2 text-[10px] font-semibold uppercase tracking-wider text-[#C5A55A]/80 border-t border-b" style="border-color: rgba(197,165,90,0.15)">Smart Search</div>
                        <div class="py-1">
                            <a href="{{ route('search.image.form') }}" class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-200 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] transition-colors group">
                                <x-icon name="search" :size="16" class="text-[#C5A55A]/80 group-hover:text-[#C5A55A]" />
                                <span>Cari dengan Foto</span>
                            </a>
                            <a href="{{ route('search.vibe.form') }}" class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-200 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] transition-colors group">
                                <x-icon name="eye" :size="16" class="text-[#C5A55A]/80 group-hover:text-[#C5A55A]" />
                                <span>Cari dengan Vibe</span>
                            </a>
                            <a href="{{ route('search.person.form') }}" class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-200 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] transition-colors group">
                                <x-icon name="user" :size="16" class="text-[#C5A55A]/80 group-hover:text-[#C5A55A]" />
                                <span>Cari Aktor/Sutradara</span>
                            </a>
                        </div>
                    </div>
                </li>

                <!-- My List dropdown (regular + Smart) -->
                <li x-data="{ openList: false }" class="relative">
                    <button @click="openList = !openList" @click.away="openList = false"
                            class="flex items-center gap-1 text-gray-300 hover:text-[#C5A55A] transition-colors">
                        <span>{{ __('My List') }}</span>
                        <span :class="openList ? '-rotate-180' : ''" class="transform transition-transform duration-300">
                            <x-icon name="chevron-down" :size="14" />
                        </span>
                    </button>
                    <div x-cloak x-show="openList"
                         x-transition:enter="transition ease-out duration-200"
                         x-transition:enter-start="opacity-0 scale-95"
                         x-transition:enter-end="opacity-100 scale-100"
                         x-transition:leave="transition ease-in duration-150"
                         x-transition:leave-start="opacity-100 scale-100"
                         x-transition:leave-end="opacity-0 scale-95"
                         class="absolute left-0 mt-3 w-56 rounded-xl shadow-2xl overflow-hidden z-50"
                         style="background: linear-gradient(180deg, #1a1a1a 0%, #141414 100%); border: 1px solid rgba(197,165,90,0.25)">
                        <div class="py-1">
                            <a href="{{ route('watchlist.index') }}" class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-200 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] transition-colors group">
                                <x-icon name="bookmark" :size="16" class="text-[#C5A55A]/80 group-hover:text-[#C5A55A]" />
                                <span>My List</span>
                            </a>
                            <a href="{{ route('watchlist.smart') }}" class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-200 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] transition-colors group">
                                <x-icon name="lightning" :size="16" class="text-[#C5A55A]/80 group-hover:text-[#C5A55A]" />
                                <span>Smart Watchlist</span>
                            </a>
                            {{-- Curated user-lists (peer LISTS) — distinct concept from
                                 the flat watchlist above: shareable playlists with title,
                                 description, follower graph, public/unlisted/private. --}}
                            <a href="{{ route('user-lists.index') }}" class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-200 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] transition-colors group">
                                <x-icon name="film" :size="16" class="text-[#C5A55A]/80 group-hover:text-[#C5A55A]" />
                                <span>Discover Lists</span>
                            </a>
                            <a href="{{ route('user-lists.mine') }}" class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-200 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] transition-colors group">
                                <x-icon name="user" :size="16" class="text-[#C5A55A]/80 group-hover:text-[#C5A55A]" />
                                <span>My Lists</span>
                            </a>
                        </div>
                    </div>
                </li>

                <li><a href="{{ route('rewards.index') }}" class="text-gray-300 hover:text-[#C5A55A] transition-colors">{{ __('Rewards') }}</a></li>
                <li><a href="{{ route('leaderboards.streaks') }}" class="text-gray-300 hover:text-[#C5A55A] transition-colors">Leaderboards</a></li>
            </ul>
        @endauth

        <!-- Desktop Right Section -->
        <nav class="hidden items-center gap-4 lg:flex">
            @auth
                <x-search.smart-bar />
                <x-lang-switcher />
                <a href="{{ route('notifications.index') }}" class="relative text-gray-300 hover:text-[#C5A55A] transition-colors" title="{{ __('Notifications') }}">
                    <x-icon name="bell" :size="20" />
                </a>

                <!-- User Profile -->
                <div x-data="{ open: false }" class="relative inline-block">
                    <button @click="open = !open" @click.away="open = false" class="flex items-center gap-2 group">
                        <div class="h-8 w-8 rounded-full flex items-center justify-center text-sm font-semibold text-black ring-1 ring-[#C5A55A]/40" style="background: linear-gradient(135deg, #C5A55A, #E8D5A3)">
                            {{ strtoupper(substr($user->name, 0, 1)) }}
                        </div>
                        <span :class="open ? '-rotate-180' : ''" class="transform transition-transform duration-300 text-gray-400 group-hover:text-[#C5A55A]">
                            <x-icon name="chevron-down" :size="14" />
                        </span>
                    </button>

                    <div x-cloak x-show="open"
                        x-transition:enter="transition ease-out duration-200"
                        x-transition:enter-start="opacity-0 scale-95"
                        x-transition:enter-end="opacity-100 scale-100"
                        x-transition:leave="transition ease-in duration-150"
                        x-transition:leave-start="opacity-100 scale-100"
                        x-transition:leave-end="opacity-0 scale-95"
                        class="absolute right-0 mt-3 w-60 rounded-xl shadow-2xl overflow-hidden"
                        style="background: linear-gradient(180deg, #1a1a1a 0%, #141414 100%); border: 1px solid rgba(197,165,90,0.25)">

                        <!-- User Info -->
                        <div class="px-4 py-3.5 border-b" style="border-color: rgba(197,165,90,0.15)">
                            <p class="text-sm font-semibold text-white truncate">{{ $user->name }}</p>
                            <p class="text-xs text-gray-400 truncate">{{ $user->email }}</p>
                            @if($user->isStaff())
                                <span class="inline-flex mt-1.5 px-2 py-0.5 text-[10px] font-semibold rounded uppercase tracking-wider" style="background: rgba(197,165,90,0.15); color: #C5A55A">
                                    {{ $user->role_label }}
                                </span>
                            @endif
                        </div>

                        <!-- Menu Items -->
                        <div class="py-1">
                            @if($user->isStaff())
                                <a href="{{ $user->adminDashboardUrl() }}" class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-200 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] transition-colors group">
                                    <x-icon name="cog" :size="16" class="text-[#C5A55A]/80 group-hover:text-[#C5A55A]" />
                                    <span>{{ __('Admin Dashboard') }}</span>
                                </a>
                            @endif
                            {{-- "My Profile" links to the public /u/{username} page when
                                 the user has set a handle, otherwise falls back to the
                                 private /profile editor so the menu item never 404s. --}}
                            <a href="{{ $user->publicProfileUrl() ?? route('profile.show') }}"
                               class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-200 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] transition-colors group">
                                <x-icon name="user" :size="16" class="text-[#C5A55A]/80 group-hover:text-[#C5A55A]" />
                                <span>{{ __('My Profile') }}</span>
                            </a>
                            <a href="{{ route('feed.index') }}" class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-200 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] transition-colors group">
                                <x-icon name="sparkles" :size="16" class="text-[#C5A55A]/80 group-hover:text-[#C5A55A]" />
                                <span>Feed</span>
                            </a>
                            <a href="{{ route('profile.show') }}" class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-200 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] transition-colors group">
                                <x-icon name="cog" :size="16" class="text-[#C5A55A]/80 group-hover:text-[#C5A55A]" />
                                <span>Account Settings</span>
                            </a>
                            <a href="{{ route('profile.sessions.index') }}" class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-200 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] transition-colors group">
                                <x-icon name="server" :size="16" class="text-[#C5A55A]/80 group-hover:text-[#C5A55A]" />
                                <span>Manage Sessions</span>
                            </a>
                            <a href="{{ route('watchlist.index') }}" class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-200 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] transition-colors group">
                                <x-icon name="bookmark" :size="16" class="text-[#C5A55A]/80 group-hover:text-[#C5A55A]" />
                                <span>My List</span>
                            </a>
                            <a href="{{ route('rewards.index') }}" class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-200 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] transition-colors group">
                                <x-icon name="trophy" :size="16" class="text-[#C5A55A]/80 group-hover:text-[#C5A55A]" />
                                <span>Rewards</span>
                            </a>
                            {{-- Refer-a-friend + Redeem Gift entry points.
                                 Guarded with Route::has() so the menu degrades
                                 cleanly if the payment stack is partially
                                 deployed (e.g. routes ungrouped during
                                 maintenance). --}}
                            @if(Route::has('referral.dashboard'))
                                <a href="{{ route('referral.dashboard') }}" class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-200 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] transition-colors group">
                                    <x-icon name="user" :size="16" class="text-[#C5A55A]/80 group-hover:text-[#C5A55A]" />
                                    <span>Referrals</span>
                                </a>
                            @endif
                            @if(Route::has('gift.redeem-form'))
                                <a href="{{ route('gift.redeem-form') }}" class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-200 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] transition-colors group">
                                    <x-icon name="gift" :size="16" class="text-[#C5A55A]/80 group-hover:text-[#C5A55A]" />
                                    <span>Redeem Gift</span>
                                </a>
                            @endif
                            <a href="{{ route('plans.index') }}" class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-200 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] transition-colors group">
                                <x-icon name="gem" :size="16" class="text-[#C5A55A]/80 group-hover:text-[#C5A55A]" />
                                <span>Upgrade Plan</span>
                            </a>
                            <a href="{{ route('privacy.index') }}" class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-200 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] transition-colors group">
                                <x-icon name="info" :size="16" class="text-[#C5A55A]/80 group-hover:text-[#C5A55A]" />
                                <span>Privacy &amp; Data</span>
                            </a>
                        </div>

                        <!-- Logout -->
                        <form action="/logout" method="post" class="border-t" style="border-color: rgba(197,165,90,0.15)">
                            @csrf
                            <button type="submit" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-gray-400 hover:text-red-400 hover:bg-red-500/5 transition-colors group">
                                <x-icon name="logout" :size="16" />
                                <span>{{ __('Log Out') }}</span>
                            </button>
                        </form>
                    </div>
                </div>
            @else
                <x-lang-switcher />
                <a href="/login" class="text-sm text-gray-300 hover:text-[#C5A55A] transition-colors">{{ __('Log In') }}</a>
                <a href="/register" class="text-sm font-semibold px-4 py-2 rounded text-black hover:opacity-90 transition-opacity" style="background: linear-gradient(135deg, #C5A55A, #E8D5A3)">{{ __('Sign Up') }}</a>
            @endauth
        </nav>

        <!-- Mobile Hamburger Button -->
        <button @click="mobileOpen = !mobileOpen" class="lg:hidden p-2 rounded-md text-gray-300 hover:text-[#C5A55A] transition-colors">
            <x-icon name="menu" x-show="!mobileOpen" :size="22" />
            <x-icon name="x" x-show="mobileOpen" :size="22" />
        </button>
    </div>

    <!-- Mobile Menu -->
    <div x-cloak x-show="mobileOpen" x-collapse class="lg:hidden border-t border-gray-800/60 bg-black/98">
        <div class="px-4 py-4 space-y-0.5">
            @auth
                <!-- User card -->
                <div class="flex items-center gap-3 px-3 py-3 mb-2 rounded-lg" style="background: rgba(197,165,90,0.06); border: 1px solid rgba(197,165,90,0.15)">
                    <div class="h-10 w-10 rounded-full flex items-center justify-center text-sm font-semibold text-black" style="background: linear-gradient(135deg, #C5A55A, #E8D5A3)">
                        {{ strtoupper(substr($user->name, 0, 1)) }}
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-semibold text-white truncate">{{ $user->name }}</p>
                        @if($user->isStaff())
                            <span class="inline-flex px-1.5 py-0.5 mt-0.5 text-[10px] font-semibold rounded uppercase tracking-wider" style="background: rgba(197,165,90,0.15); color: #C5A55A">{{ $user->role_label }}</span>
                        @else
                            <p class="text-xs text-gray-500 truncate">{{ $user->email }}</p>
                        @endif
                    </div>
                </div>

                <a href="{{ route('velflix.index') }}" class="flex items-center gap-3 py-2.5 px-3 text-sm text-gray-200 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] rounded-lg transition-colors">
                    <x-icon name="home" :size="18" class="text-[#C5A55A]/80" /> Home
                </a>
                <a href="{{ route('velflix.index') }}" class="flex items-center gap-3 py-2.5 px-3 text-sm text-gray-200 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] rounded-lg transition-colors">
                    <x-icon name="film" :size="18" class="text-[#C5A55A]/80" /> Films
                </a>
                <a href="{{ route('blog.index') }}" class="flex items-center gap-3 py-2.5 px-3 text-sm text-gray-200 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] rounded-lg transition-colors">
                    <x-icon name="sparkles" :size="18" class="text-[#C5A55A]/80" /> Blog
                </a>
                <a href="{{ route('watchlist.index') }}" class="flex items-center gap-3 py-2.5 px-3 text-sm text-gray-200 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] rounded-lg transition-colors">
                    <x-icon name="bookmark" :size="18" class="text-[#C5A55A]/80" /> My List
                </a>
                <a href="{{ route('watchlist.smart') }}" class="flex items-center gap-3 py-2.5 px-3 pl-9 text-sm text-gray-300 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] rounded-lg transition-colors">
                    <x-icon name="lightning" :size="16" class="text-[#C5A55A]/80" /> Smart Watchlist
                </a>
                <a href="{{ route('user-lists.index') }}" class="flex items-center gap-3 py-2.5 px-3 text-sm text-gray-200 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] rounded-lg transition-colors">
                    <x-icon name="film" :size="18" class="text-[#C5A55A]/80" /> Discover Lists
                </a>
                <a href="{{ route('user-lists.mine') }}" class="flex items-center gap-3 py-2.5 px-3 pl-9 text-sm text-gray-300 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] rounded-lg transition-colors">
                    <x-icon name="user" :size="16" class="text-[#C5A55A]/80" /> My Lists
                </a>

                <!-- Discover collapsible (mobile) -->
                <div x-data="{ openDiscoverM: false }" class="pt-1">
                    <button @click="openDiscoverM = !openDiscoverM"
                            class="w-full flex items-center justify-between gap-3 py-2.5 px-3 text-sm text-gray-200 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] rounded-lg transition-colors">
                        <span class="flex items-center gap-3">
                            <x-icon name="sparkles" :size="18" class="text-[#C5A55A]/80" /> Discover
                        </span>
                        <span :class="openDiscoverM ? '-rotate-180' : ''" class="transform transition-transform duration-300">
                            <x-icon name="chevron-down" :size="14" />
                        </span>
                    </button>
                    <div x-cloak x-show="openDiscoverM" x-collapse class="ml-3 pl-3 border-l border-[#C5A55A]/15 space-y-0.5 mt-1">
                        <a href="{{ route('discovery.mood.form') }}" class="flex items-center gap-3 py-2 px-3 text-sm text-gray-300 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] rounded-lg transition-colors">
                            <x-icon name="sparkles" :size="16" class="text-[#C5A55A]/80" /> Discover by Mood
                        </a>
                        <a href="{{ route('family-night.form') }}" class="flex items-center gap-3 py-2 px-3 text-sm text-gray-300 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] rounded-lg transition-colors">
                            <x-icon name="heart" :size="16" class="text-[#C5A55A]/80" /> Family Night
                        </a>
                        <a href="{{ route('year-in-review.show') }}" class="flex items-center gap-3 py-2 px-3 text-sm text-gray-300 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] rounded-lg transition-colors">
                            <x-icon name="calendar" :size="16" class="text-[#C5A55A]/80" /> Year in Review
                        </a>
                        <a href="{{ route('compare.form') }}" class="flex items-center gap-3 py-2 px-3 text-sm text-gray-300 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] rounded-lg transition-colors">
                            <x-icon name="film" :size="16" class="text-[#C5A55A]/80" /> Bandingkan Film
                        </a>
                        <a href="{{ route('search.image.form') }}" class="flex items-center gap-3 py-2 px-3 text-sm text-gray-300 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] rounded-lg transition-colors">
                            <x-icon name="search" :size="16" class="text-[#C5A55A]/80" /> Cari dengan Foto
                        </a>
                        <a href="{{ route('search.vibe.form') }}" class="flex items-center gap-3 py-2 px-3 text-sm text-gray-300 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] rounded-lg transition-colors">
                            <x-icon name="eye" :size="16" class="text-[#C5A55A]/80" /> Cari dengan Vibe
                        </a>
                        <a href="{{ route('search.person.form') }}" class="flex items-center gap-3 py-2 px-3 text-sm text-gray-300 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] rounded-lg transition-colors">
                            <x-icon name="user" :size="16" class="text-[#C5A55A]/80" /> Cari Aktor/Sutradara
                        </a>
                    </div>
                </div>

                <a href="{{ route('rewards.index') }}" class="flex items-center gap-3 py-2.5 px-3 text-sm text-gray-200 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] rounded-lg transition-colors">
                    <x-icon name="trophy" :size="18" class="text-[#C5A55A]/80" /> Rewards
                </a>
                <a href="{{ route('leaderboards.streaks') }}" class="flex items-center gap-3 py-2.5 px-3 text-sm text-gray-200 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] rounded-lg transition-colors">
                    <x-icon name="trophy" :size="18" class="text-[#C5A55A]/80" /> Leaderboards
                </a>
                <a href="{{ route('notifications.index') }}" class="flex items-center gap-3 py-2.5 px-3 text-sm text-gray-200 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] rounded-lg transition-colors">
                    <x-icon name="bell" :size="18" class="text-[#C5A55A]/80" /> Notifications
                </a>
                <a href="{{ route('plans.index') }}" class="flex items-center gap-3 py-2.5 px-3 text-sm text-gray-200 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] rounded-lg transition-colors">
                    <x-icon name="gem" :size="18" class="text-[#C5A55A]/80" /> Plans
                </a>
                {{-- Refer-a-friend + Redeem Gift (mobile). Route::has() guards
                     mirror the desktop dropdown above. --}}
                @if(Route::has('referral.dashboard'))
                    <a href="{{ route('referral.dashboard') }}" class="flex items-center gap-3 py-2.5 px-3 text-sm text-gray-200 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] rounded-lg transition-colors">
                        <x-icon name="user" :size="18" class="text-[#C5A55A]/80" /> Referrals
                    </a>
                @endif
                @if(Route::has('gift.redeem-form'))
                    <a href="{{ route('gift.redeem-form') }}" class="flex items-center gap-3 py-2.5 px-3 text-sm text-gray-200 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] rounded-lg transition-colors">
                        <x-icon name="gift" :size="18" class="text-[#C5A55A]/80" /> Redeem Gift
                    </a>
                @endif

                <div class="pt-2 mt-2 border-t border-gray-800/60 space-y-0.5">
                    <a href="{{ $user->publicProfileUrl() ?? route('profile.show') }}"
                       class="flex items-center gap-3 py-2.5 px-3 text-sm text-gray-200 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] rounded-lg transition-colors">
                        <x-icon name="user" :size="18" class="text-[#C5A55A]/80" /> My Profile
                    </a>
                    <a href="{{ route('feed.index') }}" class="flex items-center gap-3 py-2.5 px-3 text-sm text-gray-200 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] rounded-lg transition-colors">
                        <x-icon name="sparkles" :size="18" class="text-[#C5A55A]/80" /> Feed
                    </a>
                    <a href="{{ route('profile.show') }}" class="flex items-center gap-3 py-2.5 px-3 text-sm text-gray-200 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] rounded-lg transition-colors">
                        <x-icon name="cog" :size="18" class="text-[#C5A55A]/80" /> Account Settings
                    </a>
                    <a href="{{ route('profile.sessions.index') }}" class="flex items-center gap-3 py-2.5 px-3 text-sm text-gray-200 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] rounded-lg transition-colors">
                        <x-icon name="server" :size="18" class="text-[#C5A55A]/80" /> Manage Sessions
                    </a>
                    <a href="{{ route('privacy.index') }}" class="flex items-center gap-3 py-2.5 px-3 text-sm text-gray-200 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] rounded-lg transition-colors">
                        <x-icon name="info" :size="18" class="text-[#C5A55A]/80" /> Privacy &amp; Data
                    </a>
                    @if($user->isStaff())
                        <a href="{{ $user->adminDashboardUrl() }}" class="flex items-center gap-3 py-2.5 px-3 text-sm text-gray-200 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] rounded-lg transition-colors">
                            <x-icon name="cog" :size="18" class="text-[#C5A55A]/80" /> Admin Dashboard
                        </a>
                    @endif
                    <form action="/logout" method="post">
                        @csrf
                        <button type="submit" class="w-full flex items-center gap-3 py-2.5 px-3 text-sm text-gray-400 hover:text-red-400 hover:bg-red-500/5 rounded-lg transition-colors">
                            <x-icon name="logout" :size="18" /> Log Out
                        </button>
                    </form>
                </div>
            @else
                <a href="/login" class="block py-2.5 px-3 text-sm text-gray-200 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A] rounded-lg transition-colors">Log In</a>
                <a href="/register" class="block py-2.5 px-3 text-sm font-semibold text-black mt-1 rounded-lg text-center" style="background: linear-gradient(135deg, #C5A55A, #E8D5A3)">Sign Up</a>
            @endauth
        </div>
    </div>
</div>
