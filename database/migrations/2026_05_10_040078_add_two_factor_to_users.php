<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2FA / TOTP columns on users.
 *
 *  - two_factor_secret           — base32 TOTP shared secret (encrypted at rest
 *                                  via the `encrypted` cast on the User model).
 *  - two_factor_recovery_codes   — JSON array of 8 single-use hex recovery
 *                                  codes (encrypted via `encrypted:array`).
 *  - two_factor_confirmed_at     — set the moment the user proves they have
 *                                  the secret in their authenticator app.
 *                                  Null  → 2FA pending / disabled (login flow
 *                                  treats user as no-2FA).
 *                                  Set   → challenge required after password.
 *
 * Columns are wide TEXT because the encrypted payload is several hundred
 * bytes (Laravel encrypter prepends IV + MAC + base64 envelope).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'two_factor_secret')) {
                $table->text('two_factor_secret')->nullable()->after('password');
            }
            if (!Schema::hasColumn('users', 'two_factor_recovery_codes')) {
                $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            }
            if (!Schema::hasColumn('users', 'two_factor_confirmed_at')) {
                $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['two_factor_confirmed_at', 'two_factor_recovery_codes', 'two_factor_secret'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
