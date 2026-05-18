# Roles & Permissions (RBAC)

**Status:** active
**Owner:** Security
**Last updated:** 2026-05-18

FLiK's admin authorization is layered:

1. **`auth`** — must be logged in (Laravel default).
2. **`can:admin`** — the COARSE gate. Anyone with any admin-flavoured role
   passes (see [Legacy `can:admin` gate](#legacy-canadmin-gate)).
3. **`can:<permission_name>`** — per-route fine-grained gates, e.g.
   `can:movies.create`, `can:analytics.revenue`. The permission catalog
   is seeded by [`RolePermissionSeeder`](../../database/seeders/RolePermissionSeeder.php).

Every admin route in [`routes/web.php`](../../routes/web.php) wears both
the outer `can:admin` and an inner `can:<perm>` (where a sensible
permission exists). Routes with no specific permission (dashboard
landing page, pitch deck) stay on the bare `can:admin` gate.

---

## Role taxonomy

The seeder ships six system roles. `is_system=true` roles cannot be
deleted via the admin UI (the `RoleController::destroy` 403s even for
super_admin), but custom roles can be created/edited/deleted by anyone
with the `roles.manage` permission.

| Role             | Permission count* | Key responsibilities                                                                                  |
| ---------------- | ----------------: | ----------------------------------------------------------------------------------------------------- |
| `super_admin`    | **all**           | Full access. Bypasses every Gate via `Gate::before` (no DB lookup needed).                            |
| `admin`          | 27                | Day-to-day administration. Cannot manage roles, API keys, backups, refunds, delete users.             |
| `moderator`      |  3                | Comment moderation, sentiment dashboard, read-only audit logs.                                        |
| `content_editor` | 10                | Movie catalog + subtitles. No analytics, billing, or security access.                                 |
| `finance`        |  5                | Revenue / cohort / funnel analytics + subscriptions (view + refund).                                  |
| `user`           |  0                | Default at registration. Public app only — no admin panel.                                            |

\* Approximate, may drift as the taxonomy grows. The seeder is the
source of truth — search for `private const ROLES` in
`database/seeders/RolePermissionSeeder.php`.

---

## Permission catalog

Permissions follow a dotted namespace convention: `<category>.<verb>`
or `<category>.<noun>`. They live in the `permissions` table (peer
ROLE #1's migration) and are seeded under categorical buckets:

| Category       | Example permissions                                                              |
| -------------- | -------------------------------------------------------------------------------- |
| `content`      | `movies.view`, `movies.create`, `movies.update`, `movies.delete`, `genres.manage`|
| `subtitles`    | `subtitles.generate`, `subtitles.translate`, `subtitles.delete`                  |
| `ai`           | `ai.providers.configure`, `ai.usage.view`, `ai.tasks.run`                        |
| `marketing`    | `marketing.banner`, `marketing.social`, `marketing.email_ab`, `marketing.tiktok`, `marketing.cs_reply` |
| `moderation`   | `comments.moderate`, `sentiment.view`                                            |
| `analytics`    | `analytics.revenue`, `analytics.geo`, `analytics.cohort`, `analytics.funnel`, `analytics.performance`, `analytics.churn`, `analytics.insights` |
| `security`     | `security.audit_logs`, `security.sessions`, `security.backup`, `security.api_keys`, `security.waf` |
| `users`        | `users.view`, `users.update`, `users.delete`, `users.assign_roles`               |
| `roles`        | `roles.manage`                                                                   |
| `billing`      | `subscriptions.view`, `subscriptions.refund`                                     |
| `distribution` | `movies.upload_master`, `movies.encoding_status`                                 |

The full list with descriptions is in
[`database/seeders/RolePermissionSeeder.php`](../../database/seeders/RolePermissionSeeder.php).

---

## How `can:admin` (legacy gate) still works

The legacy `Gate::define('admin', ...)` in
[`AuthServiceProvider`](../../app/Providers/AuthServiceProvider.php)
returns true when the user is a super_admin OR has the `admin` role
(pivot or legacy column). It's been kept untouched so that:

- Every `Route::middleware('can:admin')` in `routes/web.php` keeps
  working without refactoring.
- Every `@can('admin')` directive in Blade views keeps working.
- The `app/Http/Middleware/EnsureOwnership.php` super-admin bypass
  still resolves correctly.

The new `can:<permission>` middleware is **layered on top** of
`can:admin`, never as a replacement for it.

### Layered Gate::before resolution

`AuthServiceProvider::boot()` registers TWO `Gate::before` hooks (in
order):

1. **Super-admin bypass** — returns `true` for any user passing
   `User::isSuperAdmin()` (super_admin role OR legacy `is_admin`
   boolean). Short-circuits everything.
2. **Defensive permission fallback** — fires only for dotted abilities
   (`<category>.<noun>`). If the gate is NOT registered (fresh install,
   missed seed) and the user has the legacy `is_admin` flag, returns
   `true`. Otherwise returns `null` (continue) so the registered gate
   can deny per role.

This means **the admin panel never 500s** if peer ROLE migrations
haven't been applied — legacy `is_admin=true` accounts still get
through every per-permission check.

---

## How `Gate::define('<permission_name>', ...)` is wired up

Peer ROLE #2 added a dynamic loop in `AuthServiceProvider::boot()`:

```php
foreach (\App\Models\Permission::query()->pluck('name') as $name) {
    Gate::define($name, fn (User $user) => $user->hasPermission($name));
}
```

The list is cached in the `rbac.permission-names` key for 15 minutes —
clear it with `php artisan cache:clear` after adding new permissions
in the admin UI.

---

## Adding a new permission

1. Add the slug to the relevant category bucket in
   `database/seeders/RolePermissionSeeder.php`'s `PERMISSIONS` array:

   ```php
   'content' => [
       // ...
       ['movies.archive', 'Archive Movies', 'Move movies to the archive shelf.'],
   ],
   ```

2. Add the same slug to whichever roles should hold it in the same
   seeder's `ROLES` array.

3. Re-run the seeder + bust the gate cache:

   ```bash
   php artisan db:seed --class=RolePermissionSeeder
   php artisan cache:clear
   ```

4. Add the new gate to the route in `routes/web.php`:

   ```php
   Route::post('/movies/{movie}/archive', [...])
       ->middleware('can:movies.archive')->name('movies.archive');
   ```

5. (optional) Cover the new gate with a test in
   `tests/Feature/Authorization/AdminRouteGuardTest.php`.

---

## Adding a new role

### Via the seeder (system role)

Edit `RolePermissionSeeder::ROLES` and add an entry with
`is_system => true`. Re-run the seeder. The role is now protected from
deletion via the admin UI.

### Via the admin UI (custom role)

1. Log in as a user with `roles.manage` (out of the box: super_admin).
2. Navigate to `/admin/roles/create`.
3. Pick a machine name (lowercase letters / digits / underscores only,
   no spaces) and display name.
4. Submit. You're redirected to the edit page where the permission
   matrix is rendered — tick the boxes for the permissions you want
   the role to grant.

Custom roles have `is_system=false` so the delete button is visible
and the underlying `RoleController::destroy` lets the request through.

---

## Default role at registration

The `RegisterController` does not currently assign a role explicitly —
the user lands with `role='user'` (default from the
`2026_05_10_000010_add_role_to_users_table.php` migration) and no
pivot row. The `RolePermissionSeeder::backfillUserRoles()` method
assigns every user the `user` role on first seed, but new registrations
post-seed rely on the legacy column.

**TODO:** wire `User::assignRole('user')` into `RegisterController`
once the role-pivot rollout is complete. Until then,
`User::hasRole('user')` still resolves correctly via the legacy column
fallback in `hasRole()`.

---

## Audit trail

Every role mutation (create / update / delete / permission sync / user
assignment) writes a row to `audit_logs` via
`App\Services\Audit\AuditLogger::security()`. The event names follow
the `admin.role.<verb>` and `admin.user.role.<verb>` convention so the
`/admin/audit-logs` filter "Type: security" surfaces the full RBAC
change history.

| Event                          | Subject     | When                                                 |
| ------------------------------ | ----------- | ---------------------------------------------------- |
| `admin.role.created`           | `Role`      | New custom role saved via the admin UI.              |
| `admin.role.updated`           | `Role`      | Role fields or permission matrix changed.            |
| `admin.role.deleted`           | `Role`      | Custom role deleted (system roles can never reach).  |
| `admin.user.role.assigned`     | `User`      | Pivot row added via `User::assignRole()`.            |
| `admin.user.role.removed`      | `User`      | Pivot row removed via `User::removeRole()`.          |
| `admin.user.role.synced`       | `User`      | Bulk replacement via `User::syncRoles()`.            |

Audit rows include the actor (the admin who pressed the button), the
target user/role IDs, and a `meta` JSON blob with before/after
snapshots for diffing. See
[`docs/security/event-taxonomy.md`](event-taxonomy.md) for the full
field schema.

---

## Testing

Feature tests live under `tests/Feature/Authorization/`:

| File                            | Coverage                                                                       |
| ------------------------------- | ------------------------------------------------------------------------------ |
| `RoleAssignmentTest.php`        | `User::assignRole` / `hasRole` / `hasPermission` / `removeRole` / `syncRoles`. |
| `AdminRouteGuardTest.php`       | Per-route `can:<permission>` enforcement across all five role tiers.           |
| `RoleCrudTest.php`              | Admin UI authz: super_admin can CRUD, others get 403, system roles undeletable.|
| `ProfilePermissionsViewTest.php`| Self-service `/profile/permissions` page renders for every role tier.          |

Every test skips gracefully when MySQL is unavailable (CI environment
without a DB) or when peer ROLE #1's migrations haven't shipped yet —
the suite never blocks a PR purely on infrastructure.

Run them with:

```bash
./vendor/bin/phpunit tests/Feature/Authorization
```
