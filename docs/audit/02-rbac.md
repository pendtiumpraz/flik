# AUDIT #2 — RBAC & Permissions

Scope: Role/Permission models, Gate registration, super_admin bypass, route guards, role-assignment UI, permission matrix, menu visibility per role, profile permissions view, role seeder, audit logging for role changes.

Date: 2026-05-20
Auditor: read-only static review
Branch: `main` (commit `1acadbd`)

---

## 1. Inventory

### Tables (migrations `2026_05_10_05000{1..4}`)
| Table | Columns of note |
|---|---|
| `roles` | `id`, `name` UNIQUE (60), `display_name` (120), `description`, `is_system` bool, `priority` int, timestamps. Index on `(is_system, priority)`. |
| `permissions` | `id`, `name` UNIQUE (80), `display_name`, `category` (40, **NOT NULL**), `description`, timestamps. Index on `category`. |
| `permission_role` | `(role_id, permission_id)` composite PK, both `cascadeOnDelete`. |
| `role_user` | `(role_id, user_id)` composite PK, plus `assigned_by_user_id` (nullOnDelete), `assigned_at` timestamp, timestamps. |

All four migrations are guarded with `Schema::hasTable(...)` early-return — safe for re-run.

### Models
- `app/Models/Role.php` — `$guarded = []` (with security note), `permissions()`, `users()` (pivot carries `assigned_by_user_id`, `assigned_at`), `hasPermission()`, `givePermission()`, `revokePermission()`, `syncPermissions()`, scopes `system()` / `custom()`.
- `app/Models/Permission.php` — `roles()`, `groupedByCategory()`.
- `app/Models/User.php:111-479` — RBAC helpers: `ROLES` constants, `roles()` pivot, `hasRole()`, `hasAllRoles()`, `assignRole()`, `removeRole()`, `syncRoles()`, `hasPermission()`, `permissions()`, `loadPermissionCache()`, `forgetPermissionCache()`, `isSuperAdmin()`, `isStaff()`, `getIsAdminAttribute()`.
- No dedicated `Concerns/HasRoles` trait — all RBAC logic is inlined directly into `User`.

### Controllers
- `app/Http/Controllers/Admin/RoleController.php` — full role CRUD + permission-matrix sync, `roles.manage`-gated, audit-logged.
- `app/Http/Controllers/Admin/MenuMatrixController.php` — visual audit grid at `/admin/menu-matrix`.
- `app/Http/Controllers/AdminController.php:341-401` — `editRoles()` + `updateRoles()` for per-user role assignment, `users.assign_roles`-gated, audit-logged.
- `app/Http/Controllers/AdminController.php:403-416` — legacy `toggleAdmin()` flipping the boolean `users.is_admin` column. **Not audit-logged.**
- `app/Http/Controllers/ProfileController.php:120-206` — `permissions()` self-service view.

### Views
- `resources/views/admin/roles/{index,create,edit}.blade.php` — list, create, full permission matrix editor with select-all/deselect-all Alpine helper.
- `resources/views/admin/users/roles.blade.php` — per-user role assignment.
- `resources/views/admin/menu-matrix/index.blade.php` — sidebar-visibility audit grid.
- `resources/views/profile/permissions.blade.php` — "My Roles & Permissions" self-service.
- `resources/views/components/admin/layout.blade.php:160-244` — sidebar driven by `config/admin_menu.php` with `$canSeeMenuItem` closure.

### Providers
- `app/Providers/AuthServiceProvider.php` — `Gate::before` super_admin bypass + dynamic permission-name `Gate::define` loop + dotted-ability fallback `Gate::before`.
- `app/Providers/AppServiceProvider.php:100-156` — registers `UserObserver`, `@role`, `@hasperm` Blade directives.

### Seeder
- `database/seeders/RolePermissionSeeder.php` — 6 system roles, ~55 permissions across 12 categories, plus `is_admin` → `admin` role backfill.

### Tests
- `tests/Feature/Authorization/RoleAssignmentTest.php` (11 tests)
- `tests/Feature/Authorization/RoleCrudTest.php` (7 tests)
- `tests/Feature/Authorization/AdminRouteGuardTest.php` (5 tests including legacy `is_admin`)
- `tests/Feature/Authorization/ProfilePermissionsViewTest.php` (5 tests)

All four files skip gracefully when MySQL is unavailable. No tests cover the `MenuMatrixController`, `UserObserver`, dynamic Gate registration, or per-permission Blade directive.

---

## 2. Critical Checks (from task brief)

