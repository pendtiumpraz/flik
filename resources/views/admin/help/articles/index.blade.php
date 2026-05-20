<x-admin.layout title="Help Articles">

    @if(session('success'))
        <div style="background:rgba(34,197,94,0.15);border:1px solid rgba(34,197,94,0.3);color:#22c55e;padding:12px 20px;border-radius:8px;margin-bottom:20px;font-size:14px">
            {{ session('success') }}
        </div>
    @endif
    @if(session('error'))
        <div style="background:rgba(220,38,38,0.15);border:1px solid rgba(220,38,38,0.3);color:#ef4444;padding:12px 20px;border-radius:8px;margin-bottom:20px;font-size:14px">
            {{ session('error') }}
        </div>
    @endif

    {{-- Header --}}
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px">
        <div>
            <h2 style="font-size:22px;font-weight:600">Help Articles</h2>
            <p style="color:#777;font-size:13px;margin-top:4px">Knowledge base for the public /help center — markdown source with rendered cache.</p>
        </div>
        <div style="display:flex;gap:8px">
            <a href="{{ route('admin.help.categories.index') }}" class="btn btn-ghost">Categories</a>
            <a href="{{ route('admin.help.articles.create') }}" class="btn btn-gold">+ New Article</a>
        </div>
    </div>

    {{-- Status filter chips --}}
    @php
        $statusOptions = [
            ''          => ['label' => 'All',       'color' => '#aaa'],
            'draft'     => ['label' => 'Draft',     'color' => '#6b7280'],
            'published' => ['label' => 'Published', 'color' => '#22c55e'],
        ];
    @endphp
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px">
        @foreach($statusOptions as $key => $meta)
            @php
                $isActive = (string) $currentStatus === (string) $key;
                $count = $key === '' ? $articles->total() : ($statusCounts[$key] ?? 0);
                $params = array_filter([
                    'status'      => $key === '' ? null : $key,
                    'q'           => $q ?: null,
                    'category_id' => $currentCategoryId,
                ]);
                $url = route('admin.help.articles.index', $params);
            @endphp
            <a href="{{ $url }}"
               style="display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:20px;font-size:12px;font-weight:600;text-decoration:none;border:1px solid {{ $isActive ? $meta['color'] : '#2a2a2a' }};background:{{ $isActive ? $meta['color'].'22' : 'transparent' }};color:{{ $isActive ? $meta['color'] : '#999' }}">
                {{ $meta['label'] }}
                <span style="opacity:0.7">{{ $count }}</span>
            </a>
        @endforeach
    </div>

    {{-- Search + category filter --}}
    <form method="get" action="{{ route('admin.help.articles.index') }}" style="margin-bottom:20px;display:flex;gap:8px;flex-wrap:wrap">
        @if($currentStatus)<input type="hidden" name="status" value="{{ $currentStatus }}">@endif
        <input type="text" name="q" value="{{ $q }}" class="form-input" placeholder="Cari judul atau slug..." style="max-width:300px">
        <select name="category_id" class="form-input" style="max-width:220px">
            <option value="">— Semua kategori —</option>
            @foreach($categories as $cat)
                <option value="{{ $cat->id }}" @selected($currentCategoryId === $cat->id)>{{ $cat->name }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn btn-ghost">Search</button>
        @if($q || $currentCategoryId)
            <a href="{{ route('admin.help.articles.index', array_filter(['status' => $currentStatus])) }}" class="btn btn-ghost">Clear</a>
        @endif
    </form>

    {{-- Article list --}}
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden">
        @if($articles->isEmpty())
            <div style="padding:48px 20px;text-align:center;color:#555">
                <p style="margin-bottom:8px">Belum ada artikel.</p>
                <p style="font-size:12px">Klik <strong>+ New Article</strong> untuk membuat draft pertama.</p>
            </div>
        @else
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Status</th>
                        <th>Category</th>
                        <th>Author</th>
                        <th style="text-align:right">Views</th>
                        <th style="text-align:right">Helpful</th>
                        <th style="text-align:right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($articles as $a)
                        @php
                            $statusColor = $a->status === 'published' ? '#22c55e' : '#6b7280';
                            $pct = $a->helpful_percentage;
                        @endphp
                        <tr>
                            <td>
                                <div style="font-weight:500;color:#e5e5e5">{{ $a->title }}</div>
                                <div style="font-size:11px;color:#666;margin-top:2px">/{{ $a->slug }}</div>
                            </td>
                            <td>
                                <span class="badge" style="background:{{ $statusColor }}22;color:{{ $statusColor }}">
                                    {{ strtoupper($a->status) }}
                                </span>
                            </td>
                            <td style="color:#aaa">{{ $a->category?->name ?? '—' }}</td>
                            <td style="color:#aaa;font-size:12px">{{ $a->author?->name ?? '—' }}</td>
                            <td style="text-align:right;color:#C5A55A;font-weight:600">{{ number_format($a->views_count) }}</td>
                            <td style="text-align:right;color:#aaa">
                                @if($pct !== null)
                                    {{ $pct }}%
                                    <div style="font-size:10px;color:#666">{{ $a->helpful_count }}/{{ $a->total_feedback }}</div>
                                @else
                                    <span style="color:#555">—</span>
                                @endif
                            </td>
                            <td style="text-align:right;white-space:nowrap">
                                @if($a->status === 'published')
                                    <a href="{{ route('help.show', $a->slug) }}" target="_blank" class="btn btn-ghost btn-sm">View</a>
                                @else
                                    <form method="POST" action="{{ route('admin.help.articles.publish', $a) }}" style="display:inline">
                                        @csrf
                                        <button type="submit" class="btn btn-gold btn-sm">Publish</button>
                                    </form>
                                @endif
                                <a href="{{ route('admin.help.articles.edit', $a) }}" class="btn btn-ghost btn-sm">Edit</a>
                                <form method="POST" action="{{ route('admin.help.articles.destroy', $a) }}" style="display:inline" onsubmit="return confirm('Pindah ke tong sampah?')">
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

    @if($articles->hasPages())
        <div style="margin-top:20px">{{ $articles->links() }}</div>
    @endif
</x-admin.layout>
