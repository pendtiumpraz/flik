<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

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

        // ── Catalog & system data ──────────────────────────────
        $this->call([
            GenreSeeder::class,
            MovieSeeder::class,
            SubscriptionPlanSeeder::class,
            AchievementSeeder::class,
        ]);
    }
}
