<x-admin.layout title="Manage Roles — {{ $user->name }}">

    <div style="max-width:760px">
        <div style="margin-bottom:20px">
            <a href="{{ route('admin.users.index') }}" style="color:#888;font-size:13px;text-decoration:none">&larr; Back to Users</a>
        </div>

        {{-- ─── User identity card ─────────────────────────────── --}}
        <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:20px 24px;margin-bottom:20px;display:flex;align-items:center;gap:16px">
            <div style="width:48px;height:48px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:700;color:#000;background:linear-gradient(135deg,#C5A55A,#E8D5A3);flex-shrink:0">
                {{ strtoupper(substr($user->name, 0, 1)) }}
            </div>
            <div>
                <div style="font-size:16px;font-weight:600;color:#fff">{{ $user->name }}</div>
                <div style="font-size:13px;color:#888">{{ $user->email }}</div>
            </div>
        </div>

        {{-- ─── Role assignment form ────────────────────────────── --}}
        <form method="POST" action="{{ route('admin.users.roles.update', $user) }}">
            @csrf

            <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:24px">
                <h2 style="font-size:16px;font-weight:600;margin-bottom:6px">Assigned Roles</h2>
                <p style="font-size:13px;color:#888;margin-bottom:20px">
                    Select one or more roles. The user will gain the union of all permissions across the selected roles.
                </p>

                @if($roles->isEmpty())
                    <div style="background:#0f0f0f;border:1px dashed #2a2a2a;border-radius:8px;padding:24px;text-align:center;color:#666">
                        No roles defined yet. <a href="{{ route('admin.roles.create') }}" style="color:#C5A55A">Create one first</a>.
                    </div>
                @else
                    <div style="display:flex;flex-direction:column;gap:10px">
                        @foreach($roles as $role)
                            <label style="display:flex;gap:14px;align-items:flex-start;padding:14px 16px;border:1px solid #2a2a2a;border-radius:10px;cursor:pointer;transition:border-color 0.2s,background 0.2s"
                                   onmouseover="this.style.borderColor='#3a3a3a';this.style.background='#202020'"
                                   onmouseout="this.style.borderColor='#2a2a2a';this.style.background='transparent'">
                                <input type="checkbox" name="roles[]" value="{{ $role->id }}"
                                       @checked(in_array($role->id, old('roles', $assignedRoleIds), true))
                                       style="accent-color:#C5A55A;width:16px;height:16px;margin-top:2px;flex-shrink:0">
                                <div style="flex:1;min-width:0">
                                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                                        <span style="font-size:14px;font-weight:600;color:#fff">{{ $role->display_name }}</span>
                                        <code style="font-size:11px;color:#C5A55A;background:rgba(197,165,90,0.1);padding:2px 8px;border-radius:6px;font-family:'JetBrains Mono',monospace">{{ $role->name }}</code>
                                        @if($role->is_system)
                                            <span class="badge" style="background:rgba(245,158,11,0.18);color:#f59e0b">SYSTEM</span>
                                        @endif
                                        <span class="badge badge-blue">{{ $role->permissions_count ?? $role->permissions()->count() }} perms</span>
                                    </div>
                                    @if($role->description)
                                        <div style="font-size:12px;color:#888;margin-top:4px;line-height:1.4">{{ $role->description }}</div>
                                    @endif
                                </div>
                            </label>
                        @endforeach
                    </div>

                    @error('roles')<div style="color:#ef4444;font-size:12px;margin-top:12px">{{ $message }}</div>@enderror
                    @error('roles.*')<div style="color:#ef4444;font-size:12px;margin-top:12px">{{ $message }}</div>@enderror
                @endif
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px">
                <a href="{{ route('admin.users.index') }}" class="btn btn-ghost">Cancel</a>
                <button type="submit" class="btn btn-gold" @disabled($roles->isEmpty())>Save Roles</button>
            </div>
        </form>
    </div>

</x-admin.layout>
