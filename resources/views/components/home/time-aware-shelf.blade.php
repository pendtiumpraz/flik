@props([
    'movies' => null,
    'slot' => null,
    'label' => null,
    'standalone' => false,
])

@php
    use App\Services\Ai\Recommendations\TimeAwareRecommender;

    /**
     * Time-aware "Cocok ditonton sekarang" shelf.
     *
     * Usage:
     *   <x-home.time-aware-shelf />                              (auto: resolves for current user + now)
     *   <x-home.time-aware-shelf :movies="$movies" :slot="$s" />  (pre-resolved by a controller)
     *
     * Renders nothing for guests or when there's no catalog.
     */

    $user = auth()->user();
    $hasUser = (bool) $user;

    // Auto-resolve when caller didn't pass data
    if ($movies === null) {
        if (!$hasUser) {
            $movies = collect();
        } else {
            try {
                /** @var \App\Services\Ai\Recommendations\TimeAwareRecommender $svc */
                $svc = app(TimeAwareRecommender::class);
                $now = \Carbon\Carbon::now(TimeAwareRecommender::TIMEZONE);
                $slot = $slot ?: $svc->slotFor($now);
                $label = $label ?: $svc->slotLabel($slot);
                $movies = $svc->recommendByTimeOfDay($user, $now, 12);
            } catch (\Throwable $e) {
                \Log::warning('time-aware-shelf: resolve failed', ['error' => $e->getMessage()]);
                $movies = collect();
            }
        }
    }

    if ($slot === null || $label === null) {
        $svc = $svc ?? app(TimeAwareRecommender::class);
        $now = $now ?? \Carbon\Carbon::now(TimeAwareRecommender::TIMEZONE);
        $slot = $slot ?: $svc->slotFor($now);
        $label = $label ?: $svc->slotLabel($slot);
    }

    // Genre map for popover (id => name) — matches <x-movies> contract
    $genreMap = \App\Models\Genre::query()->get()->mapWithKeys(fn ($g) => [$g->id => $g->name]);

    // Map Movie models → array shape <x-movies> understands
    $movieArrays = collect($movies)->map(function ($m) {
        if (is_array($m)) {
            return $m;
        }
        return [
            'id'             => $m->id,
            'slug'           => $m->slug,
            'title'          => $m->title,
            'overview'       => $m->overview,
            'release_date'   => $m->release_date ? $m->release_date->format('Y-m-d') : null,
            'poster_path'    => $m->effective_poster_url ?? $m->poster_url,
            'backdrop_path'  => $m->effective_backdrop_url ?? $m->backdrop_url,
            'vote_average'   => (float) $m->vote_average,
            'vote_count'     => $m->vote_count,
            'genre_ids'      => $m->relationLoaded('genres')
                ? $m->genres->pluck('id')->toArray()
                : [],
        ];
    });

    // Slot icon
    $slotIcon = match ($slot) {
        TimeAwareRecommender::SLOT_MORNING   => 'sun',
        TimeAwareRecommender::SLOT_AFTERNOON => 'clock',
        TimeAwareRecommender::SLOT_EVENING   => 'fire',
        TimeAwareRecommender::SLOT_LATE      => 'moon',
        TimeAwareRecommender::SLOT_OVERNIGHT => 'sparkles',
        default                              => 'clock',
    };

    // Map slot → JS variable for the front-end refresher
    $slotJs = $slot;
@endphp

@if($movieArrays->isEmpty())
    {{-- Render nothing when there are no movies for this slot --}}
@else
    <section
        class="time-aware-shelf mb-8 md:mb-10"
        x-data="timeAwareShelf({{ json_encode($slotJs) }})"
        x-init="init()"
    >
        <div class="flex items-center gap-3 mb-3 md:mb-4">
            <div class="flex items-center gap-2">
                @if(view()->exists('components.icon'))
                    <x-icon :name="$slotIcon" :size="16" class="text-[#C5A55A]" />
                @endif
                <h2 class="font-heading text-base md:text-lg font-semibold text-white tracking-wide">
                    Cocok ditonton sekarang
                </h2>
            </div>
            <span
                class="text-[11px] text-gray-400 px-2 py-0.5 rounded-full"
                style="background: rgba(197,165,90,0.08); border: 1px solid rgba(197,165,90,0.2)"
                x-text="slotLabel"
            >{{ $label }}</span>
            <span class="ml-auto text-[11px] text-gray-500 hidden md:inline" x-text="clockLabel">
                {{ \Carbon\Carbon::now(TimeAwareRecommender::TIMEZONE)->format('H:i') }} WIB
            </span>
        </div>

        <x-movies :movies="$movieArrays" :genres="$genreMap" density="large">
            <x-slot:category>
                <span class="hidden">Cocok ditonton sekarang</span>
            </x-slot:category>
        </x-movies>
    </section>

    @once
        <script>
            // ━━━ Time-aware shelf: refresh slot label client-side ━━━
            // Server already picked a slot, but we recompute on the client
            // every minute so the label stays accurate if the page is left open
            // across a slot boundary (e.g., 16:59 → 17:00 evening prime time).
            function timeAwareShelf(initialSlot) {
                return {
                    slot: initialSlot,
                    slotLabel: '',
                    clockLabel: '',
                    init() {
                        this.refresh();
                        // Update every 60s
                        setInterval(() => this.refresh(), 60_000);
                    },
                    computeSlot(hour) {
                        if (hour >= 5  && hour < 11) return 'morning';
                        if (hour >= 11 && hour < 17) return 'afternoon';
                        if (hour >= 17 && hour < 21) return 'evening';
                        if (hour >= 21 && hour < 24) return 'late_night';
                        return 'overnight';
                    },
                    labelFor(slot) {
                        switch (slot) {
                            case 'morning':    return 'Pagi — ringan & menyegarkan';
                            case 'afternoon':  return 'Siang — drama & petualangan';
                            case 'evening':    return 'Prime Time — film unggulan malam ini';
                            case 'late_night': return 'Larut malam — sinema dalam';
                            case 'overnight':  return 'Dini hari — santai & kontemplatif';
                            default:           return 'Cocok ditonton sekarang';
                        }
                    },
                    refresh() {
                        // Compute Asia/Jakarta hour reliably
                        try {
                            const fmt = new Intl.DateTimeFormat('en-GB', {
                                timeZone: 'Asia/Jakarta',
                                hour: '2-digit',
                                minute: '2-digit',
                                hour12: false,
                            });
                            const parts = fmt.formatToParts(new Date());
                            let h = 0, m = 0;
                            parts.forEach(p => {
                                if (p.type === 'hour')   h = parseInt(p.value, 10);
                                if (p.type === 'minute') m = parseInt(p.value, 10);
                            });
                            const slot = this.computeSlot(h);
                            this.slot = slot;
                            this.slotLabel = this.labelFor(slot);
                            const pad = n => String(n).padStart(2, '0');
                            this.clockLabel = `${pad(h)}:${pad(m)} WIB`;
                        } catch (_e) {
                            // Fallback to whatever the server picked
                            this.slotLabel = this.labelFor(this.slot);
                        }
                    },
                };
            }
        </script>
    @endonce
@endif
