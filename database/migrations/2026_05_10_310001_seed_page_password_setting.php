<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seed the default password for the page-level gate on /admin/docs and
 * /admin/pitch-deck. Stored in `settings` table so admin can change it
 * later via /admin/settings UI (key: pages.protected_password).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            // Settings table not migrated yet — middleware fallbacks to
            // hardcoded ott2026 anyway. Skip safely.
            return;
        }

        // Upsert — doesn't overwrite if admin already changed it.
        $exists = DB::table('settings')->where('key', 'pages.protected_password')->exists();
        if ($exists) {
            return;
        }

        DB::table('settings')->insert([
            'key'              => 'pages.protected_password',
            'value'            => 'ott2026',
            'type'             => 'string',
            'group'            => 'security',
            'description'      => 'Password untuk akses halaman terlindungi (/admin/docs, /admin/pitch-deck). Re-prompts setiap reload page. Ganti via halaman ini juga.',
            'is_public'        => false,
            'is_secret'        => true,
            'validation_rules' => 'required|string|min:4|max:120',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('settings')) {
            DB::table('settings')->where('key', 'pages.protected_password')->delete();
        }
    }
};
