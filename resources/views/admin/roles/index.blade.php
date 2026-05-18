<x-admin.layout title="Roles & Permissions">

    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden">
        <div style="padding:16px 20px;border-bottom:1px solid #2a2a2a;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
            <div>
                <h3 style="font-size:15px;font-weight:600">All Roles ({{ $roles->count() }})</h3>
                <p style="font-size:12px;color:#777;margin-top:4px">Roles bundle permissions and are assigned to users. System roles are protected.</p>
            </div>
            <a href="{{ route('admin.roles.create') }}" class="btn btn-gold">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                New Role
            </a>
        </div>

        @if(session('error'))
            <div style="background:rgba(220,38,38,0.15);border-bottom:1px solid rgba(220,38,38,0.3);color:#ef4444;padding:12px 20px;font-size:14px">
                {{ session('error') }}
            </div>
        @endif

        <table class="admin-table">
            <thead>
                <tr>
                    <th style="width:50px">#</th>
                    <th>Name</th>
                    <th>Display Name</th>
                    <th style="width:90px">Priority</th>
                    <th style="width:120px">Permissions</th>
                    <th style="width:90px">Users</th>
                    <th style="width:200px">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($roles as $role)
                <tr>
                    <td style="color:#555">{{ $role->id }}</td>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                            <code style="font-size:12px;color:#C5A55A;background:rgba(197,165,90,0.1);padding:2px 8px;border-radius:6px;font-family:'JetBrains Mono',monospace">{{ $role->name }}</code>
                            @if($role->is_system)
                                <span class="badge" style="background:rgba(245,158,11,0.18);color:#f59e0b" title="System role — cannot be renamed or deleted">SYSTEM</span>
                            @endif
                        </div>
                        @if($role->description)
                            <div style="font-size:12px;color:#777;margin-top:4px;max-width:280px;line-height:1.4">{{ \Illuminate\Support\Str::limit($role->description, 90) }}</div>
                        @endif
                    </td>
                    <td style="color:#fff;font-weight:500">{{ $role->display_name }}</td>
                    <td style="color:#888">{{ $role->priority }}</td>
                    <td><span class="badge badge-blue">{{ $role->permissions_count }} perms</span></td>
                    <td><span class="badge badge-green">{{ $role->users_count }} users</span></td>
                    <td>
                        <div style="display:flex;gap:6px;flex-wrap:wrap">
                            <a href="{{ route('admin.roles.edit', $role) }}" class="btn btn-ghost btn-sm">Edit</a>
                            @if(!$role->is_system)
                                <form method="POST" action="{{ route('admin.roles.destroy', $role) }}" onsubmit="return confirm('Delete role &quot;{{ addslashes($role->display_name) }}&quot;? This will detach it from {{ $role->users_count }} user(s) and cannot be undone.')" style="display:inline">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                </form>
                            @else
                                <span style="font-size:11px;color:#555;padding:6px 4px" title="System roles are protected">Locked</span>
                            @endif
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" style="text-align:center;color:#555;padding:32px">
                        No roles defined yet. <a href="{{ route('admin.roles.create') }}" style="color:#C5A55A">Create the first one</a>.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

</x-admin.layout>
