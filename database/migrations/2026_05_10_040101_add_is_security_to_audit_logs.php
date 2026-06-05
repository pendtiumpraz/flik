<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `is_security` boolean to `audit_logs` so security-flavoured rows can
 * be filtered & colour-coded in /admin/audit-logs without having to LIKE
 * over the action prefix on every page render.
 *
 * The column is indexed because the most common admin query becomes
 * "show me only the security rows from the last N days" — covered by the
 * compound (is_security, created_at) index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            // Defensive: skip when re-running against a schema that already
            // has the column (devs tend to copy migrations around).
            if (Schema::hasColumn('audit_logs', 'is_security')) {
                return;
            }

            $table->boolean('is_security')
                ->default(false)
                ->after('action')
                ->index();
        });

        // Compound index for the dominant access pattern:
        //   "list latest security events" + filtered date range.
        // We add it separately so MySQL can pick the most selective key.
        // Compound index — idempotent + portable (MySQL `SHOW INDEX` is not
        // valid on Postgres; a try/catch covers the duplicate-on-rerun case).
        try {
            Schema::table('audit_logs', function (Blueprint $table): void {
                $table->index(['is_security', 'created_at'], 'audit_logs_is_security_created_at_index');
            });
        } catch (\Throwable) {
            // index already exists — ignore
        }
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            // Drop compound index first, then column-level index, then column.
            try {
                $table->dropIndex('audit_logs_is_security_created_at_index');
            } catch (\Throwable) {
                // ignore — index may not exist on partial rollback
            }

            if (Schema::hasColumn('audit_logs', 'is_security')) {
                try {
                    $table->dropIndex(['is_security']);
                } catch (\Throwable) {
                    // single-column index name auto-derived; ignore if absent
                }

                $table->dropColumn('is_security');
            }
        });
    }
};
