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

    // DRM-first: only render the player when EITHER the controller minted a
    // DRM bundle (encoded HLS available + concurrent-stream slot acquired)
    // OR the parent movie still has an encoded video_path AND admins have
    // explicitly enabled the unencrypted episode fallback. See
    // docs/audit/04-drm-playback.md FIX #2 §5 — previously this view served
    // the raw mp4 with no DRM, leaking the master file for every series.
    $hasHls = !empty($drmBundle);
    $allowRawFallback = (bool) config('drm.allow_episode_raw_mp4', false);
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
                        @if($hasHls)
                            @php
                                $episodeSubs = $episode->activeSubtitles->map(fn ($s) => [
                                    'url'      => route('playback.episode-subtitle', ['episode' => $episode, 'subtitle' => $s->id]),
                                    'language' => $s->language_code,
                                    'label'    => $s->native_name,
                                    'default'  => (bool) $s->is_default,
                                ])->values();
                            @endphp
                            {{-- ━━━ Shaka Player (DRM/HLS pipeline) ━━━ --}}
                            <video id="flik-episode-shaka-player"
                                   class="absolute top-0 left-0 w-full h-full bg-black"
                                   controls preload="auto"
                                   x-ref="player"
                                   poster="{{ $seoOgImage }}"
                                   data-manifest-url="{{ $drmBundle['manifest_url'] }}"
                                   data-session-token="{{ $drmBundle['session_token'] }}"
                                   data-heartbeat-url="{{ $drmBundle['heartbeat_url'] }}"></video>
                            <div
                                x-data="{
                                    player: null,
                                    error: null,
                                    subs: @js($episodeSubs),
                                    async init() {
                                        await this.waitForShaka();
                                        const videoEl = document.getElementById('flik-episode-shaka-player');
                                        if (!videoEl || typeof window.FlikPlayer !== 'function') return;
                                        try {
                                            this.player = new window.FlikPlayer(
                                                'flik-episode-shaka-player',
                                                '{{ $movie?->slug }}',
                                                { subtitles: @js($episodeSubs) }
                                            );
                                            await this.player.initialize();
                                        } catch (e) {
                                            console.error('[FLiK] episode player init failed', e);
                                            this.error = e.message || 'Playback unavailable';
                                        }
                                    },
                                    onSubChange(e) {
                                        const lang = e.target.value;
                                        if (!this.player) return;
                                        if (!lang) { this.player.disableText(); }
                                        else { this.player.selectTextLanguage(lang); }
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
                                }"
                                x-init="init()"
                                @beforeunload.window="player?.destroy();"
                            >
                                <template x-if="error">
                                    <div class="absolute inset-0 flex items-center justify-center bg-[#0a0a0a]/90 z-30 pointer-events-none">
                                        <div class="text-center text-gray-400 px-4">
                                            <div class="text-sm" x-text="error"></div>
                                        </div>
                                    </div>
                                </template>
                                {{-- Subtitle (CC) picker — episode Shaka path --}}
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
                            {{-- Inline fingerprint script so the heartbeat
                                 endpoint can validate device claims. --}}
                            <script>{!! $drmBundle['fingerprint_script'] !!}</script>
                        @elseif($youtubeKey)
                            <iframe class="absolute inset-0 w-full h-full"
                                    src="https://www.youtube.com/embed/{{ $youtubeKey }}"
                                    style="border:0;" allow="autoplay; encrypted-media" allowfullscreen></iframe>
                        @else
                            {{-- DRM safety net: refuse to serve unencrypted master.
                                 Per audit FIX #2 §5 the previous raw-mp4 path
                                 leaked the master file for every series. --}}
                            <div class="absolute inset-0 flex items-center justify-center bg-[#0a0a0a]">
                                <div class="text-center text-gray-500 max-w-md px-6">
                                    <x-icon name="play" :size="48" class="mx-auto mb-3 text-gray-700" />
                                    <p class="text-sm text-gray-300 font-medium">Episode belum di-encode untuk diputar</p>
                                    <p class="text-xs text-gray-500 mt-2 leading-relaxed">
                                        Untuk keamanan konten, hanya episode yang telah selesai melalui pipeline transcoding/HLS yang dapat diputar.
                                        Cek kembali sebentar lagi atau hubungi admin jika ini berlangsung lama.
                                    </p>
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
