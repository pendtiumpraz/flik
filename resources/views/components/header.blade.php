<div class="body-font fixed top-0 z-50 w-full bg-black/95 backdrop-blur-sm text-white border-b border-gray-800/50"
     x-data="{ mobileOpen: false }">
    <div class="flex items-center justify-between px-4 py-3 lg:px-16 lg:py-4">
        <!-- Logo -->
        <a href="/" class="flex items-center">
            <img src="{{ asset('img/flik-logo.png') }}" alt="FLiK" class="h-7 lg:h-9">
        </a>

        @auth
            <!-- Desktop Nav Links -->
            <ul class="ml-10 hidden flex-row items-center gap-5 text-sm lg:flex">
                <li><a href="{{ route('velflix.index') }}" class="font-semibold text-white hover:text-yellow-400 transition-colors">Home</a></li>
                <li><a href="{{ route('velflix.index') }}" class="text-gray-300 hover:text-white transition-colors">Films</a></li>
                <li><a href="{{ route('watchlist.index') }}" class="text-gray-300 hover:text-white transition-colors">My List</a></li>
                <li><a href="{{ route('rewards.index') }}" class="text-gray-300 hover:text-white transition-colors">🎮 Rewards</a></li>
            </ul>
        @endauth

        <!-- Desktop Right Section -->
        <nav class="hidden items-center gap-4 lg:flex">
            @auth
                <livewire:search-velflix />
                <a href="{{ route('notifications.index') }}" class="relative">
                    <x-bi-bell-fill class="h-5 w-5 text-gray-300 hover:text-yellow-400 transition-colors cursor-pointer" />
                </a>

                <!-- User Profile -->
                <div x-data="{ open: false }" class="relative inline-block">
                    <button @click="open = !open" @click.away="open = false" class="flex items-center gap-1">
                        <div class="h-8 w-8 rounded-full flex items-center justify-center text-sm font-bold text-black" style="background: linear-gradient(135deg, #C5A55A, #E8D5A3)">
                            {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                        </div>
                        <span :class="open ? '-rotate-180' : ''" class="transform transition-transform duration-300">
                            <x-bi-chevron-down class="h-3 w-3 text-gray-400" />
                        </span>
                    </button>

                    <div x-cloak x-show="open"
                        x-transition:enter="transition ease-out duration-200"
                        x-transition:enter-start="opacity-0 scale-95"
                        x-transition:enter-end="opacity-100 scale-100"
                        x-transition:leave="transition ease-in duration-150"
                        x-transition:leave-start="opacity-100 scale-100"
                        x-transition:leave-end="opacity-0 scale-95"
                        class="absolute right-0 mt-2 w-48 rounded-lg bg-gray-900 border border-gray-700 shadow-xl overflow-hidden">
                        <div class="px-4 py-3 border-b border-gray-700">
                            <p class="text-sm font-semibold text-white">{{ auth()->user()->name }}</p>
                            <p class="text-xs text-gray-400">{{ auth()->user()->email }}</p>
                        </div>
                        @can('admin')
                            <a href="/admin" class="block px-4 py-2.5 text-sm text-gray-300 hover:bg-gray-800 transition-colors">
                                ⚙️ Admin Dashboard
                            </a>
                        @endcan
                        <a href="{{ route('profile.show') }}" class="block px-4 py-2.5 text-sm text-gray-300 hover:bg-gray-800 transition-colors">
                            👤 Profile
                        </a>
                        <a href="{{ route('watchlist.index') }}" class="block px-4 py-2.5 text-sm text-gray-300 hover:bg-gray-800 transition-colors">
                            📋 My List
                        </a>
                        <a href="{{ route('rewards.index') }}" class="block px-4 py-2.5 text-sm text-gray-300 hover:bg-gray-800 transition-colors">
                            🎮 Rewards
                        </a>
                        <a href="{{ route('plans.index') }}" class="block px-4 py-2.5 text-sm text-gray-300 hover:bg-gray-800 transition-colors">
                            💎 Upgrade Plan
                        </a>
                        <form action="/logout" method="post">
                            @csrf
                            <button type="submit" class="w-full text-left block px-4 py-2.5 text-sm text-red-400 hover:bg-gray-800 transition-colors border-t border-gray-700">
                                🚪 Log Out
                            </button>
                        </form>
                    </div>
                </div>
            @else
                <a href="/login" class="text-sm text-gray-300 hover:text-white transition-colors">Log In</a>
                <a href="/register" class="text-sm font-semibold px-4 py-2 rounded text-black" style="background: linear-gradient(135deg, #C5A55A, #E8D5A3)">Sign Up</a>
            @endauth
        </nav>

        <!-- Mobile Hamburger Button -->
        <button @click="mobileOpen = !mobileOpen" class="lg:hidden p-2 rounded-md text-gray-300 hover:text-white">
            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path x-show="!mobileOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                <path x-show="mobileOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
    </div>

    <!-- Mobile Menu -->
    <div x-cloak x-show="mobileOpen" x-collapse class="lg:hidden border-t border-gray-800 bg-black/95">
        <div class="px-4 py-4 space-y-1">
            @auth
                <a href="{{ route('velflix.index') }}" class="block py-2.5 px-3 text-sm font-semibold rounded-md" style="color: #C5A55A">Home</a>
                <a href="{{ route('velflix.index') }}" class="block py-2.5 px-3 text-sm text-gray-300 hover:bg-gray-800 rounded-md">Films</a>
                <a href="{{ route('watchlist.index') }}" class="block py-2.5 px-3 text-sm text-gray-300 hover:bg-gray-800 rounded-md">📋 My List</a>
                <a href="{{ route('rewards.index') }}" class="block py-2.5 px-3 text-sm text-gray-300 hover:bg-gray-800 rounded-md">🎮 Rewards</a>
                <a href="{{ route('notifications.index') }}" class="block py-2.5 px-3 text-sm text-gray-300 hover:bg-gray-800 rounded-md">🔔 Notifications</a>
                <a href="{{ route('plans.index') }}" class="block py-2.5 px-3 text-sm text-gray-300 hover:bg-gray-800 rounded-md">💎 Plans</a>
                <div class="border-t border-gray-800 pt-3 mt-3">
                    <a href="{{ route('profile.show') }}" class="block py-2.5 px-3 text-sm text-gray-300 hover:bg-gray-800 rounded-md">👤 Profile</a>
                    @can('admin')
                        <a href="/admin" class="block py-2.5 px-3 text-sm text-gray-300 hover:bg-gray-800 rounded-md">⚙️ Admin Dashboard</a>
                    @endcan
                    <form action="/logout" method="post">
                        @csrf
                        <button type="submit" class="block w-full text-left py-2.5 px-3 text-sm text-red-400 hover:bg-gray-800 rounded-md">🚪 Log Out</button>
                    </form>
                </div>
            @else
                <a href="/login" class="block py-2.5 px-3 text-sm text-gray-300 hover:bg-gray-800 rounded-md">Log In</a>
                <a href="/register" class="block py-2.5 px-3 text-sm font-semibold rounded-md" style="color: #C5A55A">Sign Up</a>
            @endauth
        </div>
    </div>
</div>
