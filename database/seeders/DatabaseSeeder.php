<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     * Idempotent: safe to re-run, no duplicates.
     */
    public function run()
    {
        // ── Default users (idempotent) ──────────────────────────
        User::updateOrCreate(
            ['email' => 'user@gmail.com'],
            [
                'name' => 'user',
                'password' => Hash::make('password'),
                'role' => User::ROLE_USER,
                'is_admin' => false,
            ]
        );

        User::updateOrCreate(
            ['email' => 'admin@gmail.com'],
            [
                'name' => 'admin',
                'password' => Hash::make('password'),
                'role' => User::ROLE_SUPER_ADMIN,
                'is_admin' => true,
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
