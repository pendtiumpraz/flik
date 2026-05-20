@props([
    'comment',  // \App\Models\Comment
    'movieId',  // int — needed for the Echo private channel topic
])

@php
    // Mirror App\Models\CommentReaction::REACTIONS + EMOJI here so the
    // Blade has no per-render PHP autoload of the model. Kept as a
    // literal in two places (model + Blade) intentionally — adding a
    // new reaction is a deliberate 2-touch change.
    $reactionEmoji = [
        'like' => '👍',
        'love' => '❤️',
        'laugh' => '😂',
        'wow' => '😮',
        'sad' => '😢',
        'angry' => '😡',
    ];

    $initialCounts = $comment->reactionsByType();
    $userReaction = $comment->reactionByUser(auth()->user());
@endphp

<div
    class="mt-3 flex flex-wrap items-center gap-1.5"
    x-data="commentReactions({
        id: {{ (int) $comment->id }},
        movieId: {{ (int) $movieId }},
        initial: {{ \Illuminate\Support\Js::from($initialCounts) }},
        mine: '{{ $userReaction ?? '' }}',
    })"
    x-init="init()"
    @beforeunload.window="destroy()"
>
    @foreach($reactionEmoji as $key => $emoji)
        <button
            type="button"
            @click="toggle('{{ $key }}')"
            :disabled="busy"
            :class="mine === '{{ $key }}'
                ? 'bg-[rgba(197,165,90,0.18)] text-[#C5A55A] border-[#C5A55A] ring-1 ring-[#C5A55A]/40'
                : 'bg-white/[0.03] text-gray-400 hover:text-[#C5A55A] hover:border-[#C5A55A]/50'"
            class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium transition-all border border-white/10 disabled:opacity-60 disabled:cursor-not-allowed"
            title="{{ ucfirst($key) }}"
            aria-label="React with {{ $key }}"
        >
            <span class="text-sm leading-none" aria-hidden="true">{{ $emoji }}</span>
            <span x-text="counts['{{ $key }}'] > 0 ? counts['{{ $key }}'] : ''" class="tabular-nums"></span>
        </button>
    @endforeach

    {{-- Total chip — only renders once at least one reaction exists. --}}
    <span
        x-show="total > 0"
        x-cloak
        class="ml-1 text-[10px] text-gray-500 tabular-nums"
        x-text="total + ' reaksi'"
    ></span>
</div>
