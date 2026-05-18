<x-admin.layout title="Edit Role — {{ $role->display_name }}">

    <div style="margin-bottom:20px">
        <a href="{{ route('admin.roles.index') }}" style="color:#888;font-size:13px;text-decoration:none">&larr; Back to Roles</a>
    </div>

    @if($role->is_system)
        <div style="background:rgba(245,158,11,0.12);border:1px solid rgba(245,158,11,0.35);border-radius:10px;padding:14px 18px;margin-bottom:20px;display:flex;gap:12px;align-items:flex-start">
            <span style="font-size:18px;color:#f59e0b;line-height:1">!</span>
            <div>
                <div style="font-weight:600;color:#f59e0b;font-size:14px">System role</div>
                <div style="font-size:13px;color:#d4a55a;margin-top:2px">This role is protected — the machine name cannot be changed and it cannot be deleted, but you may freely adjust its permissions, display name, description, and priority.</div>
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.roles.update', $role) }}" x-data="rolePermMatrix()">
        @csrf @method('PUT')

        {{-- ─── Top: editable role fields ─────────────────────────── --}}
        <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:24px;margin-bottom:20px">
            <h2 style="font-size:16px;font-weight:600;margin-bottom:18px">Role Details</h2>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                <div class="form-group" style="margin-bottom:0">
                    <label for="name">Name (machine identifier)</label>
                    <input type="text" id="name" name="name" class="form-input"
                           value="{{ old('name', $role->name) }}"
                           @if($role->is_system) disabled readonly @endif
                           maxlength="64" pattern="[a-z0-9_]+">
                    @if($role->is_system)
                        <div style="font-size:11px;color:#888;margin-top:4px">Locked for system roles.</div>
                    @else
                        <div style="font-size:11px;color:#666;margin-top:4px">Lowercase letters, digits, and underscores only.</div>
                    @endif
                    @error('name')<div style="color:#ef4444;font-size:12px;margin-top:6px">{{ $message }}</div>@enderror
                </div>

                <div class="form-group" style="margin-bottom:0">
                    <label for="display_name">Display Name <span style="color:#ef4444">*</span></label>
                    <input type="text" id="display_name" name="display_name" class="form-input"
                           value="{{ old('display_name', $role->display_name) }}" required maxlength="120">
                    @error('display_name')<div style="color:#ef4444;font-size:12px;margin-top:6px">{{ $message }}</div>@enderror
                </div>

                <div class="form-group" style="grid-column:1 / -1;margin-bottom:0">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" class="form-input" maxlength="500">{{ old('description', $role->description) }}</textarea>
                    @error('description')<div style="color:#ef4444;font-size:12px;margin-top:6px">{{ $message }}</div>@enderror
                </div>

                <div class="form-group" style="margin-bottom:0">
                    <label for="priority">Priority</label>
                    <input type="number" id="priority" name="priority" class="form-input"
                           value="{{ old('priority', $role->priority) }}" min="0" max="9999">
                    <div style="font-size:11px;color:#666;margin-top:4px">Lower numbers appear first in lists.</div>
                    @error('priority')<div style="color:#ef4444;font-size:12px;margin-top:6px">{{ $message }}</div>@enderror
                </div>
            </div>
        </div>

        {{-- ─── Middle: permission matrix ─────────────────────────── --}}
        <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:24px;margin-bottom:20px">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:12px">
                <div>
                    <h2 style="font-size:16px;font-weight:600">Permission Matrix</h2>
                    <p style="font-size:13px;color:#888;margin-top:2px">
                        <span x-text="checkedCount"></span> of <span x-text="totalCount"></span> permissions selected
                    </p>
                </div>
                <div style="display:flex;gap:8px">
                    <button type="button" class="btn btn-ghost btn-sm" @click="selectAll(true)">Select All</button>
                    <button type="button" class="btn btn-ghost btn-sm" @click="selectAll(false)">Deselect All</button>
                </div>
            </div>

            @if($groupedPermissions->isEmpty())
                <div style="background:#0f0f0f;border:1px dashed #2a2a2a;border-radius:8px;padding:32px;text-align:center;color:#666">
                    No permissions exist yet. Seed the `permissions` table to populate this matrix.
                </div>
            @else
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(360px,1fr));gap:16px">
                    @foreach($groupedPermissions as $category => $perms)
                        @php
                            $catSlug = \Illuminate\Support\Str::slug($category, '_');
                        @endphp
                        <fieldset style="background:#0f0f0f;border:1px solid #2a2a2a;border-radius:10px;padding:14px 16px"
                                  data-category="{{ $catSlug }}">
                            <legend style="padding:0 8px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:1px;color:#C5A55A">
                                {{ $category }}
                                <span style="color:#666;font-weight:500;letter-spacing:0;text-transform:none;margin-left:6px">({{ $perms->count() }})</span>
                            </legend>

                            <label style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid #1f1f1f;margin-bottom:8px;cursor:pointer;font-size:12px;color:#888">
                                <input type="checkbox"
                                       @change="toggleCategory('{{ $catSlug }}', $event.target.checked)"
                                       :checked="isCategoryFullyChecked('{{ $catSlug }}')"
                                       style="accent-color:#C5A55A;width:14px;height:14px">
                                <span>Select all in {{ $category }}</span>
                            </label>

                            <div style="display:flex;flex-direction:column;gap:8px">
                                @foreach($perms as $perm)
                                    <label style="display:flex;gap:10px;align-items:flex-start;cursor:pointer;padding:6px 4px;border-radius:6px;transition:background 0.15s"
                                           onmouseover="this.style.background='#1a1a1a'" onmouseout="this.style.background='transparent'">
                                        <input type="checkbox"
                                               name="permissions[]"
                                               value="{{ $perm->id }}"
                                               data-category="{{ $catSlug }}"
                                               class="perm-checkbox"
                                               @click="updateCounts"
                                               @checked(in_array($perm->id, old('permissions', $assignedPermissionIds), true))
                                               style="accent-color:#C5A55A;width:15px;height:15px;margin-top:2px;flex-shrink:0">
                                        <div style="flex:1;min-width:0">
                                            <div style="font-size:13px;color:#fff;font-weight:500;line-height:1.3">
                                                {{ $perm->display_name ?? $perm->name }}
                                            </div>
                                            <code style="font-size:11px;color:#C5A55A;display:block;margin-top:2px;font-family:'JetBrains Mono',monospace">{{ $perm->name }}</code>
                                            @if(!empty($perm->description))
                                                <div style="font-size:11px;color:#777;margin-top:3px;line-height:1.4">{{ $perm->description }}</div>
                                            @endif
                                        </div>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- ─── Save / Cancel ─────────────────────────────────────── --}}
        <div style="display:flex;gap:10px;justify-content:flex-end;background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:16px 20px;position:sticky;bottom:16px;backdrop-filter:blur(8px)">
            <a href="{{ route('admin.roles.index') }}" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-gold">Save Changes</button>
        </div>
    </form>

    <script>
        function rolePermMatrix() {
            return {
                checkedCount: 0,
                totalCount: 0,
                init() {
                    this.updateCounts();
                    // Re-render the "select all in category" header checkboxes
                    // whenever a child checkbox toggles so the parent reflects
                    // mixed/full/empty state.
                    this.$root.querySelectorAll('.perm-checkbox').forEach(cb => {
                        cb.addEventListener('change', () => this.updateCounts());
                    });
                },
                updateCounts() {
                    const all = this.$root.querySelectorAll('.perm-checkbox');
                    this.totalCount = all.length;
                    this.checkedCount = Array.from(all).filter(c => c.checked).length;
                },
                selectAll(state) {
                    this.$root.querySelectorAll('.perm-checkbox').forEach(cb => { cb.checked = state; });
                    this.updateCounts();
                },
                toggleCategory(catSlug, state) {
                    this.$root.querySelectorAll('.perm-checkbox[data-category="' + catSlug + '"]')
                        .forEach(cb => { cb.checked = state; });
                    this.updateCounts();
                },
                isCategoryFullyChecked(catSlug) {
                    const list = this.$root.querySelectorAll('.perm-checkbox[data-category="' + catSlug + '"]');
                    if (list.length === 0) return false;
                    return Array.from(list).every(cb => cb.checked);
                },
            };
        }
    </script>

</x-admin.layout>
