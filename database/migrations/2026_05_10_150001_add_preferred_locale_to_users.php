<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persist a user's preferred UI locale across sessions and devices.
 *
 * Resolution order in App\Http\Middleware\SetLocale:
 *   1. ?lang= query param  (one-shot override + share-friendly URLs)
 *   2. session('locale')   (this-tab preference)
 *   3. users.preferred_locale  (THIS column — cross-device default for authed users)
 *   4. Accept-Language header  (cold visit, no account)
 *   5. config('locales.default')
 *
 * The column is intentionally short (5 chars) — matches BCP-47 primary tags
 * we actually expose (id, en, ar, future: ms, ja, zh-CN). Anything longer
 * is rejected by the middleware before reaching the DB anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Defensive: peer migration that adds another `*_locale` column
            // could have already shipped — never fail re-running this.
            if (! Schema::hasColumn('users', 'preferred_locale')) {
                $table->string('preferred_locale', 5)
                    ->nullable()
                    ->after('email');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'preferred_locale')) {
                $table->dropColumn('preferred_locale');
            }
        });
    }
};
