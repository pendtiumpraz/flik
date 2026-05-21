<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * Menu Matrix — visual audit of "which role sees which sidebar entry".
 *
 * Renders a 2-D table: rows = sidebar items (label + permission), columns =
 * roles, cells = whether that role can see that link. The source of truth is
 * `config/admin_menu.php` (same file the sidebar renders from), so the matrix
 * is structurally impossible to drift from what users see.
 *
 * Schema reality (FIX #6, AUDIT #2 §2.3): the `roles` table has columns
 * `name` + `display_name` (no `slug`), and the pivot is `permission_role`
 * (not `role_permission`). The previous version queried the wrong columns
 * and silently fell back to a `heuristicGate()` that referenced stale role
 * constants — operators saw a misleading matrix. This rewrite reads the
 * real schema directly.
 *
 * Role discovery:
 *   - Read every row from `roles` ordered by priority then id.
 *   - Map: name => display_name (or name if no display_name).
 *
 * Permission resolution per (role, permission) cell:
 *   - super_admin → always ✓ (matches Gate::before short-circuit).
 *   - permission == null → always ✓ (no fine-grained gate).
 *   - otherwise → query the `permission_role` pivot joined to `permissions`
 *     and `roles` by name. One query per cell is acceptable here because
 *     the matrix is small (≤ 10 roles × ≤ 60 menu items) and admin-only,
 *     but we still memoise the per-role permission set to skip repeated
 *     joins inside a single render.
 *
 * Auth: `roles.manage` permission (with `admin` Gate fallback for the
 * pre-rollout window).
 */
class MenuMatrixController extends Controller
{
    /**
     * Per-render memo: role name => set of permission names.
     *
     * @var array<string, array<int, string>>
     */
    private array $rolePermissionsCache = [];

    public function index(Request $request): View
    {
        $this->authorizeAccess($request);

        $sections = (array) config('admin_menu.sections', []);
        $roles = $this->discoverRoles();
        $matrix = $this->buildMatrix($sections, $roles);
        $categoryFilter = (string) $request->query('category', '');
        $availableCategories = $this->categoryList($sections);

        // Apply category filter post-build so the cells remain consistent.
        if ($categoryFilter !== '' && in_array($categoryFilter, $availableCategories, true)) {
            $matrix = array_values(array_filter(
                $matrix,
                fn (array $row): bool => $row['category'] === $categoryFilter
            ));
        }

        // Build a name => id lookup so the view's "Edit role" link can
        // resolve {role} via the default route-key binding (id) without
        // an extra query per column header.
        $roleIds = Role::query()->pluck('id', 'name')->all();

        return view('admin.menu-matrix.index', [
            'matrix' => $matrix,
            'roles' => $roles,
            'roleIds' => $roleIds,
            'categories' => $availableCategories,
            'activeCategory' => $categoryFilter,
            'permissionsTableExists' => Schema::hasTable('permissions'),
        ]);
    }

    /**
     * Gate guard. We try the granular `roles.manage` permission first, then
     * fall back to the legacy `admin` gate so this page is reachable for
     * super-admins even before peer ROLE #1/#2 has shipped.
     */
    private function authorizeAccess(Request $request): void
    {
        $user = $request->user();
        if ($user === null) {
            abort(403);
        }

        if (Gate::has('roles.manage') && Schema::hasTable('permissions')) {
            if (! Gate::allows('roles.manage')) {
                abort(403);
            }

            return;
        }

        // Pre-rollout fallback: admin gate.
        if (! Gate::allows('admin')) {
            abort(403);
        }
    }

    /**
     * Returns [role_name => display_label] from the `roles` table, ordered
     * by priority (lowest first → super_admin appears leftmost). Returns an
     * empty list when the table is missing — the view renders a single
     * "no roles configured" column rather than crashing.
     *
     * @return array<string, string>
     */
    private function discoverRoles(): array
    {
        if (! Schema::hasTable('roles')) {
            return [];
        }

        $rows = DB::table('roles')
            ->orderBy('priority')
            ->orderBy('id')
            ->get(['name', 'display_name']);

        $map = [];
        foreach ($rows as $r) {
            $name = (string) $r->name;
            if ($name === '') {
                continue;
            }
            $map[$name] = (string) ($r->display_name ?? $name);
        }

        return $map;
    }

    /**
     * Build one row per menu item.
     *
     * @param  array<string, array<string, mixed>>  $sections
     * @param  array<string, string>  $roles
     * @return array<int, array<string, mixed>>
     */
    private function buildMatrix(array $sections, array $roles): array
    {
        $rows = [];

        foreach ($sections as $section) {
            $sectionLabel = (string) ($section['label'] ?? 'Section');
            $category = (string) ($section['category'] ?? 'misc');

            foreach (($section['items'] ?? []) as $item) {
                $permission = $item['permission'] ?? null;
                $access = [];
                foreach ($roles as $name => $_label) {
                    $access[$name] = $this->roleCanSee($name, $permission);
                }

                $rows[] = [
                    'section_label' => $sectionLabel,
                    'category' => $category,
                    'label' => (string) ($item['label'] ?? 'Item'),
                    'route' => $item['route'] ?? null,
                    'permission' => $permission,
                    'icon' => $item['icon'] ?? null,
                    'access' => $access,
                ];
            }
        }

        return $rows;
    }

    /**
     * Truth table for "does role $roleName see a link guarded by $permission?".
     *
     * Resolution order:
     *   1. permission is null         → visible (everyone in the admin gate
     *                                    can reach it).
     *   2. role is super_admin        → visible (Gate::before short-circuit).
     *   3. consult the permission_role pivot via permission name.
     */
    private function roleCanSee(string $roleName, ?string $permission): bool
    {
        if ($permission === null) {
            return true;
        }
        if ($roleName === 'super_admin') {
            return true;
        }

        if (! Schema::hasTable('permission_role')
            || ! Schema::hasTable('permissions')
            || ! Schema::hasTable('roles')
        ) {
            return false;
        }

        return in_array($permission, $this->permissionsForRole($roleName), true);
    }

    /**
     * Memoised per-render permission set for one role. Returns an empty
     * array when the role doesn't exist or has no permissions attached.
     *
     * @return array<int, string>
     */
    private function permissionsForRole(string $roleName): array
    {
        if (isset($this->rolePermissionsCache[$roleName])) {
            return $this->rolePermissionsCache[$roleName];
        }

        $names = DB::table('roles as r')
            ->join('permission_role as pr', 'pr.role_id', '=', 'r.id')
            ->join('permissions as p', 'p.id', '=', 'pr.permission_id')
            ->where('r.name', $roleName)
            ->pluck('p.name')
            ->all();

        $this->rolePermissionsCache[$roleName] = array_values(array_map('strval', $names));

        return $this->rolePermissionsCache[$roleName];
    }

    /**
     * @param  array<string, array<string, mixed>>  $sections
     * @return array<int, string>
     */
    private function categoryList(array $sections): array
    {
        $cats = [];
        foreach ($sections as $section) {
            $cat = (string) ($section['category'] ?? '');
            if ($cat !== '' && ! in_array($cat, $cats, true)) {
                $cats[] = $cat;
            }
        }
        sort($cats);

        return $cats;
    }
}
