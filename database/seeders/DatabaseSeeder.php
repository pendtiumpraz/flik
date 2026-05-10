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
     */
    public function run()
    {
        // ── Default users (idempotent) ──────────────────────────
        User::updateOrCreate(
            ['email' => 'user@gmail.com'],
            [
                'name' => 'user',
                'password' => 'password',
                'role' => User::ROLE_USER,
                'is_admin' => false,
                'email_verified_at' => now(),
            ]
        );

        User::updateOrCreate(
            ['email' => 'admin@gmail.com'],
            [
                'name' => 'admin',
                'password' => 'password',
                'role' => User::ROLE_SUPER_ADMIN,
                'is_admin' => true,
                'email_verified_at' => now(),
            ]
        );

        // ── Catalog & system data ──────────────────────────────
        $this->call([
            GenreSeeder::class,
            MovieSeeder::class,
            SubscriptionPlanSeeder::class,
            AchievementSeeder::class,
        ]);
    }
}
