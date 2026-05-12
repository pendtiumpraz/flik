<x-layout>
    <div class="min-h-screen bg-black pt-16">
        {{-- Hero / Player Section --}}
        <div class="relative w-full bg-black">
            {{-- Backdrop bg --}}
            <div class="absolute inset-0 z-0">
                <img src="{{ $movie->backdrop_url }}" alt=""
                     class="w-full h-full object-cover opacity-30 blur-xl"
                     onerror="this.style.display='none'">
                <div class="absolute inset-0" style="background: linear-gradient(180deg, rgba(0,0,0,0.4) 0%, rgba(0,0,0,0.85) 50%, #000 100%)"></div>
            </div>

            <div class="relative z-10 container mx-auto px-4 md:px-8 lg:px-16 max-w-6xl py-8 md:py-12">

                {{-- Title strip --}}
                <div class="mb-6 md:mb-8">
                    <div class="inline-flex items-center gap-2 mb-2 px-3 py-1 rounded-full text-[11px] uppercase tracking-wider font-semibold"
                         style="background: rgba(197,165,90,0.15); color: #C5A55A; border: 1px solid rgba(197,165,90,0.35)">
                        Highlight Reel · 3-Minute Recap
                    </div>
                    <h1 class="font-heading text-2xl md:text-4xl font-bold text-white leading-tight">
                        {{ $movie->title }}
                    </h1>
                    <p class="mt-1 text-sm text-gray-400">
                        Auto-generated cinematic best-of, picked by AI from the dramatic peaks of the film.
                    </p>
                </div>

                {{-- Player --}}
                <div class="rounded-2xl overflow-hidden shadow-2xl" style="border: 1px solid rgba(197,165,90,0.25)">
                    <div class="relative bg-black" style="padding-top: 56.25%">
                        @if($isReady && $reelUrl)
                            <link href="https://vjs.zencdn.net/8.10.0/video-js.css" rel="stylesheet">
                            <video id="flik-highlight-player"
                                   class="video-js vjs-fluid vjs-big-play-centered absolute top-0 left-0 w-full h-full"
                                   controls preload="auto"
                                   poster="{{ $movie->backdrop_url }}"
                                   data-setup='{"playbackRates": [0.5, 1, 1.25, 1.5, 2]}'>
                                <source src="{{ $reelUrl }}" type="video/mp4">
                                Your browser does not support embedded video playback.
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
                        @else
                            <div class="absolute inset-0 flex items-center justify-center bg-[#0a0a0a]">
                                <div class="text-center text-gray-400 max-w-md px-6">
                                    <div class="mx-auto mb-4 w-16 h-16 rounded-full flex items-center justify-center"
                                         style="background: rgba(197,165,90,0.1); border: 1px solid rgba(197,165,90,0.3)">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7 text-[#C5A55A]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                                        </svg>
                                    </div>
                                    @if($reel && $reel->status === 'processing')
                                        <h2 class="font-heading text-lg font-semibold text-white mb-1">Reel sedang diproses</h2>
                                        <p class="text-sm">Coba refresh halaman ini dalam beberapa menit.</p>
                                    @elseif($reel && $reel->status === 'failed')
                                        <h2 class="font-heading text-lg font-semibold text-white mb-1">Reel belum bisa dibuat</h2>
                                        <p class="text-sm text-gray-500">{{ $errorMessage ?: 'AI tidak menemukan cukup adegan untuk reel ini.' }}</p>
                                    @else
                                        <h2 class="font-heading text-lg font-semibold text-white mb-1">Reel belum tersedia</h2>
                                        <p class="text-sm text-gray-500">Highlight reel untuk film ini belum di-generate.</p>
                                    @endif
                                </div>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Action bar --}}
                <div class="mt-6 flex flex-wrap items-center gap-3">
                    <a href="{{ route('movies.show', $movie->slug) }}"
                       class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-semibold transition"
                       style="background: rgba(255,255,255,0.05); color: #fff; border: 1px solid rgba(255,255,255,0.12)">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                        </svg>
                        Watch Full Film
                    </a>

                    @auth
                        @if($isReady)
                            <a href="{{ url('/movie/' . $movie->slug . '/highlight/download') }}"
                               class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-semibold transition"
                               style="background: #C5A55A; color: #0a0a0a">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"/>
                                </svg>
                                Download MP4
                            </a>
                        @endif
                    @endauth
                </div>

                {{-- Scene breakdown --}}
                @if($reel && !empty($reel->scenes_json))
                    <div class="mt-10">
                        <h3 class="font-heading text-lg font-semibold text-white mb-4 flex items-center gap-2">
                            <span class="inline-block w-1.5 h-5 rounded-sm" style="background: #C5A55A"></span>
                            Scenes in this reel
                            <span class="ml-2 text-xs text-gray-500 font-normal">
                                {{ $reel->scene_count }} clips · ~{{ (int) round($reel->actual_duration_seconds) }}s total
                            </span>
                        </h3>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            @foreach($reel->scenes_json as $i => $scene)
                                @php
                                    $start = (float) ($scene['start'] ?? 0);
                                    $end   = (float) ($scene['end'] ?? 0);
                                    $score = (float) ($scene['score'] ?? 0);
                                    $reason = (string) ($scene['reason'] ?? '');
                                    $tc = function (float $s) {
                                        $h = (int) floor($s / 3600);
                                        $m = (int) floor(fmod($s, 3600) / 60);
                                        $sec = (int) floor(fmod($s, 60));
                                        return sprintf('%02d:%02d:%02d', $h, $m, $sec);
                                    };
                                @endphp
                                <div class="rounded-xl p-4 transition hover:translate-x-0.5"
                                     style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08)">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="flex items-center gap-3">
                                            <div class="flex-shrink-0 w-9 h-9 rounded-lg flex items-center justify-center font-bold text-sm"
                                                 style="background: rgba(197,165,90,0.15); color: #C5A55A; border: 1px solid rgba(197,165,90,0.3)">
                                                {{ $i + 1 }}
                                            </div>
                                            <div class="font-mono text-xs text-gray-300">
                                                {{ $tc($start) }} → {{ $tc($end) }}
                                            </div>
                                        </div>
                                        <div class="text-xs font-semibold" style="color: #C5A55A">
                                            {{ number_format($score, 1) }}/10
                                        </div>
                                    </div>
                                    @if($reason !== '')
                                        <p class="mt-2 text-xs text-gray-400 leading-relaxed">{{ $reason }}</p>
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        @if($reel->generated_at)
                            <p class="mt-6 text-xs text-gray-600">
                                Generated {{ $reel->generated_at->diffForHumans() }}.
                            </p>
                        @endif
                    </div>
                @endif

            </div>
        </div>
    </div>
</x-layout>
