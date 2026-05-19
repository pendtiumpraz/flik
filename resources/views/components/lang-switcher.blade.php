@php
    /**
     * Locale dropdown — renders one POST-form per available locale so the
     * change goes through LocaleController (which persists + bounces back).
     *
     * Driven entirely by config('locales.available'); add an entry there and
     * the switcher picks it up — no view edits needed.
     */
    $available = (array) config('locales.available', []);
    $current = app()->getLocale();
    $currentMeta = $available[$current] ?? null;
@endphp

@if(! empty($available))
    <div x-data="{ openLang: false }" class="relative">
        <button type="button"
                @click="openLang = !openLang"
                @click.away="openLang = false"
                aria-haspopup="true"
                :aria-expanded="openLang ? 'true' : 'false'"
                class="flex items-center gap-1.5 text-sm text-gray-300 hover:text-[#C5A55A] transition-colors"
                title="{{ __('Language') }}">
            <span class="text-base leading-none">{{ $currentMeta['flag'] ?? "\u{1F310}" }}</span>
            <span class="hidden sm:inline">{{ strtoupper($current) }}</span>
            <span :class="openLang ? '-rotate-180' : ''" class="transform transition-transform duration-300">
                <x-icon name="chevron-down" :size="12" />
            </span>
        </button>

        <div x-cloak x-show="openLang"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 scale-100"
             x-transition:leave-end="opacity-0 scale-95"
             class="absolute right-0 mt-3 w-56 rounded-xl shadow-2xl overflow-hidden z-50"
             style="background: linear-gradient(180deg, #1a1a1a 0%, #141414 100%); border: 1px solid rgba(197,165,90,0.25)">
            <div class="px-4 py-2 text-[10px] font-semibold uppercase tracking-wider text-[#C5A55A]/80 border-b"
                 style="border-color: rgba(197,165,90,0.15)">
                {{ __('Language') }}
            </div>
            <div class="py-1">
                @foreach($available as $code => $meta)
                    @php
                        $isActive = $code === $current;
                    @endphp
                    <form action="{{ route('locale.switch', ['code' => $code]) }}" method="POST" class="block">
                        @csrf
                        <button type="submit"
                                @class([
                                    'w-full flex items-center gap-3 px-4 py-2.5 text-sm transition-colors group text-start',
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
    </div>
@endif
