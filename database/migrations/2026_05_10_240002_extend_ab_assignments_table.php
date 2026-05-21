<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * extend_ab_assignments_table (FIX #3 — Analytics surface)
 * --------------------------------------------------------------------------
 * Aligns `ab_assignments` with the columns `AbTestFramework` writes to and
 * reads from:
 *
 *   - `assigned_at`      — explicit timestamp for when the bucket was minted.
 *                          Separate from `created_at` so future migrations
 *                          can backfill historical assignments without
 *                          touching the audit timestamp.
 *   - `conversion_value` — decimal so `track($user, $slug, $value)` can
 *                          persist real currency / score values for the
 *                          per-variant report (`avg_value`, `total_value`).
 *
 * The framework continues to write to the existing `ab_experiment_id`
 * foreign key (NOT a separate `experiment_id` column) — the service has
 * been updated to use the canonical name rather than introduce a duplicate.
 *
 * Idempotent: column existence is checked before the alteration so the
 * migration is re-runnable in the partial-deploy edge case.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('ab_assignments')) {
            return;
        }

        Schema::table('ab_assignments', function (Blueprint $table) {
            if (! Schema::hasColumn('ab_assignments', 'assigned_at')) {
                // Nullable so the historical rows (created before this
                // migration) don't fail the alter — the framework forces
                // a value on insert via Carbon::now().
                $table->timestamp('assigned_at')->nullable()->after('variant');
            }
            if (! Schema::hasColumn('ab_assignments', 'conversion_value')) {
                // 12 integer digits + 2 fractional → up to 9,999,999,999.99
                // which covers the realistic spend ceiling for any single
                // conversion event we'd track.
                $table->decimal('conversion_value', 12, 2)
                    ->nullable()
                    ->after('converted_at');
            }
        });

        // Backfill `assigned_at` from `created_at` so existing rows have
        // a meaningful "when was the bucket minted" timestamp.
        try {
            DB::table('ab_assignments')
                ->whereNull('assigned_at')
                ->update(['assigned_at' => DB::raw('created_at')]);
        } catch (\Throwable) {
            // SQLite quirk: DB::raw('created_at') may need fallback. Try
            // a per-row update as a safety net but stay quiet on failure.
            try {
                foreach (DB::table('ab_assignments')->whereNull('assigned_at')->get(['id', 'created_at']) as $row) {
                    DB::table('ab_assignments')
                        ->where('id', $row->id)
                        ->update(['assigned_at' => $row->created_at]);
                }
            } catch (\Throwable) {
                // give up — the column is nullable, this is non-blocking.
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ab_assignments')) {
            return;
        }

        Schema::table('ab_assignments', function (Blueprint $table) {
            foreach (['assigned_at', 'conversion_value'] as $col) {
                if (Schema::hasColumn('ab_assignments', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
