<x-admin.layout title="Dashboard">

    <!-- Stats Grid -->
    <div class="grid-stats" style="margin-bottom: 32px">
        <div class="stat-card">
            <div style="display:flex;justify-content:space-between;align-items:start">
                <div>
                    <div class="label">Total Movies</div>
                    <div class="value">{{ $stats['total_movies'] }}</div>
                </div>
                <div class="icon" style="background:rgba(197,165,90,0.15)">
                    <svg width="20" height="20" fill="none" stroke="#C5A55A" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 4v16M17 4v16M3 8h4m10 0h4M3 12h18M3 16h4m10 0h4M4 20h16a1 1 0 001-1V5a1 1 0 00-1-1H4a1 1 0 00-1 1v14a1 1 0 001 1z"/></svg>
                </div>
            </div>
        </div>

        <div class="stat-card">
            <div style="display:flex;justify-content:space-between;align-items:start">
                <div>
                    <div class="label">Total Genres</div>
                    <div class="value">{{ $stats['total_genres'] }}</div>
                </div>
                <div class="icon" style="background:rgba(59,130,246,0.15)">
                    <svg width="20" height="20" fill="none" stroke="#3b82f6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A2 2 0 013 12V7a4 4 0 014-4z"/></svg>
                </div>
            </div>
        </div>

        <div class="stat-card">
            <div style="display:flex;justify-content:space-between;align-items:start">
                <div>
                    <div class="label">Total Cast</div>
                    <div class="value">{{ $stats['total_casts'] }}</div>
                </div>
                <div class="icon" style="background:rgba(168,85,247,0.15)">
                    <svg width="20" height="20" fill="none" stroke="#a855f7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                </div>
            </div>
        </div>

        <div class="stat-card">
            <div style="display:flex;justify-content:space-between;align-items:start">
                <div>
                    <div class="label">Total Users</div>
                    <div class="value">{{ $stats['total_users'] }}</div>
                </div>
                <div class="icon" style="background:rgba(34,197,94,0.15)">
                    <svg width="20" height="20" fill="none" stroke="#22c55e" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div style="display:flex;gap:12px;margin-bottom:32px;flex-wrap:wrap">
        <a href="{{ route('admin.movies.create') }}" class="btn btn-gold">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            Add New Movie
        </a>
        <a href="{{ route('admin.movies.index') }}" class="btn btn-ghost">Manage Movies</a>
        <a href="{{ route('admin.genres.index') }}" class="btn btn-ghost">Manage Genres</a>
    </div>

    <!-- Recent Movies -->
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden">
        <div style="padding:16px 20px;border-bottom:1px solid #2a2a2a;display:flex;justify-content:space-between;align-items:center">
            <h3 style="font-size:15px;font-weight:600">Recent Movies</h3>
            <a href="{{ route('admin.movies.index') }}" style="font-size:12px;color:#C5A55A;text-decoration:none">View All →</a>
        </div>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Genres</th>
                    <th>Rating</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse($recentMovies as $movie)
                <tr>
                    <td style="display:flex;align-items:center;gap:12px">
                        <img src="{{ $movie->poster_url }}" alt="{{ $movie->title }}" style="width:36px;height:52px;object-fit:cover;border-radius:4px;background:#333"
                            onerror="this.style.background='#333';this.src='https://via.placeholder.com/36x52/333/666?text='">
                        <div>
                            <div style="font-weight:500;color:#fff">{{ $movie->title }}</div>
                            <div style="font-size:12px;color:#666">{{ $movie->release_date ? $movie->release_date->format('Y') : '-' }}</div>
                        </div>
                    </td>
                    <td>
                        @foreach($movie->genres->take(2) as $genre)
                            <span class="badge badge-blue">{{ $genre->name }}</span>
                        @endforeach
                    </td>
                    <td>
                        <span style="color:#22c55e;font-weight:600">★ {{ number_format($movie->vote_average, 1) }}</span>
                    </td>
                    <td>
                        @if($movie->is_popular)
                            <span class="badge badge-gold">Popular</span>
                        @endif
                        @if($movie->is_trending)
                            <span class="badge badge-green">Trending</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="4" style="text-align:center;color:#555;padding:32px">No movies yet</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

</x-admin.layout>
