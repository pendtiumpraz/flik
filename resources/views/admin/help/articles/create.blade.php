<x-admin.layout title="New Help Article">

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px">
        <div>
            <h2 style="font-size:22px;font-weight:600">New Help Article</h2>
            <p style="color:#777;font-size:13px;margin-top:4px">Draft a knowledge-base entry — Save as Draft to revisit later, or Publish Now to go live.</p>
        </div>
        <a href="{{ route('admin.help.articles.index') }}" class="btn btn-ghost">&larr; Back to list</a>
    </div>

    @include('admin.help.articles._form', [
        'formAction' => route('admin.help.articles.store'),
        'method'     => 'POST',
    ])

</x-admin.layout>
