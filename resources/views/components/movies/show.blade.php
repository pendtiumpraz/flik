@php
    $seoTitle = trim(
        ($movieModel->title ?? ($movies['title'] ?? 'FLiK'))
        . (optional($movieModel->release_date)->format('Y') ? ' (' . $movieModel->release_date->format('Y') . ')' : '')
        . ' — FLiK'
    );
    $seoDescription = \Illuminate\Support\Str::limit(
        $movies['overview'] ?? ($movieModel->overview ?? 'Nonton di FLiK — Rumah Sinema Indonesia.'),
        160,
        ''
    );
    $seoOgImage = $movieModel->backdrop_url ?: $movieModel->poster_url;
@endphp

<x-layout :title="$seoTitle" :description="$seoDescription" :ogImage="$seoOgImage">
    {{-- AI-generated SEO meta (FIX #7) — pushes <title>, description,
         keywords, OG, and Twitter Card tags into the layout <head> via
         @stack('head'). The component reads seo_title/seo_description/
         seo_keywords directly from the movie row (populated by
         SeoMetaGenerator) and falls back to title+overview when those
         columns are still NULL. --}}
    @push('head')
        <x-movie-seo :movie="$movieModel" />
    @endpush

    <x-seo.movie-jsonld :movie="$movieModel" />
    <div class="min-h-screen bg-black pt-16">
        <!-- Hero / Player Section -->
        <div class="relative w-full bg-black">
            <!-- Backdrop bg -->
            <div class="absolute inset-0 z-0">
                <img src="{{ $movies['backdrop_path'] }}" alt=""
                     class="w-full h-full object-cover opacity-30 blur-xl"
                     onerror="this.style.display='none'">
                <div class="absolute inset-0" style="background: linear-gradient(180deg, rgba(0,0,0,0.4) 0%, rgba(0,0,0,0.85) 50%, #000 100%)"></div>
            </div>

            <div class="relative z-10 container mx-auto px-4 md:px-8 lg:px-16 max-w-6xl py-8 md:py-12">
                <!-- Player -->
                <div class="rounded-2xl overflow-hidden shadow-2xl" style="border: 1px solid rgba(197,165,90,0.2)">
                    <div id="flik-player-wrap" class="relative bg-black" style="padding-top: 56.25%">
                        @if($movieModel->encoding_status === 'ready' && $movieModel->hls_manifest_path)
                            {{-- ━━━ Shaka Player (DRM/HLS pipeline) ━━━
                                 Used when the movie has been transcoded + DRM-packaged. The
                                 FlikPlayer wrapper hits /playback/{slug}/config to bootstrap
                                 the manifest URL + JWT, then Shaka handles ABR + AES key
                                 fetches. Auto-skip and X-Ray overlay layer on top. --}}
                            <video id="flik-shaka-player"
                                class="absolute top-0 left-0 w-full h-full bg-black"
                                controls preload="auto"
                                poster="{{ $movies['backdrop_path'] }}"
                                data-intro-start="{{ $movieModel->intro_start_seconds }}"
                                data-intro-end="{{ $movieModel->intro_end_seconds }}"
                                data-outro-start="{{ $movieModel->outro_start_seconds }}"
                                data-recap-end="{{ $movieModel->recap_end_seconds }}"
                                data-movie-slug="{{ $movieModel->slug }}"></video>
                            <div
                                x-data="{
                                    player: null,
                                    autoSkip: null,
                                    xray: null,
                                    error: null,
                                    subs: [],
                                    async init() {
                                        await this.waitForShaka();
                                        const videoEl = document.getElementById('flik-shaka-player');
                                        const wrap = document.getElementById('flik-player-wrap');
                                        if (!videoEl || typeof window.FlikPlayer !== 'function') return;
                                        try {
                                            this.player = new window.FlikPlayer('flik-shaka-player', '{{ $movieModel->slug }}');
                                            await this.player.initialize();
                                            this.subs = this.player.config?.subtitles || [];
                                            this.autoSkip = window.initAutoSkip({
                                                video: videoEl,
                                                markerSource: videoEl,
                                                overlay: wrap,
                                                shakaPlayer: this.player.shakaPlayer,
                                            });
                                            this.xray = window.initXrayOverlay({
                                                videoElement: videoEl,
                                                movieSlug: '{{ $movieModel->slug }}',
                                                containerEl: wrap,
                                                csrfToken: document.querySelector('meta[name=csrf-token]')?.content || '',
                                            });
                                        } catch (e) {
                                            console.error('[FLiK] player init failed', e);
                                            this.error = e.message || 'Playback unavailable';
                                        }
                                    },
                                    waitForShaka() {
                                        return new Promise((resolve) => {
                                            if (typeof window.shaka !== 'undefined') return resolve();
                                            let tries = 0;
                                            const id = setInterval(() => {
                                                if (typeof window.shaka !== 'undefined' || tries++ > 100) {
                                                    clearInterval(id);
                                                    resolve();
                                                }
                                            }, 100);
                                        });
                                    },
                                    onSubChange(e) {
                                        const lang = e.target.value;
                                        if (!this.player) return;
                                        if (!lang) { this.player.disableText(); }
                                        else { this.player.selectTextLanguage(lang); }
                                    },
                                }"
                                x-init="init()"
                                @beforeunload.window="player?.destroy(); autoSkip?.destroy(); xray?.destroy();"
                            >
                                <template x-if="error">
                                    <div class="absolute inset-0 flex items-center justify-center bg-[#0a0a0a]/90 z-30 pointer-events-none">
                                        <div class="text-center text-gray-400 px-4">
                                            <div class="text-sm" x-text="error"></div>
                                        </div>
                                    </div>
                                </template>
                                {{-- Subtitle (CC) picker for the Shaka path — native controls
                                     don't expose Shaka text tracks, so we drive them manually. --}}
                                <template x-if="subs.length">
                                    <div class="absolute top-3 right-3 z-30">
                                        <select @change="onSubChange($event)"
                                                class="bg-black/70 text-white text-xs rounded px-2 py-1 border border-white/20 focus:outline-none">
                                            <option value="">CC: Off</option>
                                            <template x-for="s in subs" :key="s.language">
                                                <option :value="s.language" x-text="s.label"></option>
                                            </template>
                                        </select>
                                    </div>
                                </template>
                            </div>
                        @elseif($movieModel->video_path)
                            <link href="https://vjs.zencdn.net/8.10.0/video-js.css" rel="stylesheet">
                            <video id="flik-player" class="video-js vjs-fluid vjs-big-play-centered absolute top-0 left-0 w-full h-full"
                                controls preload="auto"
                                poster="{{ $movies['backdrop_path'] }}"
                                data-setup='{"playbackRates": [0.5, 1, 1.25, 1.5, 2]}'>
                                <source src="{{ $movieModel->video_full_url }}" type="video/mp4">
                                {{-- Subtitle tracks — Video.js shows a captions menu automatically.
                                     Served same-origin via playback.subtitle (no CORS needed). --}}
                                @foreach($movieModel->activeSubtitles as $sub)
                                    <track kind="subtitles"
                                           src="{{ route('playback.subtitle', ['movie' => $movieModel, 'subtitle' => $sub->id]) }}"
                                           srclang="{{ $sub->language_code }}"
                                           label="{{ $sub->native_name }}"
                                           @if($sub->is_default) default @endif>
                                @endforeach
                            </video>
                            <script src="https://vjs.zencdn.net/8.10.0/video.min.js"></script>
                            <style>
                                .video-js .vjs-play-progress { background: #C5A55A; }
                                .video-js .vjs-volume-level { background: #C5A55A; }
                                .video-js .vjs-big-play-button {
                                    background: rgba(197,165,90,0.85); border: none; border-radius: 50%;
                                    width: 80px; height: 80px; line-height: 80px;
                                    font-size: 32px; margin-top: -40px; margin-left: -40px;
                                }
                                .video-js .vjs-big-play-button:hover { background: #C5A55A; }
                                .video-js .vjs-control-bar { background: rgba(0,0,0,0.85); }
                                .video-js .vjs-slider { background: rgba(255,255,255,0.15); }
                            </style>
                        @elseif (!empty($movies['videos']['results']))
                            <iframe class="absolute inset-0 w-full h-full"
                                src="https://www.youtube.com/embed/{{ $movies['videos']['results'][0]['key'] }}"
                                style="border:0;" allow="autoplay; encrypted-media" allowfullscreen></iframe>
                        @else
                            <div class="absolute inset-0 flex items-center justify-center bg-[#0a0a0a]">
                                <div class="text-center text-gray-500">
                                    <x-icon name="play" :size="48" class="mx-auto mb-3 text-gray-700" />
                                    <span class="text-sm">Video belum tersedia</span>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>

                <!-- Info & Actions Bar -->
                <div class="mt-6 md:mt-8 flex flex-col md:flex-row gap-6">
                    <!-- Left: Title + Meta + Synopsis -->
                    <div class="flex-1 min-w-0">
                        <div class="flex items-start gap-4 mb-4">
                            <!-- Score -->
                            <div class="flex-shrink-0 w-14 h-14 md:w-16 md:h-16 rounded-full flex items-center justify-center" style="background: rgba(0,0,0,0.6); border: 2px solid #C5A55A">
                                <span class="font-bold text-[#C5A55A] text-sm md:text-base">{{ round($movies['vote_average'] * 10) }}<span class="text-[10px] opacity-70">%</span></span>
                            </div>
                            <div class="flex-1 min-w-0">
                                <h1 class="font-heading text-2xl md:text-4xl font-bold text-white leading-tight">{{ $movies['title'] }}</h1>
                                @if($movies['original_title'] !== $movies['title'])
                                    <p class="text-sm text-gray-500 mt-1 italic">{{ $movies['original_title'] }}</p>
                                @endif
                                <div class="flex items-center flex-wrap gap-x-3 gap-y-1 mt-2 text-xs md:text-sm">
                                    @if(!empty($movies['release_date']))
                                        <span class="text-gray-400">{{ date('Y', strtotime($movies['release_date'])) }}</span>
                                    @endif
                                    @if(count($movies['genres']) > 0)
                                        <span class="text-gray-600">·</span>
                                        <span class="text-gray-400">
                                            @foreach($movies['genres'] as $genre)
                                                {{ $genre['name'] }}@if(!$loop->last)<span class="text-gray-600 mx-1">·</span>@endif
                                            @endforeach
                                        </span>
                                    @endif
                                    <span class="px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wider rounded" style="background: rgba(197,165,90,0.15); color: #C5A55A; border: 1px solid rgba(197,165,90,0.3)">HD</span>
                                </div>
                            </div>
                        </div>

                        <!-- Action buttons -->
                        <div class="flex items-center flex-wrap gap-2 mb-5">
                            @php
                                // Series: jump directly into the first unwatched episode
                                // (or the very first episode for a fresh viewer). Standalone
                                // movies fall back to the in-page player.
                                $resumeEpisode = null;
                                if ($movieModel->isSeries() && auth()->check()) {
                                    $resumeEpisode = $movieModel->firstUnwatchedEpisode(auth()->user());
                                } elseif ($movieModel->isSeries()) {
                                    $resumeEpisode = $movieModel->episodes->first();
                                }
                            @endphp
                            @if($resumeEpisode)
                                <a href="{{ route('episodes.watch', $resumeEpisode) }}"
                                   class="inline-flex items-center gap-2 px-5 py-2.5 rounded-md text-sm font-bold text-black hover:opacity-95 transition-opacity"
                                   style="background: linear-gradient(135deg, #C5A55A, #E8D5A3)">
                                    <x-icon name="play-solid" :size="14" />
                                    {{ $resumeEpisode->season?->season_number ? 'Play S' . $resumeEpisode->season->season_number . 'E' . $resumeEpisode->episode_number : 'Play' }}
                                </a>
                            @else
                                <button class="inline-flex items-center gap-2 px-5 py-2.5 rounded-md text-sm font-bold text-black hover:opacity-95 transition-opacity" style="background: linear-gradient(135deg, #C5A55A, #E8D5A3)">
                                    <x-icon name="play-solid" :size="14" /> Play
                                </button>
                            @endif

                            <form method="POST" action="{{ route('watchlist.toggle') }}">
                                @csrf
                                <input type="hidden" name="movie_id" value="{{ $movies['id'] }}">
                                <button type="submit" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-md text-sm font-medium transition-all"
                                        style="background: rgba(255,255,255,0.05); border: 1px solid {{ $inWatchlist ? '#C5A55A' : 'rgba(197,165,90,0.25)' }}; color: {{ $inWatchlist ? '#C5A55A' : '#fff' }}">
                                    @if($inWatchlist)
                                        <x-icon name="check" :size="14" class="text-[#C5A55A]" :stroke="2.5" /> In My List
                                    @else
                                        <x-icon name="plus" :size="14" class="text-[#C5A55A]" :stroke="2.5" /> My List
                                    @endif
                                </button>
                            </form>

                            <button class="inline-flex items-center gap-2 px-4 py-2.5 rounded-md text-sm font-medium text-white hover:border-[#C5A55A] transition-colors" style="background: rgba(255,255,255,0.05); border: 1px solid rgba(197,165,90,0.25)">
                                <x-icon name="heart" :size="14" class="text-[#C5A55A]" /> Like
                            </button>

                            {{-- AI Plot Explain modal launcher (renders both button + modal) --}}
                            <x-movies.plot-explain-modal :movie="$movieModel" />

                            {{-- Compare with another film --}}
                            <a href="{{ route('compare.form', ['movie_a' => $movieModel->id]) }}"
                               class="inline-flex items-center gap-2 px-4 py-2.5 rounded-md text-sm font-medium transition-colors hover:border-[#C5A55A]"
                               style="background: rgba(255,255,255,0.05); border: 1px solid rgba(197,165,90,0.25); color: #C5A55A"
                               title="Bandingkan film ini dengan film lain">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 3h5v5"/><path d="M4 20L21 3"/><path d="M21 16v5h-5"/><path d="M15 15l6 6"/><path d="M4 4l5 5"/></svg>
                                <span>Bandingkan Film Ini</span>
                            </a>

                            {{-- Trivia Quiz Game (O5) --}}
                            <a href="{{ route('quiz.start', $movieModel) }}"
                               class="inline-flex items-center gap-2 px-4 py-2.5 rounded-md text-sm font-medium transition-colors hover:border-[#C5A55A]"
                               style="background: rgba(255,255,255,0.05); border: 1px solid rgba(197,165,90,0.25); color: #C5A55A"
                               title="Uji pengetahuan kamu tentang film ini — raih XP & koin">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                <span>Mainkan Trivia Quiz</span>
                            </a>

                            @if($ratingsCount > 0)
                            <div class="ml-auto inline-flex items-center gap-1.5 text-sm">
                                <x-icon name="star-solid" :size="14" class="text-[#C5A55A]" />
                                <span class="font-bold text-[#C5A55A]">{{ $avgRating }}</span>
                                <span class="text-gray-500 text-xs">({{ $ratingsCount }} review)</span>
                            </div>
                            @endif
                        </div>

                        <!-- Synopsis -->
                        <p class="text-sm md:text-base text-gray-300 leading-relaxed">
                            {{ $movies['overview'] ?: 'Sinopsis belum tersedia.' }}
                        </p>
                    </div>

                    <!-- Right: Metadata sidebar -->
                    <aside class="w-full md:w-72 flex-shrink-0 space-y-4 text-sm">
                        @if(count($movies['credits']['cast']) > 0)
                        <div class="p-4 rounded-xl" style="background: rgba(20,18,16,0.6); border: 1px solid rgba(197,165,90,0.15)">
                            <div class="text-[10px] uppercase tracking-wider text-[#C5A55A] font-semibold mb-2">Cast</div>
                            <div class="text-gray-300 leading-relaxed">
                                @foreach (array_slice($movies['credits']['cast'], 0, 8) as $cast)
                                    @if(! empty($cast['id']))
                                        <a href="{{ route('public.cast.show', ['cast' => $cast['id'], 'slug' => $cast['slug'] ?? null]) }}"
                                           class="inline-block hover:text-[#C5A55A] transition-colors"
                                           title="Lihat profil {{ $cast['name'] }}">{{ $cast['name'] }}</a>@if(!$loop->last),@endif
                                    @else
                                        <span class="inline-block">{{ $cast['name'] }}@if(!$loop->last),@endif</span>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                        @endif

                        @if(count($movies['genres']) > 0)
                        <div class="p-4 rounded-xl" style="background: rgba(20,18,16,0.6); border: 1px solid rgba(197,165,90,0.15)">
                            <div class="text-[10px] uppercase tracking-wider text-[#C5A55A] font-semibold mb-2">Genres</div>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($movies['genres'] as $genre)
                                    <span class="inline-block px-2 py-0.5 text-xs rounded" style="background: rgba(197,165,90,0.1); color: #C5A55A; border: 1px solid rgba(197,165,90,0.25)">{{ $genre['name'] }}</span>
                                @endforeach
                            </div>
                        </div>
                        @endif
                    </aside>
                </div>

                {{-- ━━━ Series episode picker (renders nothing for standalone movies) ━━━
                     Populated by VelflixController::show() which eager-loads
                     seasons.episodes + the current user's per-episode watch_histories. --}}
                <x-series.episode-list :movie="$movieModel" :episodeProgress="$episodeProgress ?? collect()" />

                {{-- ━━━ AI-generated content sections ━━━
                     Each conditional: only renders if data exists. Models load via
                     VelflixController::show() (trivia, quotes, aiReviews relations). --}}
                <x-movies.ai-summary :movie="$movieModel" />
                <x-movies.ai-trivia-strip :movie="$movieModel" />
                <x-movies.ai-quotes :movie="$movieModel" />
                <x-movies.ai-reviews-tabs :movie="$movieModel" />

                {{-- Behind the Scenes (renders nothing if collection empty) --}}
                <x-movies.behind-scenes :sections="$movieModel->behindScenes" />

                {{-- Cinematography / colour analysis (renders nothing without data) --}}
                <x-movies.cinematography :data="$movieModel->cinematography" />

                {{-- AI Soundtrack Analysis (FIX #7) — collapsible card; self-hides
                     when movies.soundtrack_analysis is NULL. Populated by the
                     admin "Generate soundtrack analysis" action → AnalyzeSoundtrack
                     job → SoundtrackAnalyzer. --}}
                <x-movies.soundtrack-analysis :movie="$movieModel" />

                {{-- Highlight Reel CTA — only when at least one ready reel exists --}}
                @if($movieModel->highlightReels()->where('status', 'ready')->exists())
                    <section class="mt-10 md:mt-12">
                        <a href="{{ route('highlight.show', $movieModel) }}"
                           class="group block rounded-2xl overflow-hidden transition-all hover:translate-y-[-2px]"
                           style="background: linear-gradient(135deg, rgba(197,165,90,0.12) 0%, rgba(20,18,16,0.85) 60%); border: 1px solid rgba(197,165,90,0.35)">
                            <div class="flex flex-col md:flex-row items-stretch">
                                <div class="md:w-1/3 relative bg-black">
                                    <img src="{{ $movieModel->backdrop_url }}" alt=""
                                         class="w-full h-40 md:h-full object-cover opacity-70 group-hover:opacity-90 transition-opacity"
                                         onerror="this.style.display='none'">
                                    <div class="absolute inset-0 flex items-center justify-center">
                                        <div class="w-16 h-16 rounded-full flex items-center justify-center shadow-2xl"
                                             style="background: rgba(197,165,90,0.95)">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7 text-black ml-1" fill="currentColor" viewBox="0 0 24 24">
                                                <path d="M8 5v14l11-7z"/>
                                            </svg>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex-1 p-5 md:p-6 flex flex-col justify-center">
                                    <div class="inline-flex items-center gap-2 mb-2">
                                        <span class="text-[10px] uppercase tracking-widest font-semibold px-2 py-0.5 rounded"
                                              style="background: rgba(197,165,90,0.18); color: #C5A55A; border: 1px solid rgba(197,165,90,0.35)">
                                            AI Highlight Reel
                                        </span>
                                        <span class="text-[10px] uppercase tracking-widest font-semibold text-gray-500">~3 menit</span>
                                    </div>
                                    <h3 class="font-heading text-lg md:text-xl font-bold text-white mb-1.5">
                                        Tonton Best-of 3 Menit
                                    </h3>
                                    <p class="text-sm text-gray-400 leading-relaxed">
                                        Adegan-adegan paling dramatis dari film ini, dipilih otomatis oleh AI dan dijahit menjadi satu reel sinematik.
                                    </p>
                                    <div class="mt-3 inline-flex items-center gap-1.5 text-xs font-semibold transition-colors"
                                         style="color: #C5A55A">
                                        <span>Putar Reel</span>
                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 transition-transform group-hover:translate-x-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                                        </svg>
                                    </div>
                                </div>
                            </div>
                        </a>
                    </section>
                @endif

                <!-- Rating Section -->
                <div class="mt-8 p-5 md:p-6 rounded-xl" style="background: linear-gradient(180deg, rgba(20,18,16,0.7) 0%, rgba(15,12,10,0.7) 100%); border: 1px solid rgba(197,165,90,0.15)">
                    <div class="flex items-center gap-2.5 mb-4">
                        <x-icon name="star" :size="18" class="text-[#C5A55A]" />
                        <h3 class="font-heading font-semibold text-white">Beri Rating</h3>
                    </div>
                    <form method="POST" action="{{ route('rating.store') }}">
                        @csrf
                        <input type="hidden" name="movie_id" value="{{ $movies['id'] }}">
                        <div class="flex items-center gap-1 flex-wrap">
                            @for($i = 1; $i <= 10; $i++)
                            <button type="submit" name="score" value="{{ $i }}"
                                    class="w-8 h-8 md:w-9 md:h-9 rounded text-sm font-bold transition-all hover:scale-110"
                                    style="{{ $userRating && $userRating->score >= $i ? 'background: linear-gradient(135deg, #C5A55A, #E8D5A3); color: #000;' : 'background: rgba(255,255,255,0.04); color: #888; border: 1px solid rgba(197,165,90,0.15);' }}">
                                {{ $i }}
                            </button>
                            @endfor
                        </div>
                        @if($userRating)
                        <p class="text-xs text-gray-500 mt-3">Rating kamu: <strong style="color: #C5A55A">{{ $userRating->score }}/10</strong></p>
                        @endif
                    </form>
                </div>

                <!-- Comments Section -->
                <div class="mt-8">
                    @php
                        // Sort options for the comment list. The controller
                        // (VelflixController::show) accepts ?sort=newest|oldest|top
                        // — anything else falls back to newest.
                        $commentSort = in_array(request('sort'), ['newest', 'oldest', 'top'], true)
                            ? request('sort')
                            : 'newest';
                        $sortLabels = [
                            'newest' => 'Terbaru',
                            'oldest' => 'Terlama',
                            'top' => 'Top Reactions',
                        ];
                    @endphp
                    <div class="flex items-center justify-between mb-5 flex-wrap gap-3">
                        <div class="flex items-center gap-2.5">
                            <x-icon name="user" :size="18" class="text-[#C5A55A]" />
                            <h3 class="font-heading font-semibold text-white">Komentar</h3>
                            <span class="text-xs px-2 py-0.5 rounded-full" style="background: rgba(197,165,90,0.1); color: #C5A55A">{{ $comments->count() }}</span>
                        </div>
                        {{-- ━━━ Sort selector ━━━
                             GET form so the choice survives a hard refresh /
                             share-link. JS auto-submits on change for a snappy
                             feel without an "Apply" button. --}}
                        <form method="GET" class="flex items-center gap-2 text-xs">
                            <label for="comment-sort" class="text-gray-500">Sort:</label>
                            <select id="comment-sort" name="sort" onchange="this.form.submit()"
                                    class="bg-[rgba(20,18,16,0.6)] text-gray-300 rounded px-2 py-1 focus:outline-none focus:border-[#C5A55A]"
                                    style="border: 1px solid rgba(197,165,90,0.25)">
                                @foreach($sortLabels as $key => $label)
                                    <option value="{{ $key }}" @selected($commentSort === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                            {{-- Preserve any other querystring params (e.g. anchors) --}}
                            @foreach(request()->except(['sort', 'page']) as $k => $v)
                                @if(is_scalar($v))
                                    <input type="hidden" name="{{ $k }}" value="{{ $v }}">
                                @endif
                            @endforeach
                        </form>
                    </div>

                    <!-- Comment Form -->
                    @php
                        // Mirror CommentController::requiresCaptcha() so the
                        // widget only renders for new accounts that have already
                        // posted >= 3 comments in the past hour. Established
                        // users see no friction at all.
                        $u = auth()->user();
                        $needsCommentCaptcha = false;
                        if ($u && $u->created_at && $u->created_at->gte(now()->subDay())) {
                            $recent = \App\Models\Comment::query()
                                ->where('user_id', $u->getAuthIdentifier())
                                ->where('created_at', '>=', now()->subHour())
                                ->count();
                            $needsCommentCaptcha = $recent >= 3;
                        }
                    @endphp
                    <form method="POST" action="{{ route('comment.store') }}" class="mb-6">
                        @csrf
                        <input type="hidden" name="movie_id" value="{{ $movies['id'] }}">
                        <textarea name="body" rows="3"
                                  class="w-full p-4 rounded-xl text-sm text-white placeholder-gray-500 resize-none focus:outline-none focus:border-[#C5A55A] transition-colors"
                                  style="background: rgba(20,18,16,0.6); border: 1px solid rgba(197,165,90,0.15)"
                                  placeholder="Tulis komentar..." required></textarea>
                        @if ($needsCommentCaptcha)
                            {{-- Cloudflare Turnstile CAPTCHA (no-op when env keys absent). --}}
                            <x-captcha-turnstile action="comment" theme="dark" />
                        @endif
                        <div class="flex items-center justify-between mt-2.5">
                            <label class="flex items-center gap-2 text-xs text-gray-500 cursor-pointer">
                                <input type="checkbox" name="is_spoiler" value="1" class="rounded">
                                <span class="inline-flex items-center gap-1">
                                    <x-icon name="eye" :size="12" class="text-[#C5A55A]/70" />
                                    Mengandung spoiler
                                </span>
                            </label>
                            <button type="submit" class="px-4 py-2 rounded-lg text-xs font-bold text-black hover:opacity-95 transition-opacity" style="background: linear-gradient(135deg, #C5A55A, #E8D5A3)">
                                Kirim
                            </button>
                        </div>
                    </form>

                    <!-- Comment List -->
                    <div class="space-y-3">
                        @forelse($comments as $comment)
                        <div class="p-4 rounded-xl" style="background: rgba(20,18,16,0.5); border: 1px solid rgba(197,165,90,0.1)">
                            <div class="flex items-center gap-2.5 mb-2">
                                <div class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold text-black" style="background: linear-gradient(135deg, #C5A55A, #E8D5A3)">
                                    {{ strtoupper(substr($comment->user->name, 0, 1)) }}
                                </div>
                                <span class="text-sm font-medium text-white">{{ $comment->user->name }}</span>
                                <span class="text-xs text-gray-500">{{ $comment->created_at->diffForHumans() }}</span>
                                @if($comment->is_spoiler)
                                    <span class="text-[10px] px-2 py-0.5 rounded uppercase tracking-wider font-semibold inline-flex items-center gap-1" style="background: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid rgba(239,68,68,0.3)">
                                        <span>Spoiler</span>
                                        @if(!is_null($comment->spoiler_confidence) && (float) $comment->spoiler_confidence >= 0.7)
                                            <span class="text-[9px] opacity-80" title="Terdeteksi otomatis oleh AI (confidence {{ number_format((float) $comment->spoiler_confidence * 100, 0) }}%)">AI</span>
                                        @endif
                                    </span>
                                @endif
                            </div>
                            @if($comment->is_spoiler)
                                <div x-data="{ shown: false }" class="relative">
                                    <p
                                        class="text-sm text-gray-300 transition-[filter,opacity] duration-200 select-none"
                                        :style="shown ? 'filter: none; pointer-events: auto;' : 'filter: blur(8px); pointer-events: none;'"
                                    >{{ $comment->body }}</p>

                                    <button
                                        type="button"
                                        x-show="!shown"
                                        @click="shown = true"
                                        class="absolute inset-0 flex items-center justify-center text-xs font-semibold rounded-lg cursor-pointer transition-colors"
                                        style="background: rgba(20,18,16,0.55); color: #C5A55A; border: 1px solid rgba(197,165,90,0.35); backdrop-filter: blur(2px);"
                                    >
                                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5">
                                            <x-icon name="eye" :size="14" class="text-[#C5A55A]" />
                                            <span>&#9888; Spoiler &mdash; Klik untuk lihat</span>
                                        </span>
                                    </button>

                                    <button
                                        type="button"
                                        x-show="shown"
                                        x-cloak
                                        @click="shown = false"
                                        class="mt-1 text-[11px] text-[#C5A55A]/80 hover:text-[#C5A55A] transition-colors inline-flex items-center gap-1"
                                    >
                                        <x-icon name="eye" :size="12" class="text-[#C5A55A]/80" />
                                        <span>Sembunyikan lagi</span>
                                    </button>
                                </div>
                            @else
                                <p class="text-sm text-gray-300">{{ $comment->body }}</p>
                            @endif

                            {{-- ━━━ Reaction pill bar ━━━
                                 Auth-gated (no-op render for guests; the toggle
                                 endpoint itself is also auth-protected).
                                 Reactions array literal must mirror
                                 \App\Models\CommentReaction::REACTIONS + EMOJI. --}}
                            @auth
                                <x-comments.reaction-bar :comment="$comment" :movieId="$movies['id']" />
                            @endauth

                            @if($comment->user_id === auth()->id() || auth()->user()?->isStaff())
                            <form method="POST" action="{{ route('comment.destroy', $comment) }}" class="mt-2">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-xs text-red-500/80 hover:text-red-400 transition-colors">Hapus</button>
                            </form>
                            @endif

                            <!-- Replies -->
                            @if($comment->replies->count())
                            <div class="mt-3 ml-5 space-y-2.5 pl-3" style="border-left: 2px solid rgba(197,165,90,0.2)">
                                @foreach($comment->replies as $reply)
                                <div>
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="text-xs font-medium text-gray-400">{{ $reply->user->name }}</span>
                                        <span class="text-xs text-gray-600">{{ $reply->created_at->diffForHumans() }}</span>
                                    </div>
                                    <p class="text-xs text-gray-400">{{ $reply->body }}</p>
                                    {{-- Reaction bar on replies too — same pattern. --}}
                                    @auth
                                        <x-comments.reaction-bar :comment="$reply" :movieId="$movies['id']" />
                                    @endauth
                                </div>
                                @endforeach
                            </div>
                            @endif
                        </div>
                        @empty
                        <p class="text-center text-gray-600 py-8 text-sm">Belum ada komentar. Jadilah yang pertama!</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-layout>
