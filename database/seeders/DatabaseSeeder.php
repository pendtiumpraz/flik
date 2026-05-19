<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     * Idempotent: safe to re-run, no duplicates.
     *
     * NOTE: pass plain-text password — User::setPasswordAttribute mutator
     * will bcrypt automatically. Don't pre-hash with Hash::make() or it
     * double-bcrypts and login breaks.
     *
     * SECURITY: `is_admin`, `role`, and `email_verified_at` are NOT in
     * User::$fillable (mass-assignment audit, 2026-05-13). Set them via
     * forceFill() so the seeder still bootstraps the admin account
     * without re-introducing privilege-escalation surface.
     */
    public function run()
    {
        // ── Roles & permissions (run FIRST so default users below can
        //    be attached to roles immediately) ─────────────────────
        $this->call([
            RolePermissionSeeder::class,
        ]);

        // ── Default users (idempotent) ──────────────────────────
        $regular = User::firstOrNew(['email' => 'user@gmail.com']);
        $regular->name = 'user';
        $regular->password = 'password';
        $regular->forceFill([
            'role' => User::ROLE_USER,
            'is_admin' => false,
            'email_verified_at' => now(),
        ])->save();

        $admin = User::firstOrNew(['email' => 'admin@gmail.com']);
        $admin->name = 'admin';
        $admin->password = 'password';
        $admin->forceFill([
            'role' => User::ROLE_SUPER_ADMIN,
            'is_admin' => true,
            'email_verified_at' => now(),
        ])->save();

        // ── Attach seeded users to the new role/permission system ──
        // RolePermissionSeeder's backfill ran BEFORE these users existed,
        // so we explicitly attach them here. insertOrIgnore keeps this
        // safe across re-seeds (composite PK on role_user).
        $this->attachSeedRoles($regular, ['user']);
        $this->attachSeedRoles($admin,   ['super_admin', 'user']);

        // ── Catalog & system data ──────────────────────────────
        $this->call([
            GenreSeeder::class,
            MovieSeeder::class,
            SubscriptionPlanSeeder::class,
            AchievementSeeder::class,
            PromoCodeSeeder::class,
        ]);
    }

    /**
     * Idempotently link a user to one or more roles by slug.
     * Silently skips slugs that don't resolve (e.g. if a custom rollout
     * hasn't seeded a particular role yet).
     *
     * @param  array<int, string>  $roleNames
     */
    private function attachSeedRoles(User $user, array $roleNames): void
    {
        $roleIds = Role::query()
            ->whereIn('name', $roleNames)
            ->pluck('id');

        if ($roleIds->isEmpty()) {
            return;
        }

        $now = now();
        $rows = $roleIds->map(fn ($rid) => [
            'role_id'             => (int) $rid,
            'user_id'             => (int) $user->id,
            'assigned_by_user_id' => null,
            'assigned_at'         => $now,
            'created_at'          => $now,
            'updated_at'          => $now,
        ])->all();

        DB::table('role_user')->insertOrIgnore($rows);
    }
}
