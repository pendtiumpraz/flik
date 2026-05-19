@props([
    'movie',                  // App\Models\Movie (must be series, eager-loaded with seasons.episodes)
    'episodeProgress' => null, // Collection keyed by episode_id (App\Models\WatchHistory)
])

@php
    // Defensive: render nothing for non-series rows so the caller can drop
    // this component into any movie detail page unconditionally.
    if (! $movie->isSeries()) {
        return;
    }

    $seasons = $movie->seasons ?? collect();
    if ($seasons->isEmpty()) {
        return;
    }

    // Coerce the progress lookup into a collection keyed by episode_id so
    // O(1) `$episodeProgress->get($episode->id)` works even when the caller
    // passes raw arrays / null.
    $progress = $episodeProgress instanceof \Illuminate\Support\Collection
        ? $episodeProgress
        : collect($episodeProgress ?? [])->keyBy('episode_id');

    // Pre-pick the active season tab — first season by season_number.
    $initialSeasonId = $seasons->first()->id;
@endphp

<section class="mt-10 md:mt-12" x-data="{ activeSeason: {{ (int) $initialSeasonId }} }">
    <div class="flex items-center justify-between mb-5">
        <div class="flex items-center gap-2.5">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#C5A55A" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="15" rx="2"/><polyline points="17 2 12 7 7 2"/></svg>
            <h3 class="font-heading font-semibold text-white text-lg">Episodes</h3>
            <span class="text-xs px-2 py-0.5 rounded-full" style="background: rgba(197,165,90,0.1); color: #C5A55A">
                {{ (int) ($movie->total_episodes ?? $movie->episodes->count()) }} ep
            </span>
        </div>
    </div>

    {{-- ━━━ Season tabs ━━━ --}}
    <div class="flex items-center gap-2 mb-5 overflow-x-auto pb-1" style="scrollbar-width: thin">
        @foreach($seasons as $season)
            <button type="button"
                    @click="activeSeason = {{ (int) $season->id }}"
                    :class="activeSeason === {{ (int) $season->id }} ? 'is-active' : ''"
                    class="px-4 py-2 rounded-lg text-sm font-medium whitespace-nowrap transition-all flex-shrink-0"
                    :style="activeSeason === {{ (int) $season->id }}
                        ? 'background: linear-gradient(135deg, #C5A55A, #E8D5A3); color: #000; border: 1px solid #C5A55A;'
                        : 'background: rgba(20,18,16,0.6); color: #ccc; border: 1px solid rgba(197,165,90,0.2);'">
                Season {{ $season->season_number }}
                <span class="ml-1 text-[10px] opacity-70">({{ $season->episodes->count() }})</span>
            </button>
        @endforeach
    </div>

    {{-- ━━━ Episode grids (one panel per season, toggled by Alpine) ━━━ --}}
    @foreach($seasons as $season)
        <div x-show="activeSeason === {{ (int) $season->id }}" x-cloak>
            @if($season->overview)
                <p class="text-sm text-gray-400 mb-4 leading-relaxed">{{ $season->overview }}</p>
            @endif

            @if($season->episodes->isEmpty())
                <p class="text-sm text-gray-500 italic py-6 text-center">Belum ada episode di season ini.</p>
            @else
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    @foreach($season->episodes as $episode)
                        @php
                            $hist = $progress->get($episode->id);
                            // Prefer current_time/duration shape (matches the
                            // WatchHistoryController forceFill) and fall back
                            // to the older progress_seconds shape if present.
                            $current = (int) ($hist->current_time ?? $hist->progress_seconds ?? 0);
                            $totalSec = (int) ($hist->duration ?? $hist->duration_seconds ?? ($episode->duration_seconds ?? 0));
                            $pct = $totalSec > 0
                                ? min(100, (int) round($current / $totalSec * 100))
                                : 0;
                            $completed = (bool) ($hist->completed ?? false);
                            $still = $episode->still_url;
                            $blurb = $episode->generated_summary ?: $episode->overview;
                        @endphp
                        <a href="{{ route('episodes.watch', $episode) }}"
                           class="group flex gap-3 p-3 rounded-xl transition-all hover:translate-y-[-1px]"
                           style="background: rgba(20,18,16,0.6); border: 1px solid rgba(197,165,90,0.12)">
                            {{-- Thumbnail --}}
                            <div class="relative flex-shrink-0 w-32 md:w-40 aspect-video rounded-lg overflow-hidden bg-black">
                                @if($still)
                                    <img src="{{ $still }}" alt=""
                                         class="absolute inset-0 w-full h-full object-cover opacity-90 group-hover:opacity-100 transition-opacity"
                                         loading="lazy"
                                         onerror="this.style.display='none'">
                                @endif
                                <div class="absolute inset-0 flex items-center justify-center">
                                    <div class="w-10 h-10 rounded-full flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity"
                                         style="background: rgba(197,165,90,0.95)">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-black ml-0.5" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M8 5v14l11-7z"/>
                                        </svg>
                                    </div>
                                </div>
                                {{-- Per-episode progress bar --}}
                                @if($pct > 0)
                                    <div class="absolute bottom-0 left-0 right-0 h-1 bg-black/60">
                                        <div class="h-full" style="width: {{ $pct }}%; background: #C5A55A"></div>
                                    </div>
                                @endif
                                @if($completed)
                                    <span class="absolute top-1.5 right-1.5 text-[9px] uppercase font-bold px-1.5 py-0.5 rounded"
                                          style="background: rgba(34,197,94,0.85); color: #000">
                                        ✓
                                    </span>
                                @endif
                            </div>

                            {{-- Meta --}}
                            <div class="flex-1 min-w-0">
                                <div class="flex items-baseline gap-2">
                                    <span class="text-[11px] uppercase tracking-wider font-bold" style="color: #C5A55A">
                                        Ep {{ $episode->episode_number }}
                                    </span>
                                    @if($episode->runtime_minutes)
                                        <span class="text-[11px] text-gray-500">· {{ $episode->runtime_minutes }} min</span>
                                    @endif
                                    @if($episode->air_date)
                                        <span class="text-[11px] text-gray-500">· {{ $episode->air_date->format('d M Y') }}</span>
                                    @endif
                                </div>
                                <h4 class="text-sm md:text-base font-semibold text-white leading-snug mt-0.5 group-hover:text-[#C5A55A] transition-colors">
                                    {{ $episode->title }}
                                </h4>
                                @if($blurb)
                                    <p class="text-xs text-gray-400 mt-1 leading-relaxed line-clamp-2">
                                        {{ \Illuminate\Support\Str::limit($blurb, 140) }}
                                    </p>
                                @endif
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    @endforeach
</section>