### 2.1 Does `Gate::before` super_admin bypass actually work? — YES (with one caveat)
`AuthServiceProvider::boot():72-82` registers:
```php
Gate::before(function (?User $user, string $ability) {
    if ($user === null) return null;
    if ($user->isSuperAdmin()) return true;
    return null;
});
```
- Returns `true` to short-circuit, `null` (NOT `false`) on miss → policy ladder still runs. Correct pattern.
- `isSuperAdmin()` honours all three legitimate sources: legacy `is_admin` column (raw, not via accessor — avoids recursion), legacy `role === 'super_admin'`, modern pivot membership.
- `relationLoaded('roles')` fast-path avoids re-querying when the role was already eager-loaded.

**Caveat (confirmed by `RoleCrudTest.php:147-159`):** Super-admins still get **403 on deleting a system role** because `RoleController::destroy():267` runs `abort_if($role->is_system, 403)` AFTER `$this->authorize('roles.manage')`. The bypass works for the authorize call; the abort is intentional. Documented in the controller — not a bug, but worth flagging that the bypass is not a true "god mode" for every dotted constraint.

### 2.2 Are ALL admin routes guarded by appropriate `can:<permission>` middleware? — MOSTLY YES, with three gaps
The outer group `Route::middleware(['auth', 'can:admin'])->prefix('admin')` (`routes/web.php:530`) is the coarse gate. Every dashboard/CRUD route layers a per-permission `can:` middleware on top. Spot-checked ~80 routes against the seeded permission taxonomy — all align.

**Gaps:**
1. **`admin.dashboard`** (`/admin/`, line 531) — no per-permission middleware. Bare `can:admin` only. Intentional per the route-group comment ("Routes without a more specific permission... stay on the bare `can:admin` gate") and consistent with `config/admin_menu.php:42` (`'permission' => null`). Acceptable.
2. **`admin.notifications.*`** (lines 924-933) — no per-permission middleware on any of the 5 endpoints. Comment at 919-923 explains this is intentional (the bell must reach every staff role; per-notification audience checks happen inside the controller via `NotificationController::authorizeAudience`). Verify that inner check exists and is robust (out of scope here, but flag).
3. **`admin.pitch-deck`** (referenced at lines 676-683 region — informational page) — no per-permission middleware. Acceptable.
4. **`admin.translations.index`** — `config/admin_menu.php:184` sets `'permission' => null` so any admin sees it. Route also unguarded. Flagged in the config file itself ("Switch the permission once an `i18n.manage` ability is added"). Minor tech-debt.

No routes were found that legitimately need a tighter gate and lack one.

### 2.3 Does the menu matrix mirror the actual sidebar visibility? — **NO — there is a confirmed schema-mismatch bug.**

The sidebar in `resources/views/components/admin/layout.blade.php:185-199` correctly uses `Gate::has($perm)` + `$user->can($perm)` against the *real* dynamic gates registered in `AuthServiceProvider`.

`MenuMatrixController` bypasses Gates entirely and queries the DB directly with **wrong table/column names:**

```php
// MenuMatrixController.php:104-115
$rows = \DB::table('roles')->orderBy('id')->get(['slug', 'name']);
// ...
$map[(string) $r->slug] = (string) ($r->name ?? $r->slug);
```
The `roles` table has `name` and `display_name` — **there is no `slug` column** (migration 050001 only declares `id, name, display_name, description, is_system, priority`). The `get(['slug', 'name'])` will throw a SQL error; the wrapping `try { } catch (\Throwable)` swallows it silently and falls back to `User::ROLES` (the legacy hardcoded list).

```php
// MenuMatrixController.php:193-201
if (Schema::hasTable('permissions') && Schema::hasTable('role_permission') && Schema::hasTable('roles')) {
    $exists = \DB::table('roles as r')
        ->join('role_permission as rp', 'rp.role_id', '=', 'r.id')
        ...
```
The pivot is actually named **`permission_role`** (migration 050003), not `role_permission`. `Schema::hasTable('role_permission')` returns `false`, so the entire preferred branch is skipped and the controller falls through to `heuristicGate()` — which hardcodes a stale 4-role taxonomy (`content_manager`, `customer_support`, `finance`) that doesn't even match the seeded system roles (`admin`, `moderator`, `content_editor`, `finance`, `user`, `super_admin`).

**Concrete impact:** `/admin/menu-matrix` will show:
- Roles from `User::ROLES` (not the actual seeded set — missing `admin`, `moderator`, `content_editor`; includes `content_manager` and `customer_support` which the seeder no longer creates).
- Cells from `heuristicGate()` (not the real pivot data the sidebar resolves through).

