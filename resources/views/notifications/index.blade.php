<x-layout>
    <div class="min-h-screen bg-black pt-20 pb-16">
        <div class="container mx-auto px-4 md:px-16 max-w-3xl">

            <div class="flex items-center justify-between mb-8">
                <h1 class="font-heading text-2xl md:text-3xl font-bold text-white">🔔 Notifikasi</h1>
                @if($notifications->where('read_at', null)->count() > 0)
                <form method="POST" action="{{ route('notifications.readAll') }}">
                    @csrf
                    <button type="submit" class="text-sm font-medium hover:underline" style="color:#C5A55A">Tandai Semua Dibaca</button>
                </form>
                @endif
            </div>

            @if($notifications->count())
            <div class="space-y-2">
                @foreach($notifications as $notif)
                <form method="POST" action="{{ route('notifications.read', $notif) }}">
                    @csrf
                    <button type="submit" class="w-full text-left p-4 rounded-xl transition-colors hover:bg-gray-900/50 {{ $notif->read_at ? 'opacity-50' : '' }}" style="background:{{ $notif->read_at ? '#111' : '#1a1a1a' }};border:1px solid {{ $notif->read_at ? '#1a1a1a' : '#2a2a2a' }}">
                        <div class="flex items-start gap-3">
                            <div class="w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0 text-lg"
                                style="background:{{ $notif->type === 'achievement' ? 'rgba(197,165,90,0.2)' : ($notif->type === 'new_movie' ? 'rgba(59,130,246,0.2)' : 'rgba(100,100,100,0.2)') }}">
                                @switch($notif->type)
                                    @case('achievement') 🏆 @break
                                    @case('new_movie') 🎬 @break
                                    @case('subscription') 💳 @break
                                    @default 📢
                                @endswitch
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="text-sm font-semibold text-white">{{ $notif->title }}</div>
                                <div class="text-sm text-gray-400 mt-0.5">{{ $notif->message }}</div>
                                <div class="text-xs text-gray-600 mt-1">{{ $notif->created_at->diffForHumans() }}</div>
                            </div>
                            @if(!$notif->read_at)
                            <div class="w-2 h-2 rounded-full mt-2 flex-shrink-0" style="background:#C5A55A"></div>
                            @endif
                        </div>
                    </button>
                </form>
                @endforeach
            </div>

            <div class="mt-6">
                {{ $notifications->links() }}
            </div>
            @else
            <div class="text-center py-24">
                <div class="text-5xl mb-4">🔕</div>
                <h2 class="font-heading text-xl font-semibold text-gray-400">Belum Ada Notifikasi</h2>
                <p class="text-gray-600 mt-2 text-sm">Notifikasi akan muncul saat ada film baru, achievement, atau update lainnya.</p>
            </div>
            @endif
        </div>
    </div>
</x-layout>
