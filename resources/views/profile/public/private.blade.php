@php
    /** @var \App\Models\User $user */
    $pageTitle = '@' . ($user->username ?? $user->name) . ' — FLiK';
    $avatar = $user->avatar_url;
    $initial = strtoupper(substr($user->name ?? '?', 0, 1));
@endphp

<x-layout :title="$pageTitle">
    <div class="min-h-screen bg-black flex items-center justify-center px-4 py-16">
        <div class="max-w-md w-full text-center">
            @if($avatar)
                <img src="{{ $avatar }}" alt="{{ $user->name }}"
                     class="w-28 h-28 mx-auto rounded-full object-cover ring-2 ring-[#C5A55A]/40">
            @else
                <div class="w-28 h-28 mx-auto rounded-full flex items-center justify-center text-4xl font-bold text-black ring-2 ring-[#C5A55A]/40"
                     style="background: linear-gradient(135deg, #C5A55A, #E8D5A3)">
                    {{ $initial }}
                </div>
            @endif

            <h1 class="font-heading text-2xl font-bold text-white mt-5">{{ $user->name }}</h1>
            @if($user->username)
                <p class="text-sm text-gray-400">&commat;{{ $user->username }}</p>
            @endif

            <div class="mt-8 p-6 rounded-2xl" style="background:#1a1a1a;border:1px solid #2a2a2a">
                <div class="text-3xl mb-2">🔒</div>
                <h2 class="font-heading text-lg font-semibold text-white">This profile is private</h2>
                <p class="text-sm text-gray-400 mt-2">
                    {{ $user->name }} has chosen to keep their activity, watchlist, and lists out of public view.
                </p>

                @if($isAuthed && ! $isFollowing)
                    <form method="POST" action="{{ route('profile.public.follow', $user) }}" class="mt-5">
                        @csrf
                        <button type="submit"
                                class="inline-flex items-center gap-2 px-5 py-2 rounded-lg text-sm font-semibold text-black"
                                style="background:#C5A55A">
                            Follow
                        </button>
                    </form>
                @elseif($isAuthed && $isFollowing)
                    <p class="text-xs text-[#C5A55A] mt-4">You are following this user.</p>
                @else
                    <a href="{{ route('login') }}"
                       class="inline-block mt-5 px-5 py-2 rounded-lg text-sm font-semibold text-black"
                       style="background:#C5A55A">
                        Sign in to follow
                    </a>
                @endif
            </div>
        </div>
    </div>
</x-layout>
