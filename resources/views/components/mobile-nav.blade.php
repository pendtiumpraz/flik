@php
    // Mobile bottom tab bar — only rendered when authed (see layout.blade.php).
    // Five fixed tabs that map to the most-used screens on small-screen flows.
    // Hidden on lg+ where the regular <x-header /> sticky top nav owns wayfinding.
    //
    // Active-route detection uses Laravel's route name patterns so the indicator
    // lights up correctly even when nested under variants (e.g. velflix.* covers
    // /movies and /movie/{id}; watchlist.* covers /my-list and /my-list/smart).
    $user = auth()->user();

    // user-level unread notifications — uses the User::unreadNotificationCount()
    // helper already in use by the desktop bell. Wrapped in a try so a missing
    // helper or DB hiccup never breaks the chrome on every page render.
    $unread = 0;
    try {
        if ($user && method_exists($user, 'unreadNotificationCount')) {
            $unread = (int) $user->unreadNotificationCount();
        }
    } catch (\Throwable $e) {
        $unread = 0;
    }

    $tabs = [
        [
            'label' => __('Home'),
            'icon' => 'home',
            'href' => route('velflix.index'),
            'active' => request()->routeIs('velflix.index') || request()->routeIs('movies.show'),
        ],
        [
            'label' => __('Discover'),
            'icon' => 'sparkles',
            'href' => route('discovery.mood.form'),
            'active' => request()->routeIs('discovery.*')
                || request()->routeIs('search.*')
                || request()->routeIs('compare.*')
                || request()->routeIs('family-night.*'),
        ],
        [
            'label' => __('My List'),
            'icon' => 'bookmark',
            'href' => route('watchlist.index'),
            'active' => request()->routeIs('watchlist.*'),
        ],
        [
            'label' => __('Alerts'),
            'icon' => 'bell',
            'href' => route('notifications.index'),
            'active' => request()->routeIs('notifications.*'),
            'badge' => $unread,
        ],
        [
            'label' => __('Profile'),
            'icon' => 'user',
            'href' => $user?->publicProfileUrl() ?? route('profile.show'),
            'active' => request()->routeIs('profile.*') || request()->routeIs('feed.*') || request()->routeIs('rewards.*'),
        ],
    ];
@endphp

{{-- Sticky bottom bar. z-40 keeps us below the modal/toast layer (50) and
     above page content. safe-area-inset-bottom keeps the tab bar above the
     iPhone home-indicator strip when running as a standalone PWA. --}}
<nav
    class="lg:hidden fixed bottom-0 inset-x-0 z-40 select-none"
    role="navigation"
    aria-label="{{ __('Primary mobile navigation') }}"
    style="background: #0a0a0a; border-top: 1px solid #1a1a1a; padding-bottom: env(safe-area-inset-bottom, 0px);"
>
    <ul class="grid grid-cols-5">
        @foreach($tabs as $tab)
            <li>
                <a
                    href="{{ $tab['href'] }}"
                    @class([
                        'relative flex flex-col items-center justify-center gap-0.5 py-2 px-1 text-[10px] font-medium transition-colors',
                        'text-[#C5A55A]' => $tab['active'] ?? false,
                        'text-gray-400 hover:text-[#C5A55A]/80' => !($tab['active'] ?? false),
                    ])
                    @if($tab['active'] ?? false) aria-current="page" @endif
                >
                    <div class="relative">
                        <x-icon :name="$tab['icon']" :size="22" />
                        @if(!empty($tab['badge']) && $tab['badge'] > 0)
                            <span
                                class="absolute -top-1.5 -right-2 min-w-[16px] h-4 px-1 rounded-full text-[9px] font-bold text-black flex items-center justify-center"
                                style="background: #C5A55A;"
                                aria-label="{{ $tab['badge'] }} {{ __('unread') }}"
                            >{{ $tab['badge'] > 99 ? '99+' : $tab['badge'] }}</span>
                        @endif
                    </div>
                    <span class="truncate max-w-full">{{ $tab['label'] }}</span>
                    @if($tab['active'] ?? false)
                        <span class="absolute top-1 h-1 w-1 rounded-full" style="background: #C5A55A;" aria-hidden="true"></span>
                    @endif
                </a>
            </li>
        @endforeach
    </ul>
</nav>
