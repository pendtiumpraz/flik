<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
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
 * Role discovery order (best-effort, peer-swarm-tolerant):
 *   1. If the `roles` table exists (created by ROLE peer #1), pull labels +
 *      slugs from there so admin-defined roles render alongside the seeded set.
 *   2. Otherwise fall back to `User::ROLES` (the hardcoded staff taxonomy
 *      declared in app/Models/User.php).
 *
 * Permission resolution order per (role, permission) cell:
 *   1. If the `permissions` table + `role_permission` pivot exist AND the
 *      named permission is registered as a Gate, use the role->permissions
 *      relation defined by ROLE peer #2.
 *   2. Otherwise fall back to the Gate definitions registered in
 *      AuthServiceProvider for the named coarse abilities (admin / manage-*)
 *      so the matrix still tells the truth pre-rollout.
 *   3. Super-admin: always ✓ (matches Gate::before short-circuit).
 *
 * Auth: `roles.manage` permission (with `admin` Gate fallback for the
 * pre-rollout window).
 */
class MenuMatrixController extends Controller
{
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

        return view('admin.menu-matrix.index', [
            'matrix' => $matrix,
            'roles' => $roles,
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
     * Returns a list of [slug => label] role descriptors. Pulls from the
     * Roles table when ROLE peer #1's migration has run; otherwise reads
     * the hardcoded User::ROLES staff taxonomy.
     *
     * @return array<string, string> slug => human label
     */
    private function discoverRoles(): array
    {
        if (Schema::hasTable('roles')) {
            try {
                $rows = \DB::table('roles')
                    ->orderBy('id')
                    ->get(['slug', 'name']);

                $map = [];
                foreach ($rows as $r) {
                    $map[(string) $r->slug] = (string) ($r->name ?? $r->slug);
                }
                if ($map !== []) {
                    return $map;
                }
            } catch (\Throwable $e) {
                // Schema diverges from expected → fall through.
            }
        }

        return User::ROLES;
    }

    /**
     * Build one row per menu item. Each row carries:
     *   - section_label  (string)
     *   - category       (string)
     *   - label          (string)
     *   - route          (?string)
     *   - permission     (?string)
     *   - icon           (?string)
     *   - access         (array<string, bool>)  slug => allowed
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
                foreach ($roles as $slug => $_label) {
                    $access[$slug] = $this->roleCanSee($slug, $permission);
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
     * Truth table for "does role $slug see a link guarded by $permission?".
     *
     * Layered resolution:
     *   - permission null         → always visible (everyone in the admin
     *                                gate can reach it).
     *   - super_admin             → always visible (matches Gate::before
     *                                short-circuit in AuthServiceProvider).
     *   - peer ROLE #1/#2 live    → consult role_permission pivot via the
     *                                role's permission relation.
     *   - otherwise               → translate the named permission to one of
     *                                the coarse Gates in AuthServiceProvider
     *                                so the matrix tells the truth pre-rollout.
     */
    private function roleCanSee(string $roleSlug, ?string $permission): bool
    {
        if ($permission === null) {
            return true;
        }
        if ($roleSlug === User::ROLE_SUPER_ADMIN) {
            return true;
        }

        // Preferred path: query the pivot once ROLE peer #2 has shipped.
        if (Schema::hasTable('permissions') && Schema::hasTable('role_permission') && Schema::hasTable('roles')) {
            try {
                $exists = \DB::table('roles as r')
                    ->join('role_permission as rp', 'rp.role_id', '=', 'r.id')
                    ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
                    ->where('r.slug', $roleSlug)
                    ->where('p.name', $permission)
                    ->exists();

                return $exists;
            } catch (\Throwable $e) {
                // Schema mismatch → fall through to the heuristic.
            }
        }

        // Pre-rollout heuristic: map the named permission to the closest
        // coarse Gate already registered in AuthServiceProvider so the
        // matrix is not empty during the swarm hand-off window.
        return $this->heuristicGate($roleSlug, $permission);
    }

    /**
     * Map a granular permission name (e.g. `movies.update`, `analytics.churn`)
     * to one of the pre-existing coarse Gates so the matrix is meaningful
     * before peer ROLE #2 wires every permission as its own Gate.
     *
     * The buckets here intentionally mirror the role specialties so the
     * matrix produces sensible defaults: content_manager sees content +
     * basic AI, customer_support sees users + comments, finance sees
     * revenue/analytics, super_admin sees everything.
     */
    private function heuristicGate(string $roleSlug, string $permission): bool
    {
        // Content / catalog
        $contentPrefixes = ['movies.', 'subtitles.'];
        if ($this->startsWithAny($permission, $contentPrefixes)) {
            return in_array($roleSlug, [User::ROLE_CONTENT_MANAGER], true);
        }

        // Comments + sentiment → customer support
        if ($this->startsWithAny($permission, ['comments.'])) {
            return in_array($roleSlug, [User::ROLE_CUSTOMER_SUPPORT, User::ROLE_CONTENT_MANAGER], true);
        }

        // Users + roles → support + super admin only
        if ($this->startsWithAny($permission, ['users.', 'roles.'])) {
            return $roleSlug === User::ROLE_CUSTOMER_SUPPORT;
        }

        // Analytics / revenue → finance
        if ($this->startsWithAny($permission, ['analytics.'])) {
            return $roleSlug === User::ROLE_FINANCE;
        }

        // Marketing — content manager runs campaigns
        if ($this->startsWithAny($permission, ['marketing.'])) {
            return $roleSlug === User::ROLE_CONTENT_MANAGER;
        }

        // AI providers / usage / tasks — content manager runs tasks,
        // super-admin configures providers (already covered by short-circuit).
        if ($permission === 'ai.tasks.run' || $permission === 'ai.usage.view') {
            return $roleSlug === User::ROLE_CONTENT_MANAGER;
        }
        if ($permission === 'ai.providers.configure') {
            return false; // super-admin only (handled by short-circuit)
        }

        // Security — super-admin only by default
        if ($this->startsWithAny($permission, ['security.'])) {
            return false;
        }

        return false;
    }

    /**
     * @param  array<int, string>  $prefixes
     */
    private function startsWithAny(string $value, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return true;
            }
        }

        return false;
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
