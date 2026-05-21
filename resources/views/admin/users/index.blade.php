<x-admin.layout title="User Management">

    <style>
        .icon-btn {
            display:inline-flex;align-items:center;justify-content:center;
            width:32px;height:32px;padding:0;
            border-radius:6px;background:transparent;border:1px solid #2a2a2a;
            color:#bbb;cursor:pointer;transition:all .12s;
        }
        .icon-btn:hover { background:#252525;color:#fff;border-color:#3a3a3a; }
        .icon-btn.icon-gold:hover   { color:#C5A55A; border-color:#C5A55A; }
        .icon-btn.icon-danger:hover { color:#ef4444; border-color:#ef4444; }
        .icon-btn.icon-green:hover  { color:#22c55e; border-color:#22c55e; }
        .icon-btn.icon-blue:hover   { color:#3b82f6; border-color:#3b82f6; }
        .icon-btn svg { width:16px;height:16px; }
        .icon-btn.is-active { background:#252525;color:#C5A55A;border-color:#C5A55A; }
    </style>

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
        <h2 style="font-size:18px;font-weight:600">All Users ({{ $users->total() }})</h2>
        <a href="{{ route('admin.users.create') }}" class="btn btn-gold">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            Add User
        </a>
    </div>

    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden">
        <div style="padding:16px 20px;border-bottom:1px solid #2a2a2a">
            <h3 style="font-size:14px;font-weight:600;color:#888">Manage admin + member accounts</h3>
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
                    <th>Admin</th>
                    <th>Roles</th>
                    <th>Joined</th>
                    <th style="width:240px">Actions</th>
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
                    <td>
                        {{-- Roles column. `relationLoaded('roles')` guards against an N+1 storm
                             if the controller forgot to eager-load; falls back to "—" when the
                             RBAC tables/relation haven't been wired yet by peer ROLE #1. --}}
                        @php
                            $userRoles = method_exists($user, 'roles') && $user->relationLoaded('roles')
                                ? $user->roles
                                : (method_exists($user, 'roles') ? $user->roles()->get() : collect());
                        @endphp
                        @if($userRoles->isEmpty())
                            <span style="font-size:12px;color:#555">—</span>
                        @else
                            <div style="display:flex;gap:4px;flex-wrap:wrap;max-width:220px">
                                @foreach($userRoles as $role)
                                    <span class="badge" style="background:rgba(197,165,90,0.15);color:#C5A55A" title="{{ $role->name ?? '' }}">
                                        {{ $role->display_name ?? $role->name ?? 'role#'.$role->id }}
                                    </span>
                                @endforeach
                            </div>
                        @endif
                    </td>
                    <td style="color:#666;font-size:13px">{{ $user->created_at ? $user->created_at->format('d M Y') : '-' }}</td>
                    <td>
                        @if($user->id !== auth()->id())
                            <div style="display:inline-flex;gap:4px;align-items:center">
                                {{-- Edit (pencil, gold hover) --}}
                                <a href="{{ route('admin.users.edit', $user) }}" class="icon-btn icon-gold" title="Edit user">
                                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                </a>

                                {{-- Manage roles (shield, blue hover) --}}
                                @if(\Illuminate\Support\Facades\Route::has('admin.users.roles.edit'))
                                    <a href="{{ route('admin.users.roles.edit', $user) }}" class="icon-btn icon-blue" title="Manage roles">
                                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                                    </a>
                                @endif

                                {{-- Toggle admin (crown when is_admin else star) --}}
                                <form method="POST" action="{{ route('admin.users.toggleAdmin', $user) }}" style="margin:0">
                                    @csrf @method('PUT')
                                    <button type="submit" class="icon-btn {{ $user->is_admin ? 'is-active' : 'icon-gold' }}"
                                            title="{{ $user->is_admin ? 'Remove admin status' : 'Promote to admin' }}">
                                        @if($user->is_admin)
                                            <svg fill="currentColor" viewBox="0 0 24 24"><path d="M5 16L3 7l5.5 5L12 5l3.5 7L21 7l-2 9H5zm14 3H5v-2h14v2z"/></svg>
                                        @else
                                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
                                        @endif
                                    </button>
                                </form>

                                {{-- Unlock login (open padlock, green hover) --}}
                                <form method="POST" action="{{ route('admin.users.unlock-login', $user) }}" style="margin:0">
                                    @csrf
                                    <button type="submit" class="icon-btn icon-green" title="Clear failed login attempts">
                                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z"/></svg>
                                    </button>
                                </form>

                                {{-- Delete (trash, red hover) --}}
                                <form method="POST" action="{{ route('admin.users.destroy', $user) }}"
                                      onsubmit="return confirm('Delete user {{ addslashes($user->name) }}? This cannot be undone.')" style="margin:0">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="icon-btn icon-danger" title="Delete user">
                                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3"/></svg>
                                    </button>
                                </form>
                            </div>
                        @else
                            <span style="font-size:11px;color:#C5A55A;font-weight:600">YOU</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" style="text-align:center;color:#555;padding:32px">No users found</td>
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