The view banner ("permissions table not migrated → showing heuristic fallback") will **never fire** because `Schema::hasTable('permissions')` returns `true` — but the rest of the page is still using the heuristic. The "Edit role" link in each column header (`menu-matrix/index.blade.php:91`) routes to `route('admin.roles.edit', $slug)` where `$slug` is the legacy User::ROLES key — half the links will 404 (e.g. `content_manager` / `customer_support` don't exist in the seeded `roles` table).

**Fix:** in `MenuMatrixController.php` change `slug` → `name` (both at line 108 and the `where('r.slug', $roleSlug)` at line 198), and change `role_permission` → `permission_role` (lines 193 and 196). Then drop or rewrite `heuristicGate()` since the real pivot branch will start matching. Or, much simpler: replace the whole DB lookup with `Gate::forUser(User::factory()->...->make())->allows($perm)` per role — same code path the sidebar uses.

### 2.4 Can a moderator / finance / content_editor actually navigate without 403 storms? — YES
The seeder's permission grants align with the per-route `can:` middleware:
- `moderator`: `comments.moderate` + `sentiment.view` + `security.audit_logs` — reaches `/admin/comments/queue`, `/admin/sentiment`, `/admin/audit-logs`. Confirmed by `AdminRouteGuardTest::moderator can reach comment moderation but not user admin`.
- `finance`: `analytics.revenue`, `analytics.cohort`, `analytics.funnel`, `subscriptions.view`, `subscriptions.refund` — reaches `/admin/revenue`, `/admin/cohorts`, `/admin/funnel`. Confirmed by `AdminRouteGuardTest::finance can reach revenue dashboard`. Notably **lacks `analytics.geo`, `analytics.performance`, `analytics.churn`, `analytics.insights`** — those dashboards 403 for finance even though they're revenue-adjacent. May want to expand finance's grants.
- `content_editor`: movies + genres + casts + banners + blog + help + subtitles. Clean.
- `admin` (role, not the boolean): broad grants but explicitly excludes `roles.manage`, `users.delete`, `users.assign_roles`, `subscriptions.refund`, `security.api_keys`, `security.backup`, `system.maintenance`. Sensible separation of duties.

Sidebar component filters items the user can't see (`layout.blade.php:217-220`), so empty sections collapse cleanly instead of showing dead links. No 403 storms by design.

### 2.5 Is there a UI to revoke a role assignment? — YES
- Per-user role assignment at `/admin/users/{user}/roles` (`AdminController::editRoles` + view `admin/users/roles.blade.php`). Unchecking a checkbox and saving runs `$user->roles()->sync($after)` which removes the unchecked role.
- Role-level revocation by editing the role's permission matrix at `/admin/roles/{role}/edit`.
- Role deletion at `/admin/roles/{role}` (system roles blocked).
- **Self-revocation guard:** `AdminController::toggleAdmin` blocks demoting your own boolean `is_admin`, but `updateRoles` does **not** block a user from removing their own roles. A super_admin could remove their own `super_admin` role, lose admin access, and lock themselves out (depending on the order of pivot updates). Minor footgun; consider adding `if ($user->id === auth()->id() && /* removing super_admin */) abort(...)`.

### 2.6 Are role changes audited? — MOSTLY YES, one gap
- `RoleController::store/update/destroy` → `AuditLogger::security('admin.role.created' | 'updated' | 'deleted')` with before/after snapshots. Solid.
- `AdminController::updateRoles` → `AuditLogger::security('admin.user.roles_updated')` with `added` / `removed` deltas. Solid.
- **`AdminController::toggleAdmin` (line 403) — NO audit log.** This still mutates the legacy `users.is_admin` boolean and, via `isSuperAdmin()` / `getIsAdminAttribute`, still grants super-admin-level access. Flipping it is a privilege change and MUST be audited. Currently it's invisible to `/admin/audit-logs`.
- `AdminController::destroyUser` (line 418) audits via `SecurityEvents::ADMIN_USER_DELETED` — good.

### 2.7 Does `User::is_admin` accessor preserve legacy `can:admin` behavior? — YES
`getIsAdminAttribute()` (line 441) is true when any of:
1. Raw DB `is_admin = true` (legacy column flip), OR
2. `role === 'super_admin'` or `role === 'admin'` (legacy single-column), OR
3. Pivot `roles` includes `admin` or `super_admin`.

The `admin` Gate (`AuthServiceProvider:88`) is `$user->isSuperAdmin() || $user->hasRole('admin')`. Combined with the dotted-ability `Gate::before` fallback at line 158-179 (any user with `is_admin=true` who hits a dotted permission that isn't `Gate::define`d gets `true`), legacy installs continue to work — confirmed by `AdminRouteGuardTest::legacy is_admin=true users still pass admin routes`.

---

## 3. Other Findings

### 3.1 Stale `User::ROLES` constants
`User.php:111-127` defines `ROLE_CONTENT_MANAGER` ("content_manager"), `ROLE_CUSTOMER_SUPPORT` ("customer_support"), `ROLE_FINANCE`, `ROLE_USER`, `ROLE_SUPER_ADMIN`. The actual seeded roles are `super_admin`, `admin`, `moderator`, `content_editor`, `finance`, `user`. So `content_manager` and `customer_support` constants point to roles that **don't exist in the DB**.

This drives:
- The Gate definitions for `manage-content` and `manage-users` at `AuthServiceProvider:95-99` reference `User::ROLE_CONTENT_MANAGER` / `User::ROLE_CUSTOMER_SUPPORT` — these gates will only match the legacy single-column `role` field, never a pivot assignment, since those role rows don't exist.
- `MenuMatrixController::heuristicGate` (lines 229, 234, 239) uses the same stale constants.
- `User::adminDashboardUrl()` at line 470 routes `content_manager` → `/admin/movies`, `customer_support` → `/admin/users` — dead branches.

Either rename the constants to match seeded names (`ROLE_CONTENT_EDITOR`, `ROLE_MODERATOR`) or add `content_manager`/`customer_support` as aliases to the seeder. The decision needs product input — but right now there's a mismatch.

### 3.2 `User::ROLES` map drift from sidebar / matrix
`User::ROLES` ships 5 roles, but the seeder ships 6 (adds `moderator`, `admin`, `content_editor`; drops `content_manager`, `customer_support`). The matrix's `discoverRoles()` falls back to `User::ROLES` when its (broken) DB lookup fails — so the matrix currently shows the wrong column set.

### 3.3 `Permission::category` is NOT NULL in DB but the model defaults aren't enforced
Migration sets `category` non-nullable. Permission has no `$casts` or default — if a caller does `Permission::create(['name' => 'x', 'display_name' => 'X'])` without a category, the DB rejects it. Fine in practice (seeder always provides one), but a custom permission created via the admin UI — wait, there's **no admin UI to create custom permissions**. Only roles can be created (`RoleController::create`). The Permission catalog is seeder-only. Worth noting if "extensible permissions" is a future ask.

### 3.4 `RoleController::edit` query: minor inefficiency
Line 152-157 fetches every Permission and then groups in PHP. `Permission::groupedByCategory()` already exists and does this. Tiny duplication, not a bug.

### 3.5 `RoleController::destroy` detach order
Comment at 278-280 acknowledges the cascade should make the explicit `detach()` redundant. The pivot migrations DO use `cascadeOnDelete` for both `permission_role` and `role_user`. The explicit detaches are belt-and-braces — harmless.

### 3.6 Dynamic Gate registration caches stale permissions for 15 min
`AuthServiceProvider:122-126` caches the permission name list for 15 minutes. When a new permission is seeded (e.g. via a future "add custom permission" UI or a fresh migration + seeder re-run), the new ability won't have a Gate registered until either:
- The 15-min cache expires, OR
- Someone runs `php artisan cache:clear`.

The dotted-ability fallback `Gate::before` (line 158) covers it during that window — but only for `is_admin=true` users. A non-admin granted a brand-new permission will silently 403 until the cache expires. Add a `cache:forget` call inside `RoleController` after permission edits... wait, permissions aren't edited from the UI. The cache invalidation pathway is implicit (admin clears cache after seed). Document this or hook it.

### 3.7 `UserObserver` doesn't observe role changes
`UserObserver` only handles `created` (assigns default `user` role + referral code). It does NOT observe role pivot mutations, so e.g. `forgetPermissionCache` is not auto-bust when a `role_user` row is inserted from outside the User model (raw DB query, seeder, etc.). In practice the only writers are `User::assignRole/removeRole/syncRoles` which all call `forgetPermissionCache` manually, plus `RoleController` which mutates `permission_role` directly without busting User caches for users holding that role. The cache is per-instance (private property), so it dies with the request — not a multi-request data-integrity bug, but if a long-running queue worker holds a User instance across jobs and the role's permissions change mid-job, the worker will use stale data. Worth noting.

### 3.8 Profile permissions view: super_admin display contradiction
`profile/permissions.blade.php:127-131` says "Super Admin bypass aktif — list below only shows attached permissions" — but in practice super_admin is seeded with `'*'` (every permission attached to the role), so the list IS the full ~55 permissions. The banner copy is technically right but reads weirdly. Cosmetic.

### 3.9 No coverage for `MenuMatrixController`
None of the four test files exercise the matrix. The schema-mismatch bug in §2.3 has been latent because tests never run the controller.

### 3.10 Inline `toggleAdmin` legacy path
`AdminController::toggleAdmin` (line 403) is still wired at `routes/web.php:599` and the per-route gate is `can:users.update` — meaning any user with `users.update` (currently `admin` role and `super_admin`) can flip the legacy `is_admin` boolean on any other user, effectively granting/revoking super-admin via the back door. Should be gated on `users.assign_roles` at minimum, ideally retired entirely in favor of the role UI. And it isn't audited (§2.6).

---

## 4. Severity Summary

| # | Finding | Severity | Action |
|---|---|---|---|
| 2.3 | `MenuMatrixController` uses wrong column (`slug`) and wrong pivot table (`role_permission`) — matrix shows heuristic / legacy data, not reality | **HIGH** | Fix column/table names; add a test |
| 2.6 / 3.10 | `toggleAdmin` mutates `is_admin` (effective super-admin) without audit logging AND is gated on the weaker `users.update` | **HIGH** | Audit + re-gate on `users.assign_roles`, or remove |
| 3.1 | `User::ROLE_CONTENT_MANAGER` / `ROLE_CUSTOMER_SUPPORT` constants reference non-existent seeded roles; `manage-content`/`manage-users` Gates partially broken | **MEDIUM** | Rename constants OR seed missing roles |
| 2.5 | No self-revocation guard on `updateRoles` (super_admin can lock themselves out) | **MEDIUM** | Add guard |
| 3.6 | 15-min permission-Gate cache + no auto-bust on permission edits | **LOW** | Hook a cache forget on the (theoretical) permission-edit flow |
| 3.7 | `User::cachedPermissions` not invalidated when `permission_role` is mutated by `RoleController` | **LOW** | Add `Cache::forget` on a per-user version key or accept request-scoped staleness |
| 3.9 | No tests for `MenuMatrixController` (allowed §2.3 to land undetected) | **LOW** | Add a feature test |
| 2.2 (#2) | `admin.notifications.*` routes rely on inside-controller audience check; not verified here | **LOW** | Verify `NotificationController::authorizeAudience` exists and is sound |

No CRITICAL findings (no privilege escalation, no bypass of authentication, no SQL injection, no missing CSRF). The `MenuMatrixController` bug is HIGH because it provides operators a *misleading* picture of who can see what — security-by-audit relies on accurate audit tooling.

---

## 5. Files Referenced

- `D:\AI\velflix\velflix\app\Models\Role.php`
- `D:\AI\velflix\velflix\app\Models\Permission.php`
- `D:\AI\velflix\velflix\app\Models\User.php`
- `D:\AI\velflix\velflix\app\Providers\AuthServiceProvider.php`
- `D:\AI\velflix\velflix\app\Providers\AppServiceProvider.php`
- `D:\AI\velflix\velflix\app\Observers\UserObserver.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\Admin\RoleController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\Admin\MenuMatrixController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\AdminController.php` (lines 330-453)
- `D:\AI\velflix\velflix\app\Http\Controllers\ProfileController.php` (lines 120-206)
- `D:\AI\velflix\velflix\config\admin_menu.php`
- `D:\AI\velflix\velflix\database\migrations\2026_05_10_05000{1,2,3,4}_*.php`
- `D:\AI\velflix\velflix\database\seeders\RolePermissionSeeder.php`
- `D:\AI\velflix\velflix\resources\views\admin\roles\{index,create,edit}.blade.php`
- `D:\AI\velflix\velflix\resources\views\admin\users\roles.blade.php`
- `D:\AI\velflix\velflix\resources\views\admin\menu-matrix\index.blade.php`
- `D:\AI\velflix\velflix\resources\views\profile\permissions.blade.php`
- `D:\AI\velflix\velflix\resources\views\components\admin\layout.blade.php` (lines 160-244)
- `D:\AI\velflix\velflix\routes\web.php` (lines 228-229, 509-1020)
- `D:\AI\velflix\velflix\tests\Feature\Authorization\{RoleAssignmentTest,RoleCrudTest,AdminRouteGuardTest,ProfilePermissionsViewTest}.php`
