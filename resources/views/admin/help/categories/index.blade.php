<x-admin.layout title="Help Categories">

    @if(session('success'))
        <div style="background:rgba(34,197,94,0.15);border:1px solid rgba(34,197,94,0.3);color:#22c55e;padding:12px 20px;border-radius:8px;margin-bottom:20px;font-size:14px">
            {{ session('success') }}
        </div>
    @endif

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px">
        <div>
            <h2 style="font-size:22px;font-weight:600">Help Categories</h2>
            <p style="color:#777;font-size:13px;margin-top:4px">Group articles for the public /help landing page. Icon names follow the existing &lt;x-icon&gt; component catalogue.</p>
        </div>
        <a href="{{ route('admin.help.articles.index') }}" class="btn btn-ghost">&larr; Back to Articles</a>
    </div>

    @if($errors->any())
        <div style="background:rgba(220,38,38,0.15);border:1px solid rgba(220,38,38,0.3);color:#ef4444;padding:12px 20px;border-radius:8px;margin-bottom:20px;font-size:13px">
            <ul style="margin-left:18px">
                @foreach($errors->all() as $err)<li>{{ $err }}</li>@endforeach
            </ul>
        </div>
    @endif

    @php
        // Subset of the existing icon component keys that fits help-style cards.
        $iconOptions = ['sparkles','film','bookmark','shield','coin','gift','info','chat','user','user-circle','cog','star','sparkles','lightning','medal','eye','fire','gem','home','chevron-down','check'];
        $iconOptions = array_values(array_unique($iconOptions));
    @endphp

    {{-- Create form --}}
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:20px;margin-bottom:24px">
        <h3 style="font-size:14px;text-transform:uppercase;letter-spacing:1px;color:#C5A55A;margin-bottom:16px;font-weight:600">+ New Category</h3>
        <form method="POST" action="{{ route('admin.help.categories.store') }}">
            @csrf
            <div style="display:grid;grid-template-columns:1.5fr 1.5fr 1fr 80px auto;gap:10px;align-items:end">
                <div>
                    <label style="font-size:11px;color:#aaa;display:block;margin-bottom:4px">Name *</label>
                    <input type="text" name="name" required maxlength="120" class="form-input" placeholder="contoh: Pemutaran">
                </div>
                <div>
                    <label style="font-size:11px;color:#aaa;display:block;margin-bottom:4px">Slug (optional)</label>
                    <input type="text" name="slug" maxlength="80" class="form-input" placeholder="auto dari nama">
                </div>
                <div>
                    <label style="font-size:11px;color:#aaa;display:block;margin-bottom:4px">Icon</label>
                    <select name="icon" class="form-input">
                        <option value="">— pilih ikon —</option>
                        @foreach($iconOptions as $ic)
                            <option value="{{ $ic }}">{{ $ic }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label style="font-size:11px;color:#aaa;display:block;margin-bottom:4px">Sort</label>
                    <input type="number" name="sort_order" value="0" class="form-input">
                </div>
                <button type="submit" class="btn btn-gold">Create</button>
            </div>
            <div style="margin-top:10px">
                <label style="font-size:11px;color:#aaa;display:block;margin-bottom:4px">Description</label>
                <textarea name="description" class="form-input" rows="2" placeholder="Penjelasan singkat untuk public landing — opsional"></textarea>
            </div>
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
                        <th>Icon</th>
                        <th>Description</th>
                        <th style="text-align:right">Articles</th>
                        <th style="text-align:right">Sort</th>
                        <th style="text-align:right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($categories as $c)
                        <tr>
                            <form method="POST" action="{{ route('admin.help.categories.update', $c) }}" id="cat-update-{{ $c->id }}">
                                @csrf @method('PUT')
                            </form>
                            <td>
                                <input type="text" form="cat-update-{{ $c->id }}" name="name" value="{{ $c->name }}" class="form-input" style="padding:6px 10px;margin-bottom:4px" required>
                                <input type="text" form="cat-update-{{ $c->id }}" name="slug" value="{{ $c->slug }}" class="form-input" style="padding:6px 10px;font-size:11px;color:#999" placeholder="slug">
                            </td>
                            <td style="min-width:120px">
                                <select form="cat-update-{{ $c->id }}" name="icon" class="form-input" style="padding:6px 10px">
                                    <option value="">—</option>
                                    @foreach($iconOptions as $ic)
                                        <option value="{{ $ic }}" @selected($c->icon === $ic)>{{ $ic }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td>
                                <textarea form="cat-update-{{ $c->id }}" name="description" class="form-input" rows="2" style="padding:6px 10px;font-size:12px;min-width:200px">{{ $c->description }}</textarea>
                            </td>
                            <td style="text-align:right;color:#aaa">
                                <div style="color:#C5A55A;font-weight:600">{{ $c->articles_count }}</div>
                                <div style="font-size:10px;color:#666">total: {{ $c->articles_total }}</div>
                            </td>
                            <td style="text-align:right">
                                <input type="number" form="cat-update-{{ $c->id }}" name="sort_order" value="{{ $c->sort_order }}" class="form-input" style="width:70px;padding:6px 8px;text-align:right">
                            </td>
                            <td style="text-align:right;white-space:nowrap">
                                <button type="submit" form="cat-update-{{ $c->id }}" class="btn btn-gold btn-sm">Save</button>
                                <form method="POST" action="{{ route('admin.help.categories.destroy', $c) }}" style="display:inline" onsubmit="return confirm('Hapus kategori? Artikel terkait akan dilepas (set NULL).')">
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
