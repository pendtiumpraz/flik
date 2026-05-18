<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Role CRUD + permission-matrix editor.
 *
 * Contract:
 *   - Peer ROLE #1 owns the `roles` and `permissions` tables + the
 *     Role/Permission Eloquent models and their `permissions()` /
 *     `users()` / `roles()` pivot relations. This controller assumes
 *     that contract: a `Role` has many Permissions via `permissions()`
 *     and many Users via `users()`; `Permission::category` groups
 *     checkboxes in the matrix view.
 *   - Peer ROLE #2 owns `User::hasRole()` / `User::hasPermission()`.
 *   - Every method is gated by the `roles.manage` ability (defined by
 *     peer ROLE #3) so non-super-admins can be granted RBAC-management
 *     without granting full super-admin.
 *
 * System roles (`is_system = true`) are protected:
 *   - their `name` (machine identifier) cannot be renamed (display_name
 *     and permissions still editable),
 *   - they cannot be deleted.
 *
 * Every destructive or mutating action emits an audit_logs row via
 * AuditLogger::security() with the 'admin.role.*' action prefix so the
 * `/admin/audit-logs` security filter surfaces RBAC changes.
 */
class RoleController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * List roles with counts of attached permissions and users.
     *
     * `withCount` is used instead of eager-loading the full collections
     * because the index only displays totals — fetching every pivot row
     * would balloon memory when there are dozens of permissions per role.
     */
    public function index(): View
    {
        $this->authorize('roles.manage');

        $roles = Role::query()
            ->withCount(['permissions', 'users'])
            ->orderBy('priority')
            ->orderBy('name')
            ->get();

        return view('admin.roles.index', compact('roles'));
    }

    /**
     * Show the create-role form.
     */
    public function create(): View
    {
        $this->authorize('roles.manage');

        return view('admin.roles.create');
    }

    /**
     * Persist a new role and redirect to its edit page so the admin can
     * pick permissions immediately (the matrix is too large for a single
     * create form).
     *
     * Validation:
     *   - `name` is the machine identifier — lowercase letters / digits /
     *     underscores only. Spaces are explicitly rejected per the task
     *     spec (a separate check, not just regex, so the error message is
     *     human-readable).
     *   - `display_name` is the human label shown in badges.
     *   - `priority` controls sort order on the index (lower = higher in list).
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('roles.manage');

        // Reject whitespace up-front with a friendly error before the regex
        // rule fires — the regex error is less obvious to operators.
        if ($request->filled('name') && preg_match('/\s/', (string) $request->input('name'))) {
            return back()
                ->withErrors(['name' => 'Role name cannot contain spaces — use underscores instead.'])
                ->withInput();
        }

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:64',
                'regex:/^[a-z0-9_]+$/',
                'unique:roles,name',
            ],
            'display_name' => 'required|string|max:120',
            'description' => 'nullable|string|max:500',
            'priority' => 'nullable|integer|min:0|max:9999',
        ], [
            'name.regex' => 'Role name must contain only lowercase letters, digits, and underscores.',
            'name.unique' => 'A role with that name already exists.',
        ]);

        $role = Role::create([
            'name' => $validated['name'],
            'display_name' => $validated['display_name'],
            'description' => $validated['description'] ?? null,
            'priority' => $validated['priority'] ?? 100,
            'is_system' => false,
        ]);

        $this->audit->security(
            event: 'admin.role.created',
            subject: $role,
            meta: [
                'name' => $role->name,
                'display_name' => $role->display_name,
                'priority' => $role->priority,
            ],
        );

        return redirect()
            ->route('admin.roles.edit', $role)
            ->with('success', "Role \"{$role->display_name}\" created. Assign permissions below.");
    }

    /**
     * Show the edit form: top fields + permission matrix grouped by category.
     *
     * The matrix shape is `category => [Permission, ...]` because the view
     * renders one <fieldset> per category. Permissions without a `category`
     * column value are bucketed under "General" so they're never lost.
     */
    public function edit(Role $role): View
    {
        $this->authorize('roles.manage');

        // groupBy on a Collection returns an ordered map — we sort by key
        // so the fieldsets render alphabetically by category. The "General"
        // bucket is hoisted to the end so named groups appear first.
        $permissions = Permission::query()
            ->orderBy('category')
            ->orderBy('name')
            ->get()
            ->groupBy(fn (Permission $p) => $p->category ?: 'General')
            ->sortKeys();

        $assigned = $role->permissions()->pluck('permissions.id')->all();

        return view('admin.roles.edit', [
            'role' => $role,
            'groupedPermissions' => $permissions,
            'assignedPermissionIds' => $assigned,
        ]);
    }

    /**
     * Update role fields and sync permissions.
     *
     * For system roles, `name` is locked (the column is omitted from the
     * validated set entirely so a crafted payload can't bypass the UI's
     * disabled attribute).
     */
    public function update(Request $request, Role $role): RedirectResponse
    {
        $this->authorize('roles.manage');

        $rules = [
            'display_name' => 'required|string|max:120',
            'description' => 'nullable|string|max:500',
            'priority' => 'nullable|integer|min:0|max:9999',
            'permissions' => 'array',
            'permissions.*' => 'integer|exists:permissions,id',
        ];

        // System roles cannot be renamed. Skip the rule AND ignore any
        // submitted `name` value so a tampered form can't rename them.
        if (! $role->is_system) {
            if ($request->filled('name') && preg_match('/\s/', (string) $request->input('name'))) {
                return back()
                    ->withErrors(['name' => 'Role name cannot contain spaces — use underscores instead.'])
                    ->withInput();
            }

            $rules['name'] = [
                'required',
                'string',
                'max:64',
                'regex:/^[a-z0-9_]+$/',
                'unique:roles,name,'.$role->id,
            ];
        }

        $validated = $request->validate($rules, [
            'name.regex' => 'Role name must contain only lowercase letters, digits, and underscores.',
            'name.unique' => 'A role with that name already exists.',
        ]);

        // Snapshot before-state for the audit row so reviewers can see the
        // delta without joining against a prior log entry.
        $before = [
            'display_name' => $role->display_name,
            'description' => $role->description,
            'priority' => $role->priority,
            'permissions' => $role->permissions()->pluck('permissions.id')->all(),
        ];

        $updates = [
            'display_name' => $validated['display_name'],
            'description' => $validated['description'] ?? null,
            'priority' => $validated['priority'] ?? $role->priority,
        ];

        if (! $role->is_system && isset($validated['name'])) {
            $updates['name'] = $validated['name'];
        }

        // Wrap the field update + pivot sync in a transaction so a partial
        // failure doesn't leave the matrix half-updated.
        DB::transaction(function () use ($role, $updates, $validated) {
            $role->update($updates);
            $role->permissions()->sync($validated['permissions'] ?? []);
        });

        $this->audit->security(
            event: 'admin.role.updated',
            subject: $role->fresh(),
            meta: [
                'before' => $before,
                'after' => [
                    'display_name' => $role->display_name,
                    'description' => $role->description,
                    'priority' => $role->priority,
                    'permissions' => $validated['permissions'] ?? [],
                ],
                'is_system' => (bool) $role->is_system,
            ],
        );

        return redirect()
            ->route('admin.roles.edit', $role)
            ->with('success', "Role \"{$role->display_name}\" updated.");
    }

    /**
     * Delete a role.
     *
     * Aborts 403 for `is_system` roles even though the UI hides the button —
     * never trust the client. Detaches users + permissions inside a
     * transaction so an FK error mid-way doesn't orphan pivot rows.
     */
    public function destroy(Role $role): RedirectResponse
    {
        $this->authorize('roles.manage');

        abort_if($role->is_system, 403, 'System roles cannot be deleted.');

        $snapshot = [
            'id' => $role->id,
            'name' => $role->name,
            'display_name' => $role->display_name,
            'user_count' => $role->users()->count(),
            'permission_count' => $role->permissions()->count(),
        ];

        DB::transaction(function () use ($role) {
            // Order matters only if the FKs are NOT set up with cascade
            // delete — peer #1's migration is expected to use cascading
            // pivots, but the explicit detach is defensive and cheap.
            $role->users()->detach();
            $role->permissions()->detach();
            $role->delete();
        });

        $this->audit->security(
            event: 'admin.role.deleted',
            meta: $snapshot,
        );

        return redirect()
            ->route('admin.roles.index')
            ->with('success', "Role \"{$snapshot['display_name']}\" deleted.");
    }
}
