
<x-layout>
    <div class="flex h-full w-full items-center overflow-y-auto pt-14 md:pt-4 shadow-lg">
        <div class="container mx-auto overflow-y-auto rounded-lg px-4 md:px-16 lg:px-56">
            <div class="rounded-xl bg-gray-800">
                <div class="responsive-container video-player-container relative overflow-hidden" style="padding-top: 56.25%">
                @if($movieModel->video_path)
                    {{-- Video.js Player for uploaded videos --}}
                    <link href="https://vjs.zencdn.net/8.10.0/video-js.css" rel="stylesheet">
                    <video id="flik-player" class="video-js vjs-fluid vjs-big-play-centered absolute top-0 left-0 w-full h-full rounded-t-xl"
                        controls preload="auto"
                        poster="{{ $movies['backdrop_path'] }}"
                        data-setup='{"playbackRates": [0.5, 1, 1.25, 1.5, 2]}'>
                        <source src="{{ $movieModel->video_full_url }}" type="video/mp4">
                        <p class="vjs-no-js">Browser tidak mendukung video. <a href="{{ $movieModel->video_full_url }}">Download video</a>.</p>
                    </video>
                    <script src="https://vjs.zencdn.net/8.10.0/video.min.js"></script>
                    <style>
                        /* FLiK Gold Skin for Video.js */
                        .video-js .vjs-play-progress { background: #C5A55A; }
                        .video-js .vjs-volume-level { background: #C5A55A; }
                        .video-js .vjs-big-play-button {
                            background: rgba(197,165,90,0.85);
                            border: none; border-radius: 50%;
                            width: 64px; height: 64px; line-height: 64px;
                            font-size: 28px; margin-top: -32px; margin-left: -32px;
                        }
                        .video-js .vjs-big-play-button:hover { background: #C5A55A; }
                        .video-js .vjs-control-bar { background: rgba(0,0,0,0.85); }
                        .video-js .vjs-slider { background: rgba(255,255,255,0.15); }
                    </style>
                @elseif (!empty($movies['videos']['results']))
                    <iframe class="responsive-iframe absolute top-0 left-0 h-full w-full rounded-t-xl"
                        src="https://www.youtube.com/embed/{{ $movies['videos']['results'][0]['key'] }}"
                        style="border:0;" allow="autoplay; encrypted-media" allowfullscreen>
                    </iframe>
                @else
                    <div class="responsive-iframe absolute top-0 left-0 flex h-full w-full items-center justify-center rounded-t-xl bg-gray-900 text-gray-400">
                        <div class="text-center">
                            <svg class="w-12 h-12 mx-auto mb-2 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <span class="text-sm">Video belum tersedia</span>
                        </div>
                    </div>
                @endif
                </div>

                <div class="modal-body px-4 md:px-8 py-3">
                    <div class="responsive-container relative overflow-hidden text-white">
                        <div class="my-4 flex w-full flex-row gap-3 flex-wrap items-center">
                            <button class="bg-gradient flex items-center justify-center gap-2 rounded bg-white px-4 md:px-6 py-2 shadow-md">
                                <svg class="h-5 w-5 md:h-6 md:w-6 text-black" fill="currentColor" viewBox="0 0 16 16"><path d="m12.14 8.753-5.482 4.796c-.646.566-1.658.106-1.658-.753V3.204a1 1 0 0 1 1.659-.753l5.48 4.796a1 1 0 0 1 0 1.506z"/></svg>
                                <span class="font-semibold text-black text-sm md:text-base">Play</span>
                            </button>

                            <!-- Watchlist Toggle -->
                            <form method="POST" action="{{ route('watchlist.toggle') }}">
                                @csrf
                                <input type="hidden" name="movie_id" value="{{ $movies['id'] }}">
                                <button type="submit" class="flex h-8 w-8 md:h-10 md:w-10 items-center justify-center rounded-full ring-2 transition-colors {{ $inWatchlist ? 'ring-yellow-500 bg-yellow-500/20' : 'ring-gray-400 hover:ring-white' }}" title="{{ $inWatchlist ? 'Hapus dari watchlist' : 'Tambah ke watchlist' }}">
                                    @if($inWatchlist)
                                        <svg class="h-4 w-4" fill="#C5A55A" viewBox="0 0 24 24"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
                                    @else
                                        <svg class="h-4 w-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
                                    @endif
                                </button>
                            </form>

                            <!-- Community Rating -->
                            @if($ratingsCount > 0)
                            <div class="flex items-center gap-2 ml-auto">
                                <span class="text-xs text-gray-500">Community:</span>
                                <span class="text-sm font-bold" style="color:#C5A55A">★ {{ $avgRating }}</span>
                                <span class="text-xs text-gray-600">({{ $ratingsCount }})</span>
                            </div>
                            @endif
                        </div>

                        <div class="my-6 flex flex-col md:flex-row gap-6">
                            <div class="w-full md:w-4/6">
                                <span class="mb-3 flex flex-col md:flex-row gap-3 md:gap-4 md:items-center">
                                    <div class="flex items-center gap-3">
                                        <div id="vote_average" class="relative h-14 w-14 md:h-16 md:w-16 rounded-full bg-gray-900 flex items-center justify-center text-white">
                                            <span class="font-bold text-green-500 text-sm md:text-base">{{ $movies['vote_average'] * 10 . '%' }}</span>
                                        </div>
                                        <div>
                                            <div class="font-heading font-semibold text-white text-lg md:text-xl">{{ $movies['original_title'] }}</div>
                                            <div class="text-sm text-gray-500">{{ $movies['release_date'] ? date('Y', strtotime($movies['release_date'])) : '-' }}</div>
                                        </div>
                                    </div>
                                </span>

                                <p class="mt-4 text-sm md:text-base text-gray-300 leading-relaxed">
                                    {{ $movies['overview'] }}
                                </p>
                            </div>

                            <div class="w-full md:w-2/6 space-y-3 text-sm">
                                <div>
                                    <span class="text-gray-500">Cast: </span>
                                    <span class="text-gray-300">
                                        @foreach ($movies['credits']['cast'] as $cast)
                                            {{ $cast['name'] }}@if(!$loop->last), @endif
                                        @endforeach
                                    </span>
                                </div>
                                <div>
                                    <span class="text-gray-500">Genres: </span>
                                    <span class="text-gray-300">
                                        @foreach ($movies['genres'] as $genre)
                                            {{ $genre['name'] }}@if(!$loop->last), @endif
                                        @endforeach
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Rating Section -->
                        <div class="my-6 p-4 rounded-xl" style="background:#1f2937;border:1px solid #374151">
                            <h3 class="font-heading font-semibold text-white mb-3">⭐ Beri Rating</h3>
                            <form method="POST" action="{{ route('rating.store') }}">
                                @csrf
                                <input type="hidden" name="movie_id" value="{{ $movies['id'] }}">
                                <div class="flex items-center gap-1 mb-3">
                                    @for($i = 1; $i <= 10; $i++)
                                    <button type="submit" name="score" value="{{ $i }}" class="w-8 h-8 rounded text-sm font-bold transition-colors {{ $userRating && $userRating->score >= $i ? 'text-black' : 'text-gray-400 hover:text-yellow-400' }}" style="{{ $userRating && $userRating->score >= $i ? 'background:#C5A55A' : 'background:#374151' }}">
                                        {{ $i }}
                                    </button>
                                    @endfor
                                </div>
                                @if($userRating)
                                <p class="text-xs text-gray-500">Rating kamu: <strong style="color:#C5A55A">{{ $userRating->score }}/10</strong></p>
                                @endif
                            </form>
                        </div>

                        <!-- Comments Section -->
                        <div class="my-6">
                            <h3 class="font-heading font-semibold text-white mb-4">💬 Komentar ({{ $comments->count() }})</h3>

                            <!-- Comment Form -->
                            <form method="POST" action="{{ route('comment.store') }}" class="mb-6">
                                @csrf
                                <input type="hidden" name="movie_id" value="{{ $movies['id'] }}">
                                <textarea name="body" rows="3" class="w-full p-3 rounded-lg text-sm text-white placeholder-gray-500 resize-none focus:outline-none" style="background:#1f2937;border:1px solid #374151" placeholder="Tulis komentar..." required></textarea>
                                <div class="flex items-center justify-between mt-2">
                                    <label class="flex items-center gap-2 text-xs text-gray-500 cursor-pointer">
                                        <input type="checkbox" name="is_spoiler" value="1" class="rounded">
                                        ⚠️ Mengandung spoiler
                                    </label>
                                    <button type="submit" class="px-4 py-2 rounded-lg text-xs font-semibold text-black" style="background:#C5A55A">Kirim</button>
                                </div>
                            </form>

                            <!-- Comment List -->
                            <div class="space-y-4">
                                @forelse($comments as $comment)
                                <div class="p-4 rounded-lg" style="background:#1f2937">
                                    <div class="flex items-center gap-2 mb-2">
                                        <div class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold text-black" style="background:linear-gradient(135deg,#C5A55A,#E8D5A3)">
                                            {{ strtoupper(substr($comment->user->name, 0, 1)) }}
                                        </div>
                                        <span class="text-sm font-medium text-white">{{ $comment->user->name }}</span>
                                        <span class="text-xs text-gray-600">{{ $comment->created_at->diffForHumans() }}</span>
                                        @if($comment->is_spoiler)
                                            <span class="text-xs px-2 py-0.5 rounded bg-red-500/20 text-red-400">Spoiler</span>
                                        @endif
                                    </div>
                                    @if($comment->is_spoiler)
                                        <div x-data="{ show: false }">
                                            <p x-show="!show" class="text-sm text-gray-500 cursor-pointer italic" @click="show = true">⚠️ Klik untuk lihat spoiler...</p>
                                            <p x-show="show" class="text-sm text-gray-300">{{ $comment->body }}</p>
                                        </div>
                                    @else
                                        <p class="text-sm text-gray-300">{{ $comment->body }}</p>
                                    @endif

                                    @if($comment->user_id === auth()->id() || (auth()->user() && auth()->user()->is_admin))
                                    <form method="POST" action="{{ route('comment.destroy', $comment) }}" class="mt-2">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-xs text-red-500 hover:text-red-400">Hapus</button>
                                    </form>
                                    @endif

                                    <!-- Replies -->
                                    @if($comment->replies->count())
                                    <div class="mt-3 ml-6 space-y-3" style="border-left:2px solid #374151;padding-left:12px">
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
        </div>
    </div>
</x-layout>
