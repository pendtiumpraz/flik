@php
    $seoTitle = trim(
        $movie?->title . ' — S' . ($season?->season_number ?? '?') . 'E' . $episode->episode_number
        . ': ' . $episode->title . ' — FLiK'
    );
    $seoDescription = \Illuminate\Support\Str::limit(
        $episode->generated_summary ?: $episode->overview ?: ($movie?->overview ?? 'Tonton di FLiK.'),
        160,
        ''
    );
    $seoOgImage = $episode->still_url ?: $movie?->backdrop_url ?: $movie?->poster_url;

    // Pick the best playable URL — episode video first, fall back to
    // parent series video for early-stage data where episodes share
    // the master file.
    $videoSrc = $episode->video_path
        ? (str_starts_with($episode->video_path, 'http') ? $episode->video_path : asset('storage/' . $episode->video_path))
        : ($movie?->video_full_url ?? null);

    $youtubeKey = $movie?->youtube_key;
@endphp

<x-layout :title="$seoTitle" :description="$seoDescription" :ogImage="$seoOgImage">
    <div class="min-h-screen bg-black pt-16">
        {{-- Hero / Player ------------------------------------------------ --}}
        <div class="relative w-full bg-black">
            <div class="absolute inset-0 z-0">
                @if($seoOgImage)
                    <img src="{{ $seoOgImage }}" alt=""
                         class="w-full h-full object-cover opacity-25 blur-xl"
                         onerror="this.style.display='none'">
                @endif
                <div class="absolute inset-0" style="background: linear-gradient(180deg, rgba(0,0,0,0.4) 0%, rgba(0,0,0,0.85) 50%, #000 100%)"></div>
            </div>

            <div class="relative z-10 container mx-auto px-4 md:px-8 lg:px-16 max-w-6xl py-6 md:py-10">
                {{-- Breadcrumb back to series ----------------------------- --}}
                <nav class="text-xs md:text-sm text-gray-400 mb-4 flex items-center flex-wrap gap-1.5">
                    <a href="{{ route('movies.show', $movie) }}" class="hover:text-[#C5A55A] transition-colors">
                        {{ $movie?->title }}
                    </a>
                    <span class="text-gray-600">/</span>
                    <span>Season {{ $season?->season_number }}</span>
                    <span class="text-gray-600">/</span>
                    <span class="text-white">Ep {{ $episode->episode_number }}</span>
                </nav>

                {{-- Player ------------------------------------------------ --}}
                <div class="rounded-2xl overflow-hidden shadow-2xl"
                     style="border: 1px solid rgba(197,165,90,0.2)"
                     x-data="episodePlayer({
                            episodeId: {{ (int) $episode->id }},
                            movieId: {{ (int) $movie?->id }},
                            resumeAt: {{ (int) $resumeAt }},
                            durationHint: {{ (int) ($episode->duration_seconds ?? 0) }},
                            outroStart: {{ (int) ($episode->outro_start_seconds ?? 0) }},
                            nextUrl: {{ $next ? "'" . route('episodes.watch', $next) . "'" : 'null' }},
                            progressUrl: '{{ route('watch.progress') }}',
                            csrf: document.querySelector('meta[name=csrf-token]')?.content || '',
                       })">
                    <div class="relative bg-black" style="padding-top: 56.25%">
                        @if($videoSrc)
                            <link href="https://vjs.zencdn.net/8.10.0/video-js.css" rel="stylesheet">
                            <video id="flik-episode-player"
                                   class="video-js vjs-fluid vjs-big-play-centered absolute top-0 left-0 w-full h-full"
                                   controls preload="auto"
                                   x-ref="player"
                                   poster="{{ $seoOgImage }}"
                                   data-setup='{"playbackRates": [0.5, 1, 1.25, 1.5, 2]}'>
                                <source src="{{ $videoSrc }}" type="video/mp4">
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
                            </style>
                        @elseif($youtubeKey)
                            <iframe class="absolute inset-0 w-full h-full"
                                    src="https://www.youtube.com/embed/{{ $youtubeKey }}"
                                    style="border:0;" allow="autoplay; encrypted-media" allowfullscreen></iframe>
                        @else
                            <div class="absolute inset-0 flex items-center justify-center bg-[#0a0a0a]">
                                <div class="text-center text-gray-500">
                                    <x-icon name="play" :size="48" class="mx-auto mb-3 text-gray-700" />
                                    <span class="text-sm">Episode belum punya file video.</span>
                                </div>
                            </div>
                        @endif

                        {{-- Auto-next overlay (10s countdown near end of episode) ----------------- --}}
                        @if($next)
                            <div x-show="showAutoNext" x-cloak
                                 x-transition.opacity
                                 class="absolute bottom-16 right-4 md:right-8 z-20 rounded-xl p-4 max-w-xs"
                                 style="background: rgba(15,12,10,0.95); border: 1px solid rgba(197,165,90,0.45); backdrop-filter: blur(8px);">
                                <div class="text-[10px] uppercase tracking-widest mb-1.5" style="color: #C5A55A">Next Episode</div>
                                <div class="text-sm font-semibold text-white leading-snug">
                                    Ep {{ $next->episode_number }}: {{ $next->title }}
                                </div>
                                <div class="text-xs text-gray-400 mt-1">
                                    Mulai dalam <span class="font-bold" style="color: #C5A55A" x-text="countdown"></span>s
                                </div>
                                <div class="flex gap-2 mt-3">
                                    <a :href="nextUrl"
                                       class="px-3 py-1.5 rounded text-xs font-bold text-black hover:opacity-95 transition-opacity"
                                       style="background: linear-gradient(135deg, #C5A55A, #E8D5A3)">
                                        Tonton sekarang
                                    </a>
                                    <button type="button" @click="cancelAutoNext()"
                                            class="px-3 py-1.5 rounded text-xs font-medium text-gray-300 transition-colors hover:text-white"
                                            style="background: rgba(255,255,255,0.05); border: 1px solid rgba(197,165,90,0.2)">
                                        Batal
                                    </button>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Title bar + Prev/Next ----------------------------------------------- --}}
                <div class="mt-5 md:mt-6 flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-baseline gap-2 mb-1">
                            <span class="text-[10px] uppercase tracking-widest font-bold" style="color: #C5A55A">
                                S{{ $season?->season_number }} · Episode {{ $episode->episode_number }}
                            </span>
                            @if($episode->runtime_minutes)
                                <span class="text-xs text-gray-500">· {{ $episode->runtime_minutes }} min</span>
                            @endif
                        </div>
                        <h1 class="font-heading text-xl md:text-2xl font-bold text-white leading-tight">
                            {{ $episode->title }}
                        </h1>
                    </div>
                    <div class="flex items-center gap-2">
                        @if($previous)
                            <a href="{{ route('episodes.watch', $previous) }}"
                               class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-medium text-gray-300 transition-colors hover:text-white"
                               style="background: rgba(20,18,16,0.6); border: 1px solid rgba(197,165,90,0.2)">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>
                                Prev
                            </a>
                        @endif
                        @if($next)
                            <a href="{{ route('episodes.watch', $next) }}"
                               class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-bold text-black transition-opacity hover:opacity-95"
                               style="background: linear-gradient(135deg, #C5A55A, #E8D5A3)">
                                Next Episode
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 6l6 6-6 6"/></svg>
                            </a>
                        @endif
                    </div>
                </div>

                {{-- Blurb ------------------------------------------------- --}}
                @if($episode->generated_summary || $episode->overview)
                    <p class="mt-4 text-sm md:text-base text-gray-300 leading-relaxed">
                        {{ $episode->generated_summary ?: $episode->overview }}
                    </p>
                @endif
            </div>
        </div>

        {{-- All episodes of the series at the bottom for fast hopping ----- --}}
        <div class="container mx-auto px-4 md:px-8 lg:px-16 max-w-6xl py-8">
            <x-series.episode-list :movie="$movie" />
        </div>
    </div>

    <script>
        // Alpine component handling: heartbeat progress + auto-next overlay.
        // Heartbeat fires every 15s while playing — the WatchHistoryController
        // upserts on (user_id, movie_id, episode_id) so duplicates are harmless.
        function episodePlayer(cfg) {
            return {
                showAutoNext: false,
                countdown: 10,
                _countdownTimer: null,
                _heartbeatTimer: null,
                _videoEl: null,
                _userCancelled: false,
                nextUrl: cfg.nextUrl,
                init() {
                    // Wait for Video.js to wire up the player. Use the raw
                    // <video> element so progress events work even if the
                    // VJS wrapper is missing (e.g. iframe-only fallback).
                    this._videoEl = this.$refs.player || document.getElementById('flik-episode-player');
                    if (!this._videoEl) return;

                    // Resume from last position.
                    const seek = () => {
                        if (cfg.resumeAt > 0 && this._videoEl.duration && cfg.resumeAt < this._videoEl.duration - 20) {
                            try { this._videoEl.currentTime = cfg.resumeAt; } catch (e) {}
                        }
                    };
                    this._videoEl.addEventListener('loadedmetadata', seek, { once: true });

                    // 15s heartbeat — round-trip kept tight to keep "Continue
                    // Watching" accurate without spamming the DB.
                    this._heartbeatTimer = setInterval(() => this.heartbeat(), 15000);

                    // Auto-next trigger:
                    //  - explicit outroStart marker if set, OR
                    //  - 30s before the end as a default heuristic.
                    this._videoEl.addEventListener('timeupdate', () => this.checkAutoNext());

                    // Fire one last heartbeat when the user navigates away.
                    window.addEventListener('beforeunload', () => this.heartbeat());
                },
                heartbeat() {
                    if (!this._videoEl || !cfg.progressUrl) return;
                    const current = this._videoEl.currentTime || 0;
                    const duration = this._videoEl.duration || cfg.durationHint || 0;
                    if (current < 1 || duration < 1) return;

                    fetch(cfg.progressUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': cfg.csrf,
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({
                            movie_id: cfg.movieId,
                            episode_id: cfg.episodeId,
                            current_time: current,
                            duration: duration,
                        }),
                        keepalive: true,
                    }).catch(() => {});
                },
                checkAutoNext() {
                    if (!this.nextUrl || this._userCancelled || this.showAutoNext) return;
                    if (!this._videoEl) return;
                    const duration = this._videoEl.duration || 0;
                    const current = this._videoEl.currentTime || 0;
                    if (duration < 1) return;

                    const outroAt = cfg.outroStart > 0 ? cfg.outroStart : Math.max(0, duration - 30);
                    if (current >= outroAt) this.startAutoNext();
                },
                startAutoNext() {
                    this.showAutoNext = true;
                    this.countdown = 10;
                    this._countdownTimer = setInterval(() => {
                        this.countdown -= 1;
                        if (this.countdown <= 0) {
                            clearInterval(this._countdownTimer);
                            window.location.href = this.nextUrl;
                        }
                    }, 1000);
                },
                cancelAutoNext() {
                    this._userCancelled = true;
                    this.showAutoNext = false;
                    if (this._countdownTimer) clearInterval(this._countdownTimer);
                },
            };
        }
    </script>
</x-layout>
