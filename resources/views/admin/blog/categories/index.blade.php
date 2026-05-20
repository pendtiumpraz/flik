<x-admin.layout title="Blog Categories">

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px">
        <div>
            <h2 style="font-size:22px;font-weight:600">Blog Categories</h2>
            <p style="color:#777;font-size:13px;margin-top:4px">Taxonomy for editorial posts. Color tints the badge on cards & detail pages.</p>
        </div>
        <a href="{{ route('admin.blog.posts.index') }}" class="btn btn-ghost">← Back to Posts</a>
    </div>

    @if($errors->any())
        <div style="background:rgba(220,38,38,0.15);border:1px solid rgba(220,38,38,0.3);color:#ef4444;padding:12px 20px;border-radius:8px;margin-bottom:20px;font-size:13px">
            <ul style="margin-left:18px">
                @foreach($errors->all() as $err)<li>{{ $err }}</li>@endforeach
            </ul>
        </div>
    @endif

    {{-- Create form --}}
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:20px;margin-bottom:24px">
        <h3 style="font-size:14px;text-transform:uppercase;letter-spacing:1px;color:#C5A55A;margin-bottom:16px;font-weight:600">+ New Category</h3>
        <form method="POST" action="{{ route('admin.blog.categories.store') }}" style="display:grid;grid-template-columns:2fr 2fr 100px 80px auto;gap:10px;align-items:end">
            @csrf
            <div>
                <label style="font-size:11px;color:#aaa;display:block;margin-bottom:4px">Name *</label>
                <input type="text" name="name" required maxlength="120" class="form-input" placeholder="e.g. Reviews">
            </div>
            <div>
                <label style="font-size:11px;color:#aaa;display:block;margin-bottom:4px">Slug (optional)</label>
                <input type="text" name="slug" maxlength="80" class="form-input" placeholder="auto from name">
            </div>
            <div>
                <label style="font-size:11px;color:#aaa;display:block;margin-bottom:4px">Color</label>
                <input type="color" name="color" value="#C5A55A" class="form-input" style="padding:2px;height:38px">
            </div>
            <div>
                <label style="font-size:11px;color:#aaa;display:block;margin-bottom:4px">Sort</label>
                <input type="number" name="sort_order" value="0" class="form-input">
            </div>
            <button type="submit" class="btn btn-gold">Create</button>
        </form>
    </div>

    {{-- List --}}
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden">
        @if($categories->isEmpty())
            <div style="padding:48px 20px;text-align:center;color:#555">
                <p>Belum ada kategori.</p>
            </div>
        @else
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Name / Slug</th>
                        <th>Color</th>
                        <th style="text-align:right">Posts</th>
                        <th style="text-align:right">Sort</th>
                        <th style="text-align:right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($categories as $c)
                        <tr>
                            <form method="POST" action="{{ route('admin.blog.categories.update', $c) }}" id="cat-update-{{ $c->id }}">
                                @csrf @method('PUT')
                            </form>
                            <td>
                                <input type="text" form="cat-update-{{ $c->id }}" name="name" value="{{ $c->name }}" class="form-input" style="padding:6px 10px;margin-bottom:4px" required>
                                <input type="text" form="cat-update-{{ $c->id }}" name="slug" value="{{ $c->slug }}" class="form-input" style="padding:6px 10px;font-size:11px;color:#999" placeholder="slug">
                            </td>
                            <td>
                                <div style="display:flex;align-items:center;gap:8px">
                                    <input type="color" form="cat-update-{{ $c->id }}" name="color" value="{{ $c->color }}" style="width:36px;height:30px;border:1px solid #2a2a2a;background:transparent;cursor:pointer;border-radius:4px">
                                    <span class="badge" style="background:{{ $c->color }}22;color:{{ $c->color }}">{{ $c->name }}</span>
                                </div>
                            </td>
                            <td style="text-align:right;color:#aaa">{{ $c->posts_count }}</td>
                            <td style="text-align:right">
                                <input type="number" form="cat-update-{{ $c->id }}" name="sort_order" value="{{ $c->sort_order }}" class="form-input" style="width:70px;padding:6px 8px;text-align:right">
                            </td>
                            <td style="text-align:right;white-space:nowrap">
                                <button type="submit" form="cat-update-{{ $c->id }}" class="btn btn-gold btn-sm">Save</button>
                                <form method="POST" action="{{ route('admin.blog.categories.destroy', $c) }}" style="display:inline" onsubmit="return confirm('Hapus kategori? Posts terkait akan dilepas (set NULL).')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

</x-admin.layout>
