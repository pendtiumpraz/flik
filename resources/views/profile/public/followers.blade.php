@php
    /** @var \App\Models\User $user */
    /** @var \Illuminate\Pagination\LengthAwarePaginator $users */
    /** @var \App\Models\User|null $viewer */
    /** @var string $mode  'followers' | 'following' */
    $heading  = $mode === 'followers' ? 'Followers' : 'Following';
    $title    = '@' . ($user->username ?? $user->name) . ' — ' . $heading;
    $altRoute = $mode === 'followers'
        ? route('profile.public.following', $user)
        : route('profile.public.followers', $user);
    $altLabel = $mode === 'followers' ? 'Following' : 'Followers';
@endphp

<x-layout :title="$title">
    <div class="min-h-screen bg-black pt-20 pb-16">
        <div class="container mx-auto px-4 md:px-16">

            {{-- Identity strip --}}
            <div class="flex items-center gap-3 mb-2">
                <a href="{{ $user->publicProfileUrl() ?? route('velflix.index') }}"
                   class="text-sm text-[#C5A55A] hover:underline">&larr; back to profile</a>
            </div>

            <h1 class="font-heading text-2xl md:text-3xl font-bold text-white">
                {{ $user->name }}
                <span class="text-gray-500 text-base font-normal">&middot; {{ $heading }}</span>
            </h1>

            {{-- Tab switch --}}
            <div class="flex items-center gap-1 mt-4 mb-6 border-b" style="border-color:#2a2a2a">
                <a href="{{ route('profile.public.followers', $user) }}"
                   class="px-4 py-2.5 text-sm font-semibold border-b-2 transition {{ $mode === 'followers' ? 'text-white' : 'text-gray-500 hover:text-gray-300' }}"
                   style="border-color: {{ $mode === 'followers' ? '#C5A55A' : 'transparent' }}">
                    Followers
                </a>
                <a href="{{ route('profile.public.following', $user) }}"
                   class="px-4 py-2.5 text-sm font-semibold border-b-2 transition {{ $mode === 'following' ? 'text-white' : 'text-gray-500 hover:text-gray-300' }}"
                   style="border-color: {{ $mode === 'following' ? '#C5A55A' : 'transparent' }}">
                    Following
                </a>
            </div>

            {{-- Grid of user cards --}}
            @if($users->count())
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    @foreach($users as $u)
                        <x-users.card :user="$u" :viewer="$viewer" />
                    @endforeach
                </div>

                <div class="mt-8">
                    {{ $users->links() }}
                </div>
            @else
                <div class="p-12 rounded-xl text-center" style="background:#1a1a1a;border:1px solid #2a2a2a">
                    <p class="text-gray-500">
                        @if($mode === 'followers')
                            No one is following {{ $user->name }} yet.
                        @else
                            {{ $user->name }} isn't following anyone yet.
                        @endif
                    </p>
                </div>
            @endif
        </div>
    </div>
</x-layout>
