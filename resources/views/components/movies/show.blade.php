<x-layout>
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
                    <div class="relative bg-black" style="padding-top: 56.25%">
                        @if($movieModel->video_path)
                            <link href="https://vjs.zencdn.net/8.10.0/video-js.css" rel="stylesheet">
                            <video id="flik-player" class="video-js vjs-fluid vjs-big-play-centered absolute top-0 left-0 w-full h-full"
                                controls preload="auto"
                                poster="{{ $movies['backdrop_path'] }}"
                                data-setup='{"playbackRates": [0.5, 1, 1.25, 1.5, 2]}'>
                                <source src="{{ $movieModel->video_full_url }}" type="video/mp4">
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
                            <button class="inline-flex items-center gap-2 px-5 py-2.5 rounded-md text-sm font-bold text-black hover:opacity-95 transition-opacity" style="background: linear-gradient(135deg, #C5A55A, #E8D5A3)">
                                <x-icon name="play-solid" :size="14" /> Play
                            </button>

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
                                    <span class="inline-block">{{ $cast['name'] }}@if(!$loop->last),@endif</span>
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
                    <div class="flex items-center justify-between mb-5">
                        <div class="flex items-center gap-2.5">
                            <x-icon name="user" :size="18" class="text-[#C5A55A]" />
                            <h3 class="font-heading font-semibold text-white">Komentar</h3>
                            <span class="text-xs px-2 py-0.5 rounded-full" style="background: rgba(197,165,90,0.1); color: #C5A55A">{{ $comments->count() }}</span>
                        </div>
                    </div>

                    <!-- Comment Form -->
                    <form method="POST" action="{{ route('comment.store') }}" class="mb-6">
                        @csrf
                        <input type="hidden" name="movie_id" value="{{ $movies['id'] }}">
                        <textarea name="body" rows="3"
                                  class="w-full p-4 rounded-xl text-sm text-white placeholder-gray-500 resize-none focus:outline-none focus:border-[#C5A55A] transition-colors"
                                  style="background: rgba(20,18,16,0.6); border: 1px solid rgba(197,165,90,0.15)"
                                  placeholder="Tulis komentar..." required></textarea>
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
                                    <span class="text-[10px] px-2 py-0.5 rounded uppercase tracking-wider font-semibold" style="background: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid rgba(239,68,68,0.3)">Spoiler</span>
                                @endif
                            </div>
                            @if($comment->is_spoiler)
                                <div x-data="{ show: false }">
                                    <p x-show="!show" class="text-sm text-gray-500 cursor-pointer italic hover:text-gray-400 inline-flex items-center gap-1.5" @click="show = true">
                                        <x-icon name="eye" :size="14" class="text-[#C5A55A]/70" />
                                        Klik untuk lihat spoiler
                                    </p>
                                    <p x-show="show" x-cloak class="text-sm text-gray-300">{{ $comment->body }}</p>
                                </div>
                            @else
                                <p class="text-sm text-gray-300">{{ $comment->body }}</p>
                            @endif

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
