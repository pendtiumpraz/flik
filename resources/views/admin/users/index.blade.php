<x-admin.layout title="User Management">

    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden">
        <div style="padding:16px 20px;border-bottom:1px solid #2a2a2a;display:flex;justify-content:space-between;align-items:center">
            <h3 style="font-size:15px;font-weight:600">All Users ({{ $users->total() }})</h3>
        </div>

        @if(session('error'))
            <div style="background:rgba(220,38,38,0.15);border-bottom:1px solid rgba(220,38,38,0.3);color:#ef4444;padding:12px 20px;font-size:14px">
                ⚠️ {{ session('error') }}
            </div>
        @endif

        <table class="admin-table">
            <thead>
                <tr>
                    <th style="width:50px">#</th>
                    <th>User</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Joined</th>
                    <th style="width:180px">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($users as $user)
                <tr>
                    <td style="color:#555">{{ $user->id }}</td>
                    <td>
                        <div style="display:flex;align-items:center;gap:10px">
                            <div style="width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:#000;{{ $user->is_admin ? 'background:linear-gradient(135deg,#C5A55A,#E8D5A3)' : 'background:#444;color:#fff' }}">
                                {{ strtoupper(substr($user->name, 0, 1)) }}
                            </div>
                            <span style="font-weight:500;color:#fff">{{ $user->name }}</span>
                        </div>
                    </td>
                    <td style="color:#888">{{ $user->email }}</td>
                    <td>
                        @if($user->is_admin)
                            <span class="badge badge-gold">Admin</span>
                        @else
                            <span class="badge" style="background:rgba(100,100,100,0.2);color:#888">User</span>
                        @endif
                    </td>
                    <td style="color:#666;font-size:13px">{{ $user->created_at ? $user->created_at->format('d M Y') : '-' }}</td>
                    <td>
                        @if($user->id !== auth()->id())
                            <div style="display:flex;gap:6px">
                                <form method="POST" action="{{ route('admin.users.toggleAdmin', $user) }}">
                                    @csrf @method('PUT')
                                    <button type="submit" class="btn btn-ghost btn-sm">
                                        {{ $user->is_admin ? '👤 Remove Admin' : '⭐ Make Admin' }}
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('admin.users.destroy', $user) }}" onsubmit="return confirm('Delete user {{ addslashes($user->name) }}? This cannot be undone.')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-danger btn-sm">Del</button>
                                </form>
                            </div>
                        @else
                            <span style="font-size:12px;color:#C5A55A">You (current)</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" style="text-align:center;color:#555;padding:32px">No users found</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($users->hasPages())
    <div style="margin-top:20px;display:flex;justify-content:center;gap:8px">
        @if($users->onFirstPage())
            <span class="btn btn-ghost btn-sm" style="opacity:0.3">← Prev</span>
        @else
            <a href="{{ $users->previousPageUrl() }}" class="btn btn-ghost btn-sm">← Prev</a>
        @endif
        <span style="padding:6px 12px;font-size:13px;color:#888">Page {{ $users->currentPage() }} of {{ $users->lastPage() }}</span>
        @if($users->hasMorePages())
            <a href="{{ $users->nextPageUrl() }}" class="btn btn-ghost btn-sm">Next →</a>
        @else
            <span class="btn btn-ghost btn-sm" style="opacity:0.3">Next →</span>
        @endif
    </div>
    @endif

</x-admin.layout>
