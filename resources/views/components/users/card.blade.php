@props([
    'user',          // App\Models\User
    'viewer' => null, // App\Models\User|null — the authenticated viewer (drives follow-back hint)
    'compact' => false,
])

@php
    /** @var \App\Models\User $user */
    /** @var \App\Models\User|null $viewer */
    $isSelf       = $viewer && $viewer->id === $user->id;
    $isFollowing  = $viewer && ! $isSelf ? $viewer->isFollowing($user) : false;
    $isMutual     = $viewer && ! $isSelf ? $viewer->isMutualWith($user) : false;
    $isFollowedBy = $viewer && ! $isSelf ? $viewer->isFollowedBy($user) : false;
    $profileUrl   = $user->publicProfileUrl() ?? '#';
    $avatar       = $user->avatar_url; // null when no upload
    $initial      = strtoupper(substr($user->name ?? '?', 0, 1));
    $bioSnippet   = \Illuminate\Support\Str::limit((string) $user->bio, 80);
@endphp

<div class="rounded-xl p-4 transition-colors hover:bg-[#181818]" style="background:#1a1a1a;border:1px solid #2a2a2a">
    <div class="flex items-start gap-3">
        <a href="{{ $profileUrl }}" class="shrink-0">
            @if($avatar)
                <img src="{{ $avatar }}" alt="{{ $user->name }}" class="w-12 h-12 rounded-full object-cover ring-1 ring-[#C5A55A]/30">
            @else
                <div class="w-12 h-12 rounded-full flex items-center justify-center text-base font-semibold text-black ring-1 ring-[#C5A55A]/30"
                     style="background: linear-gradient(135deg, #C5A55A, #E8D5A3)">
                    {{ $initial }}
                </div>
            @endif
        </a>

        <div class="flex-1 min-w-0">
            <a href="{{ $profileUrl }}" class="block">
                <div class="font-semibold text-white truncate">{{ $user->name }}</div>
                @if($user->username)
                    <div class="text-xs text-gray-500">&commat;{{ $user->username }}</div>
                @endif
            </a>

            @if($bioSnippet && ! $compact)
                <p class="text-xs text-gray-400 mt-1 line-clamp-2">{{ $bioSnippet }}</p>
            @endif

            <div class="mt-1.5 flex items-center gap-2">
                @if($isMutual)
                    <span class="text-[10px] font-semibold uppercase tracking-wider px-1.5 py-0.5 rounded"
                          style="background:rgba(197,165,90,0.15);color:#C5A55A">Mutuals</span>
                @elseif($isFollowedBy)
                    <span class="text-[10px] font-semibold uppercase tracking-wider px-1.5 py-0.5 rounded"
                          style="background:rgba(107,114,128,0.18);color:#9ca3af">Follows you</span>
                @endif
            </div>
        </div>

        @if($viewer && ! $isSelf)
            <div class="shrink-0">
                @if($isFollowing)
                    <form method="POST" action="{{ route('profile.public.unfollow', $user) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit"
                                class="px-3 py-1.5 rounded-lg text-xs font-semibold whitespace-nowrap"
                                style="background:transparent;border:1px solid #2a2a2a;color:#9ca3af">
                            Following
                        </button>
                    </form>
                @else
                    <form method="POST" action="{{ route('profile.public.follow', $user) }}">
                        @csrf
                        <button type="submit"
                                class="px-3 py-1.5 rounded-lg text-xs font-semibold text-black whitespace-nowrap"
                                style="background:#C5A55A">
                            {{ $isFollowedBy ? 'Follow back' : 'Follow' }}
                        </button>
                    </form>
                @endif
            </div>
        @endif
    </div>
</div>
