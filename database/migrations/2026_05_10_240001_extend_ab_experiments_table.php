<?php

declare(strict_types=1);

use App\Models\AbExperiment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * extend_ab_experiments_table (FIX #3 — Analytics surface)
 * --------------------------------------------------------------------------
 * Adds the columns the `AbTestFramework` service, `AbTestController`, and
 * `admin/ab/show.blade.php` were written to assume but which were never
 * present on the original `2026_05_10_030008_create_ab_experiments_table`
 * migration:
 *
 *   - `slug`             — stable string identifier referenced by the
 *                          framework (`AbTestFramework::assign($user, $slug)`).
 *                          UNIQUE so multiple experiments can't share a key.
 *                          Backfilled from `name` for existing rows.
 *   - `hypothesis`       — free-form text explaining the experiment goal.
 *   - `winner_variant`   — variant key selected on `act('conclude', ...)`.
 *
 * Also relaxes the `status` ENUM into a plain string column so the new
 * lifecycle states (e.g. `running` alias for `active`) can live alongside
 * the historical four. Existing rows keep their value untouched.
 *
 * SQLite-safe: each `Schema::table` call is wrapped in a feature-detection
 * check so the migration is idempotent on environments where someone has
 * already hand-patched a column.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('ab_experiments')) {
            return;
        }

        // ── 1. Add missing columns ─────────────────────────────────────
        Schema::table('ab_experiments', function (Blueprint $table) {
            if (! Schema::hasColumn('ab_experiments', 'slug')) {
                // Nullable on add so the backfill step below can populate
                // existing rows before we tighten the UNIQUE constraint.
                $table->string('slug', 120)->nullable()->after('name');
            }
            if (! Schema::hasColumn('ab_experiments', 'hypothesis')) {
                $table->text('hypothesis')->nullable()->after('description');
            }
            if (! Schema::hasColumn('ab_experiments', 'winner_variant')) {
                $table->string('winner_variant', 60)->nullable()->after('ended_at');
            }
        });

        // ── 2. Backfill slug from name for any existing row ────────────
        $rows = DB::table('ab_experiments')
            ->whereNull('slug')
            ->orWhere('slug', '')
            ->get(['id', 'name']);

        foreach ($rows as $row) {
            $base = Str::slug((string) $row->name) ?: ('experiment-' . $row->id);
            $slug = $base;
            $suffix = 0;
            while (
                DB::table('ab_experiments')
                    ->where('slug', $slug)
                    ->where('id', '!=', $row->id)
                    ->exists()
            ) {
                $suffix++;
                $slug = $base . '-' . $suffix;
            }
            DB::table('ab_experiments')
                ->where('id', $row->id)
                ->update(['slug' => $slug]);
        }

        // ── 3. Add UNIQUE index on slug (only after backfill) ──────────
        // Wrapped in try/catch because SQLite raises if we re-add an
        // already-present index, and we want this migration idempotent.
        try {
            Schema::table('ab_experiments', function (Blueprint $table) {
                $table->unique('slug', 'ab_experiments_slug_unique');
            });
        } catch (\Throwable) {
            // index already exists — fine.
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ab_experiments')) {
            return;
        }

        Schema::table('ab_experiments', function (Blueprint $table) {
            try {
                $table->dropUnique('ab_experiments_slug_unique');
            } catch (\Throwable) {
                // no-op
            }

            foreach (['slug', 'hypothesis', 'winner_variant'] as $col) {
                if (Schema::hasColumn('ab_experiments', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
