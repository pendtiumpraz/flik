<x-admin.layout title="New Role">

    <div style="max-width:640px">
        <div style="margin-bottom:20px">
            <a href="{{ route('admin.roles.index') }}" style="color:#888;font-size:13px;text-decoration:none">&larr; Back to Roles</a>
        </div>

        <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:24px">
            <h2 style="font-size:18px;font-weight:600;margin-bottom:6px">Create New Role</h2>
            <p style="font-size:13px;color:#888;margin-bottom:24px">After creating, you'll be redirected to the edit page to assign permissions.</p>

            <form method="POST" action="{{ route('admin.roles.store') }}">
                @csrf

                <div class="form-group">
                    <label for="name">Name (machine identifier) <span style="color:#ef4444">*</span></label>
                    <input type="text" id="name" name="name" class="form-input" placeholder="e.g. moderator, finance_lead"
                           value="{{ old('name') }}" required maxlength="64"
                           pattern="[a-z0-9_]+" title="Lowercase letters, digits, and underscores only — no spaces.">
                    <div style="font-size:11px;color:#666;margin-top:4px">Lowercase letters, digits, and underscores only. No spaces. Cannot be changed for system roles.</div>
                    @error('name')<div style="color:#ef4444;font-size:12px;margin-top:6px">{{ $message }}</div>@enderror
                </div>

                <div class="form-group">
                    <label for="display_name">Display Name <span style="color:#ef4444">*</span></label>
                    <input type="text" id="display_name" name="display_name" class="form-input" placeholder="e.g. Content Moderator"
                           value="{{ old('display_name') }}" required maxlength="120">
                    <div style="font-size:11px;color:#666;margin-top:4px">Shown in user badges and the assignment UI.</div>
                    @error('display_name')<div style="color:#ef4444;font-size:12px;margin-top:6px">{{ $message }}</div>@enderror
                </div>

                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" class="form-input" placeholder="What is this role for?" maxlength="500">{{ old('description') }}</textarea>
                    @error('description')<div style="color:#ef4444;font-size:12px;margin-top:6px">{{ $message }}</div>@enderror
                </div>

                <div class="form-group">
                    <label for="priority">Priority</label>
                    <input type="number" id="priority" name="priority" class="form-input" value="{{ old('priority', 100) }}" min="0" max="9999">
                    <div style="font-size:11px;color:#666;margin-top:4px">Lower numbers sort first in the role list. Default 100.</div>
                    @error('priority')<div style="color:#ef4444;font-size:12px;margin-top:6px">{{ $message }}</div>@enderror
                </div>

                <div style="display:flex;gap:10px;margin-top:20px">
                    <button type="submit" class="btn btn-gold">Create &amp; Assign Permissions &rarr;</button>
                    <a href="{{ route('admin.roles.index') }}" class="btn btn-ghost">Cancel</a>
                </div>
            </form>
        </div>
    </div>

</x-admin.layout>
