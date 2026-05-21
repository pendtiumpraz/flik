@php
    // Mobile bottom tab bar — only rendered when authed (see layout.blade.php).
    // Six fixed tabs that map to the most-used screens on small-screen flows.
    // Hidden on lg+ where the regular <x-header /> sticky top nav owns wayfinding.
    //
    // Active-route detection uses Laravel's route name patterns so the indicator
    // lights up correctly even when nested under variants (e.g. velflix.* covers
    // /movies and /movie/{id}; watchlist.* covers /my-list and /my-list/smart).
    //
    // The 6th tab ("More") opens a bottom sheet (Alpine-driven) that exposes
    // the language switcher + Settings/Help/Logout — closes audit 19 i18n-3
    // (mobile users had no UI affordance to change locale).
    $user = auth()->user();
    $availableLocales = (array) config('locales.available', []);
    $currentLocale = app()->getLocale();
    $currentLocaleMeta = $availableLocales[$currentLocale] ?? null;

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

{{-- Sticky bottom bar + "More" sheet. z-40 keeps the bar below the modal/toast
     layer (50) and above page content. safe-area-inset-bottom keeps the tab bar
     above the iPhone home-indicator strip when running as a standalone PWA. The
     sheet is rendered as a sibling element inside an Alpine x-data root so the
     same `moreOpen` state can toggle both pieces. --}}
<div x-data="{ moreOpen: false }" class="lg:hidden">
    <nav
        class="fixed bottom-0 inset-x-0 z-40 select-none"
        role="navigation"
        aria-label="{{ __('Primary mobile navigation') }}"
        style="background: #0a0a0a; border-top: 1px solid #1a1a1a; padding-bottom: env(safe-area-inset-bottom, 0px);"
    >
        <ul class="grid grid-cols-6">
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

            {{-- 6th tab: "More". Opens the overflow sheet that hosts the language
                 switcher + Settings/Help/Logout. Replaces the desktop-only header
                 lang-switcher mount so Arabic/Indonesian/English mobile users
                 finally have an in-app affordance to change locale (audit 19 i18n-3). --}}
            <li>
                <button
                    type="button"
                    @click="moreOpen = true"
                    aria-haspopup="dialog"
                    :aria-expanded="moreOpen ? 'true' : 'false'"
                    class="w-full h-full relative flex flex-col items-center justify-center gap-0.5 py-2 px-1 text-[10px] font-medium text-gray-400 hover:text-[#C5A55A]/80 transition-colors"
                >
                    <x-icon name="menu" :size="22" />
                    <span class="truncate max-w-full">{{ __('More') }}</span>
                </button>
            </li>
        </ul>
    </nav>

    {{-- Overflow sheet. Backdrop closes on tap; sheet animates from the bottom.
         z-[55] sits ABOVE modals (50) so it owns the screen while open.
         Inline-style padding-bottom uses env(safe-area-inset-bottom) so the
         sheet's bottom action row clears the home-indicator on iPhone X-class. --}}
    <div
        x-cloak
        x-show="moreOpen"
        x-transition.opacity
        @click="moreOpen = false"
        @keydown.escape.window="moreOpen = false"
        class="fixed inset-0 z-[55] bg-black/70"
        aria-hidden="true"
    ></div>

    <div
        x-cloak
        x-show="moreOpen"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="translate-y-full"
        x-transition:enter-end="translate-y-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="translate-y-0"
        x-transition:leave-end="translate-y-full"
        class="fixed bottom-0 inset-x-0 z-[60] rounded-t-2xl shadow-2xl overflow-hidden"
        style="background: #141414; border-top: 1px solid rgba(197,165,90,0.25); padding-bottom: env(safe-area-inset-bottom, 0px);"
        role="dialog"
        aria-modal="true"
        aria-labelledby="mobile-more-title"
    >
        <div class="flex items-center justify-between px-5 pt-4 pb-2">
            <h2 id="mobile-more-title" class="text-sm font-semibold text-[#C5A55A] uppercase tracking-wider">{{ __('More') }}</h2>
            <button type="button" @click="moreOpen = false" class="text-gray-400 hover:text-white" aria-label="{{ __('Close') }}">
                <x-icon name="x" :size="20" />
            </button>
        </div>

        {{-- Language section — one POST form per locale, identical wiring to
             <x-lang-switcher /> but tuned for the sheet layout. --}}
        @if(! empty($availableLocales))
            <div class="px-5 py-3 border-t" style="border-color: rgba(197,165,90,0.15)">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-[#C5A55A]/80 mb-2">
                    {{ __('Language') }}
                    @if($currentLocaleMeta)
                        <span class="ml-1 text-gray-400 normal-case font-normal">
                            · {{ $currentLocaleMeta['flag'] ?? '' }} {{ $currentLocaleMeta['name'] ?? strtoupper($currentLocale) }}
                        </span>
                    @endif
                </p>
                <div class="grid grid-cols-1 gap-1">
                    @foreach($availableLocales as $code => $meta)
                        @php $isActive = $code === $currentLocale; @endphp
                        <form action="{{ route('locale.switch', ['code' => $code]) }}" method="POST">
                            @csrf
                            <button type="submit"
                                    @class([
                                        'w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm transition-colors text-start',
                                        'text-[#C5A55A] bg-[#C5A55A]/10 font-medium' => $isActive,
                                        'text-gray-200 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A]' => ! $isActive,
                                    ])>
                                <span class="text-base leading-none">{{ $meta['flag'] ?? '' }}</span>
                                <span class="flex-1">{{ $meta['name'] ?? $code }}</span>
                                @if($isActive)
                                    <x-icon name="check" :size="14" class="text-[#C5A55A]" />
                                @endif
                            </button>
                        </form>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Settings / Help / Logout overflow row. Plain anchor tags / one form
             so progressive enhancement still works if Alpine is unavailable. --}}
        <div class="px-5 py-3 border-t" style="border-color: rgba(197,165,90,0.15)">
            <ul class="space-y-1">
                <li>
                    <a href="{{ route('profile.show') }}"
                       class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-200 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A]">
                        <x-icon name="user" :size="18" />
                        <span>{{ __('Profile') }}</span>
                    </a>
                </li>
                @if(Route::has('help.index'))
                    <li>
                        <a href="{{ route('help.index') }}"
                           class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-200 hover:bg-[#C5A55A]/10 hover:text-[#C5A55A]">
                            <x-icon name="sparkles" :size="18" />
                            <span>{{ __('Help') }}</span>
                        </a>
                    </li>
                @endif
                <li>
                    <form action="{{ route('logout') }}" method="POST">
                        @csrf
                        <button type="submit"
                                class="w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-200 hover:bg-red-500/10 hover:text-red-400 text-start">
                            <x-icon name="x" :size="18" />
                            <span>{{ __('Logout') }}</span>
                        </button>
                    </form>
                </li>
            </ul>
        </div>
    </div>
</div>
