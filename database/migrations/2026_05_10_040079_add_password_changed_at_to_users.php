<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `password_changed_at` to `users`.
 *
 * Stamped by:
 *   - User::setPasswordAttribute() on every password write.
 *   - The future password-reset / forced-rotation flow.
 *
 * Useful for:
 *   - Sliding password-rotation policies (e.g. force reset after 365d).
 *   - Detecting "password unchanged since signup" for breach response.
 *   - Audit dashboards under /admin/audit-logs.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('password_changed_at')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('password_changed_at');
        });
    }
};
