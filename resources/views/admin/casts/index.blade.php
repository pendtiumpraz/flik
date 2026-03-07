<x-admin.layout title="Cast Members">

    <div style="display:flex;gap:24px;flex-wrap:wrap">
        <!-- Add Cast Form -->
        <div style="width:300px;flex-shrink:0">
            <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:20px">
                <h3 style="font-size:15px;font-weight:600;margin-bottom:16px">Add Cast</h3>
                <form method="POST" action="{{ route('admin.casts.store') }}">
                    @csrf
                    <div class="form-group">
                        <label>Name *</label>
                        <input type="text" name="name" class="form-input" placeholder="Actor/Actress name..." required value="{{ old('name') }}">
                        @error('name')
                            <div style="color:#ef4444;font-size:12px;margin-top:4px">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="form-group">
                        <label>Profile Photo URL</label>
                        <input type="text" name="profile_path" class="form-input" placeholder="https://..." value="{{ old('profile_path') }}">
                    </div>
                    <button type="submit" class="btn btn-gold" style="width:100%;justify-content:center">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        Add Cast
                    </button>
                </form>
            </div>
        </div>

        <!-- Cast List -->
        <div style="flex:1;min-width:300px">
            <div style="margin-bottom:16px">
                <form method="GET" action="{{ route('admin.casts.index') }}" style="display:flex;gap:8px">
                    <input type="text" name="search" value="{{ request('search') }}" placeholder="Search cast..." class="form-input" style="width:250px;padding:8px 14px">
                    <button type="submit" class="btn btn-ghost btn-sm">Search</button>
                    @if(request('search'))
                        <a href="{{ route('admin.casts.index') }}" class="btn btn-ghost btn-sm" style="color:#666">Clear</a>
                    @endif
                </form>
            </div>

            <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden">
                <div style="padding:16px 20px;border-bottom:1px solid #2a2a2a">
                    <h3 style="font-size:15px;font-weight:600">All Cast ({{ $casts->total() }})</h3>
                </div>
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Name</th>
                            <th>Movies</th>
                            <th style="width:80px">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($casts as $cast)
                        <tr>
                            <td style="color:#555">{{ $cast->id }}</td>
                            <td style="display:flex;align-items:center;gap:10px">
                                @if($cast->profile_path)
                                    <img src="{{ $cast->profile_path }}" style="width:32px;height:32px;border-radius:50%;object-fit:cover;background:#333" onerror="this.style.display='none'">
                                @else
                                    <div style="width:32px;height:32px;border-radius:50%;background:#333;display:flex;align-items:center;justify-content:center;font-size:12px;color:#888">{{ strtoupper(substr($cast->name, 0, 1)) }}</div>
                                @endif
                                <span style="font-weight:500;color:#fff">{{ $cast->name }}</span>
                            </td>
                            <td><span class="badge badge-blue">{{ $cast->movies_count }} films</span></td>
                            <td>
                                @if($cast->movies_count == 0)
                                    <form method="POST" action="{{ route('admin.casts.destroy', $cast) }}" onsubmit="return confirm('Delete {{ addslashes($cast->name) }}?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn btn-danger btn-sm">Del</button>
                                    </form>
                                @else
                                    <span style="font-size:11px;color:#555">In use</span>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="4" style="text-align:center;color:#555;padding:32px">
                                @if(request('search'))
                                    No cast found for "{{ request('search') }}"
                                @else
                                    No cast members yet
                                @endif
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($casts->hasPages())
            <div style="margin-top:20px;display:flex;justify-content:center;gap:8px">
                @if($casts->onFirstPage())
                    <span class="btn btn-ghost btn-sm" style="opacity:0.3">← Prev</span>
                @else
                    <a href="{{ $casts->previousPageUrl() }}" class="btn btn-ghost btn-sm">← Prev</a>
                @endif
                <span style="padding:6px 12px;font-size:13px;color:#888">Page {{ $casts->currentPage() }} of {{ $casts->lastPage() }}</span>
                @if($casts->hasMorePages())
                    <a href="{{ $casts->nextPageUrl() }}" class="btn btn-ghost btn-sm">Next →</a>
                @else
                    <span class="btn btn-ghost btn-sm" style="opacity:0.3">Next →</span>
                @endif
            </div>
            @endif
        </div>
    </div>

</x-admin.layout>
