<x-admin.layout title="Genres">

    <div style="display:flex;gap:24px;flex-wrap:wrap">
        <!-- Add Genre Form -->
        <div style="width:300px;flex-shrink:0">
            <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:20px">
                <h3 style="font-size:15px;font-weight:600;margin-bottom:16px">Add Genre</h3>
                <form method="POST" action="{{ route('admin.genres.store') }}">
                    @csrf
                    <div class="form-group">
                        <input type="text" name="name" class="form-input" placeholder="Genre name..." required value="{{ old('name') }}">
                        @error('name')
                            <div style="color:#ef4444;font-size:12px;margin-top:4px">{{ $message }}</div>
                        @enderror
                    </div>
                    <button type="submit" class="btn btn-gold" style="width:100%;justify-content:center">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        Add Genre
                    </button>
                </form>
            </div>
        </div>

        <!-- Genre List -->
        <div style="flex:1;min-width:300px">
            <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden">
                <div style="padding:16px 20px;border-bottom:1px solid #2a2a2a">
                    <h3 style="font-size:15px;font-weight:600">All Genres ({{ $genres->count() }})</h3>
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
                        @forelse($genres as $genre)
                        <tr>
                            <td style="color:#555">{{ $genre->id }}</td>
                            <td style="font-weight:500;color:#fff">{{ $genre->name }}</td>
                            <td><span class="badge badge-blue">{{ $genre->movies_count }} films</span></td>
                            <td>
                                @if($genre->movies_count == 0)
                                    <form method="POST" action="{{ route('admin.genres.destroy', $genre) }}" onsubmit="return confirm('Delete genre {{ $genre->name }}?')">
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
                            <td colspan="4" style="text-align:center;color:#555;padding:32px">No genres yet</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</x-admin.layout>
