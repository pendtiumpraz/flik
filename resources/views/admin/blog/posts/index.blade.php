<x-admin.layout title="Blog Posts">

    @if(session('error'))
        <div style="background:rgba(220,38,38,0.15);border:1px solid rgba(220,38,38,0.3);color:#ef4444;padding:12px 20px;border-radius:8px;margin-bottom:20px;font-size:14px">
            {{ session('error') }}
        </div>
    @endif

    {{-- Header --}}
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px">
        <div>
            <h2 style="font-size:22px;font-weight:600">Blog Posts</h2>
            <p style="color:#777;font-size:13px;margin-top:4px">Editorial articles — markdown source with auto-rendered HTML cache.</p>
        </div>
        <div style="display:flex;gap:8px">
            <a href="{{ route('admin.blog.categories.index') }}" class="btn btn-ghost">Categories</a>
            <a href="{{ route('admin.blog.posts.create') }}" class="btn btn-gold">+ New Post</a>
        </div>
    </div>

    {{-- Status filter chips --}}
    @php
        $statusOptions = [
            ''          => ['label' => 'All',       'color' => '#aaa'],
            'draft'     => ['label' => 'Draft',     'color' => '#6b7280'],
            'scheduled' => ['label' => 'Scheduled', 'color' => '#3b82f6'],
            'published' => ['label' => 'Published', 'color' => '#22c55e'],
            'archived'  => ['label' => 'Archived',  'color' => '#ef4444'],
        ];
    @endphp
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px">
        @foreach($statusOptions as $key => $meta)
            @php
                $isActive = (string) $currentStatus === (string) $key;
                $count = $key === '' ? $posts->total() : ($statusCounts[$key] ?? 0);
                $url = $key === ''
                    ? route('admin.blog.posts.index', array_filter(['q' => $q]))
                    : route('admin.blog.posts.index', array_filter(['status' => $key, 'q' => $q]));
            @endphp
            <a href="{{ $url }}"
               style="display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:20px;font-size:12px;font-weight:600;text-decoration:none;border:1px solid {{ $isActive ? $meta['color'] : '#2a2a2a' }};background:{{ $isActive ? $meta['color'].'22' : 'transparent' }};color:{{ $isActive ? $meta['color'] : '#999' }}">
                {{ $meta['label'] }}
                <span style="opacity:0.7">{{ $count }}</span>
            </a>
        @endforeach
    </div>

    {{-- Search --}}
    <form method="get" action="{{ route('admin.blog.posts.index') }}" style="margin-bottom:20px;display:flex;gap:8px">
        @if($currentStatus)<input type="hidden" name="status" value="{{ $currentStatus }}">@endif
        <input type="text" name="q" value="{{ $q }}" class="form-input" placeholder="Cari judul atau slug..." style="max-width:360px">
        <button type="submit" class="btn btn-ghost">Search</button>
        @if($q)
            <a href="{{ route('admin.blog.posts.index', array_filter(['status' => $currentStatus])) }}" class="btn btn-ghost">Clear</a>
        @endif
    </form>

    {{-- Post list --}}
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden">
        @if($posts->isEmpty())
            <div style="padding:48px 20px;text-align:center;color:#555">
                <p style="margin-bottom:8px">Belum ada post.</p>
                <p style="font-size:12px">Klik <strong>+ New Post</strong> untuk membuat draft pertama.</p>
            </div>
        @else
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Status</th>
                        <th>Category</th>
                        <th>Author</th>
                        <th>Published</th>
                        <th style="text-align:right">Views</th>
                        <th style="text-align:right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($posts as $p)
                        @php
                            $statusColor = match($p->status) {
                                'draft'     => '#6b7280',
                                'scheduled' => '#3b82f6',
                                'published' => '#22c55e',
                                'archived'  => '#ef4444',
                                default     => '#666',
                            };
                        @endphp
                        <tr>
                            <td>
                                <div style="font-weight:500;color:#e5e5e5">
                                    {{ $p->title }}
                                    @if($p->is_featured)
                                        <span title="Featured" style="color:#C5A55A">★</span>
                                    @endif
                                </div>
                                <div style="font-size:11px;color:#666;margin-top:2px">/{{ $p->slug }} · {{ $p->reading_minutes }} min read</div>
                            </td>
                            <td>
                                <span class="badge" style="background:{{ $statusColor }}22;color:{{ $statusColor }}">
                                    {{ strtoupper($p->status) }}
                                </span>
                            </td>
                            <td style="color:#aaa">
                                @if($p->category)
                                    <span class="badge" style="background:{{ $p->category->color }}22;color:{{ $p->category->color }}">
                                        {{ $p->category->name }}
                                    </span>
                                @else
                                    <span style="color:#555">—</span>
                                @endif
                            </td>
                            <td style="color:#aaa;font-size:12px">{{ $p->author?->name ?? '—' }}</td>
                            <td style="color:#888;font-size:12px">
                                @if($p->status === 'published' && $p->published_at)
                                    {{ $p->published_at->format('Y-m-d H:i') }}
                                @elseif($p->status === 'scheduled' && $p->scheduled_for)
                                    <span style="color:#3b82f6">⏱ {{ $p->scheduled_for->format('Y-m-d H:i') }}</span>
                                @else
                                    <span style="color:#555">—</span>
                                @endif
                            </td>
                            <td style="text-align:right;color:#C5A55A;font-weight:600">{{ number_format($p->views_count) }}</td>
                            <td style="text-align:right;white-space:nowrap">
                                @if($p->status === 'published')
                                    <a href="{{ route('blog.show', $p->slug) }}" target="_blank" class="btn btn-ghost btn-sm">View</a>
                                @endif
                                <a href="{{ route('admin.blog.posts.edit', $p) }}" class="btn btn-ghost btn-sm">Edit</a>
                                <form method="POST" action="{{ route('admin.blog.posts.destroy', $p) }}" style="display:inline" onsubmit="return confirm('Pindah ke tong sampah?')">
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

    @if($posts->hasPages())
        <div style="margin-top:20px">{{ $posts->links() }}</div>
    @endif
</x-admin.layout>
