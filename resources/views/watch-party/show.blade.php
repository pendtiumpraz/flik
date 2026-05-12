<x-layout :title="'Watch Party — ' . $movie->title">
<div class="min-h-screen bg-black text-white pt-16"
     x-data="{ chatOpen: true, copied: false }">

    {{-- Hidden bootstrap data the watch-party.js module reads on init. --}}
    <div id="watch-party-bootstrap"
         data-room-code="{{ $party->room_code }}"
         data-is-host="{{ $isHost ? '1' : '0' }}"
         data-user-id="{{ auth()->id() }}"
         data-user-name="{{ auth()->user()->name }}"
         data-position="{{ $party->current_position_seconds }}"
         data-is-playing="{{ $party->is_playing ? '1' : '0' }}"
         data-pusher-enabled="{{ $pusherEnabled ? '1' : '0' }}"
         data-pusher-key="{{ $pusherKey }}"
         data-pusher-cluster="{{ $pusherCluster }}"
         data-sync-url="{{ route('watch-party.sync', ['roomCode' => $party->room_code]) }}"
         data-chat-url="{{ route('watch-party.chat', ['roomCode' => $party->room_code]) }}"
         data-leave-url="{{ route('watch-party.leave', ['roomCode' => $party->room_code]) }}"
         data-csrf="{{ csrf_token() }}"
         style="display:none"></div>

    @unless($pusherEnabled)
        <div class="container mx-auto px-4 md:px-8 max-w-7xl py-6">
            <div class="rounded-xl border border-yellow-500/40 bg-yellow-500/10 text-yellow-200 px-5 py-4 flex items-start gap-3">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 mt-0.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 9v4M12 17h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                </svg>
                <div>
                    <div class="font-semibold mb-1">Watch Party feature requires Pusher setup.</div>
                    <div class="text-sm text-yellow-200/80">
                        Set <code class="px-1 bg-black/30 rounded">BROADCAST_DRIVER=pusher</code> and the
                        <code class="px-1 bg-black/30 rounded">PUSHER_APP_ID</code>,
                        <code class="px-1 bg-black/30 rounded">PUSHER_APP_KEY</code>,
                        <code class="px-1 bg-black/30 rounded">PUSHER_APP_SECRET</code>,
                        <code class="px-1 bg-black/30 rounded">PUSHER_APP_CLUSTER</code> env vars to enable real-time sync.
                        Playback works locally but other members won't see your changes.
                    </div>
                </div>
            </div>
        </div>
    @endunless

    <div class="container mx-auto px-4 md:px-8 max-w-7xl py-6 grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-6">

        {{-- ── Main column: header + video ───────────────────────── --}}
        <div>
            <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                <div>
                    <div class="text-xs uppercase tracking-widest text-[#C5A55A] mb-1">Watch Party</div>
                    <h1 class="text-2xl md:text-3xl font-bold font-heading">{{ $movie->title }}</h1>
                    <div class="text-sm text-gray-400 mt-1">
                        Host: <span class="text-white">{{ $party->host->name }}</span>
                        @if($isHost)
                            <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-[10px] uppercase tracking-wider bg-[#C5A55A] text-black font-bold">You are host</span>
                        @endif
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <div class="px-3 py-2 rounded-lg bg-[#0f0f0f] border border-[#C5A55A]/30">
                        <div class="text-[10px] uppercase tracking-wider text-gray-500">Room Code</div>
                        <div class="flex items-center gap-2 mt-0.5">
                            <span class="font-mono text-lg text-[#C5A55A] tracking-widest">{{ $party->room_code }}</span>
                            <button type="button"
                                    @click="navigator.clipboard.writeText('{{ $party->room_code }}'); copied = true; setTimeout(() => copied = false, 1500)"
                                    class="text-xs text-gray-400 hover:text-white transition">
                                <span x-show="!copied">Copy</span>
                                <span x-show="copied" x-cloak class="text-green-400">Copied!</span>
                            </button>
                        </div>
                    </div>
                    <form method="POST" action="{{ route('watch-party.leave', ['roomCode' => $party->room_code]) }}"
                          onsubmit="return confirm('{{ $isHost ? 'Keluar akan menutup room untuk semua peserta. Lanjutkan?' : 'Keluar dari Watch Party?' }}')">
                        @csrf
                        <button type="submit"
                                class="px-4 py-2 rounded-lg bg-red-600/20 border border-red-500/50 text-red-300 hover:bg-red-600/30 text-sm font-medium transition">
                            {{ $isHost ? 'End Party' : 'Leave' }}
                        </button>
                    </form>
                </div>
            </div>

            {{-- Video player container --}}
            <div class="rounded-2xl overflow-hidden shadow-2xl bg-black" style="border: 1px solid rgba(197,165,90,0.25)">
                <div class="relative bg-black" style="padding-top: 56.25%">
                    @if($movie->video_path || $movie->video_url)
                        <video id="wp-video"
                               class="absolute inset-0 w-full h-full"
                               controls
                               preload="auto"
                               poster="{{ $movie->backdrop_url }}"
                               @if(!$isHost) data-member="1" @endif>
                            <source src="{{ $movie->video_full_url }}" type="video/mp4">
                            Your browser doesn't support HTML5 video.
                        </video>
                    @elseif($movie->youtube_key)
                        <div class="absolute inset-0 flex items-center justify-center bg-[#0a0a0a] text-center text-gray-400 px-6">
                            <div>
                                <div class="text-sm">Film ini hanya tersedia via YouTube embed.</div>
                                <div class="text-xs text-gray-500 mt-1">Synchronized playback requires self-hosted video.</div>
                            </div>
                        </div>
                    @else
                        <div class="absolute inset-0 flex items-center justify-center bg-[#0a0a0a] text-gray-500 text-sm">
                            Video belum tersedia
                        </div>
                    @endif
                </div>
            </div>

            {{-- Host controls hint --}}
            <div class="mt-4 p-4 rounded-xl bg-[#0f0f0f] border border-white/5">
                @if($isHost)
                    <div class="text-sm text-gray-300">
                        <span class="inline-flex items-center gap-1.5 font-medium text-[#C5A55A]">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2 1 21h22L12 2zm0 4 8 14H4l8-14z"/></svg>
                            You control playback
                        </span>
                        <span class="text-gray-500 ml-2">— play, pause, and seek are broadcast to all members.</span>
                    </div>
                @else
                    <div class="text-sm text-gray-300">
                        <span class="inline-flex items-center gap-1.5 font-medium text-[#C5A55A]">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2 1 21h22L12 2zm0 4 8 14H4l8-14z"/></svg>
                            Following host
                        </span>
                        <span class="text-gray-500 ml-2">— your player will sync with {{ $party->host->name }}.</span>
                    </div>
                @endif
                <div id="wp-status" class="text-xs text-gray-500 mt-2"></div>
            </div>
        </div>

        {{-- ── Side panel: members + chat ────────────────────────── --}}
        <aside class="space-y-4">
            <div class="rounded-2xl bg-[#0f0f0f] border border-white/5 overflow-hidden">
                <div class="px-4 py-3 border-b border-white/5 flex items-center justify-between">
                    <div class="font-semibold text-sm uppercase tracking-wider text-[#C5A55A]">Members</div>
                    <div class="text-xs text-gray-500">
                        <span id="wp-member-count">{{ $party->activeMembers->count() }}</span> / {{ $party->max_members }}
                    </div>
                </div>
                <ul id="wp-member-list" class="divide-y divide-white/5 max-h-60 overflow-y-auto">
                    @foreach($party->activeMembers as $member)
                        <li data-member-id="{{ $member->user_id }}" class="px-4 py-2.5 flex items-center justify-between text-sm">
                            <div class="flex items-center gap-2">
                                <div class="w-8 h-8 rounded-full bg-gradient-to-br from-[#C5A55A] to-[#8b7239] flex items-center justify-center text-black font-bold text-xs">
                                    {{ strtoupper(substr($member->user->name, 0, 1)) }}
                                </div>
                                <span>{{ $member->user->name }}</span>
                            </div>
                            @if($party->isHost($member->user_id))
                                <span class="text-[10px] uppercase tracking-wider text-[#C5A55A] font-bold">Host</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="rounded-2xl bg-[#0f0f0f] border border-white/5 overflow-hidden flex flex-col" style="height: 420px">
                <button type="button" @click="chatOpen = !chatOpen"
                        class="w-full px-4 py-3 border-b border-white/5 flex items-center justify-between hover:bg-white/[0.02] transition">
                    <span class="font-semibold text-sm uppercase tracking-wider text-[#C5A55A]">Chat</span>
                    <svg :class="chatOpen ? 'rotate-180' : ''" xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 transition-transform" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                </button>
                <div x-show="chatOpen" x-cloak class="flex-1 flex flex-col overflow-hidden">
                    <div id="wp-chat-log" class="flex-1 overflow-y-auto px-4 py-3 space-y-2 text-sm">
                        <div class="text-xs text-gray-600 text-center italic">— start of session —</div>
                    </div>
                    <form id="wp-chat-form" class="border-t border-white/5 p-2 flex gap-2">
                        <input type="text" id="wp-chat-input"
                               placeholder="{{ $pusherEnabled ? 'Tulis pesan…' : 'Chat requires Pusher' }}"
                               maxlength="500"
                               @disabled(!$pusherEnabled)
                               class="flex-1 bg-black/40 border border-white/10 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[#C5A55A]/60 disabled:opacity-50">
                        <button type="submit"
                                @disabled(!$pusherEnabled)
                                class="px-3 py-2 rounded-lg bg-[#C5A55A] text-black font-bold text-sm hover:bg-[#d4b76f] transition disabled:opacity-50 disabled:cursor-not-allowed">
                            Send
                        </button>
                    </form>
                </div>
            </div>
        </aside>
    </div>
</div>

@push('scripts')
    {{-- Pusher SDK + Laravel Echo, loaded only when Pusher is configured. --}}
    @if($pusherEnabled)
        <script src="https://js.pusher.com/8.2.0/pusher.min.js"></script>
    @endif
    @vite('resources/js/watch-party.js')
@endpush
</x-layout>
