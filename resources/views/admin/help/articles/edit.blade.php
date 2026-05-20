<x-admin.layout title="Edit Help Article">

    @if(session('success'))
        <div style="background:rgba(34,197,94,0.15);border:1px solid rgba(34,197,94,0.3);color:#22c55e;padding:12px 20px;border-radius:8px;margin-bottom:20px;font-size:14px">
            {{ session('success') }}
        </div>
    @endif

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px">
        <div>
            <h2 style="font-size:22px;font-weight:600">Edit: {{ $article->title }}</h2>
            <p style="color:#777;font-size:13px;margin-top:4px">
                /{{ $article->slug }}
                @if($article->updated_at)
                    &middot; Terakhir diedit {{ $article->updated_at->diffForHumans() }}
                @endif
                @if($article->last_reviewed_at)
                    &middot; Direview {{ $article->last_reviewed_at->diffForHumans() }}
                @endif
            </p>
        </div>
        <div style="display:flex;gap:8px">
            @if($article->status === 'published')
                <a href="{{ route('help.show', $article->slug) }}" target="_blank" class="btn btn-ghost">View Live &rarr;</a>
            @endif
            <a href="{{ route('admin.help.articles.index') }}" class="btn btn-ghost">&larr; Back</a>
        </div>
    </div>

    @include('admin.help.articles._form', [
        'formAction' => route('admin.help.articles.update', $article),
        'method'     => 'PUT',
    ])

</x-admin.layout>
