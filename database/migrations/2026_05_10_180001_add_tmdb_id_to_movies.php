<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add a canonical TMDB id column to `movies` so the TMDB import wizard
 * can dedupe on re-imports (find-or-create-by-tmdb_id) and so the admin
 * movie edit screen can offer a "Re-sync from TMDB" button.
 *
 * Idempotent: every column add is guarded by Schema::hasColumn() so
 * re-running the migration set against an already-patched DB is safe.
 *
 * Also stamps imdb_id (TMDB returns it in the same payload) and
 * imported_from + imported_at to make audit/debug "where did this row
 * come from" trivial without grepping audit_logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movies', function (Blueprint $table) {
            if (! Schema::hasColumn('movies', 'tmdb_id')) {
                // Unique-but-nullable: most rows are pre-TMDB-import era
                // and have no tmdb_id; new imports stamp it and dedupe on it.
                // The unique index ignores NULLs on MySQL (multi-NULL allowed),
                // matching the behaviour we want.
                $table->unsignedBigInteger('tmdb_id')->nullable()->after('id');
                $table->unique('tmdb_id');
            }

            if (! Schema::hasColumn('movies', 'imdb_id')) {
                // tt-prefixed string identifier (e.g. tt1375666). Nullable;
                // TMDB doesn't always have it.
                $table->string('imdb_id', 16)->nullable()->after('tmdb_id');
                $table->index('imdb_id');
            }

            if (! Schema::hasColumn('movies', 'runtime_minutes')) {
                // Mirrors the column name used on episodes for consistency.
                $table->unsignedSmallInteger('runtime_minutes')->nullable()->after('total_episodes');
            }

            if (! Schema::hasColumn('movies', 'tagline')) {
                $table->string('tagline', 500)->nullable()->after('overview');
            }

            if (! Schema::hasColumn('movies', 'imported_from')) {
                // Free-form provenance tag (e.g. 'tmdb', 'manual', 'csv-bulk').
                $table->string('imported_from', 32)->nullable()->after('imdb_id');
            }

            if (! Schema::hasColumn('movies', 'imported_at')) {
                $table->timestamp('imported_at')->nullable()->after('imported_from');
            }
        });
    }

    public function down(): void
    {
        Schema::table('movies', function (Blueprint $table) {
            // Drop in reverse order. Indexes are auto-dropped with the column.
            foreach ([
                'imported_at',
                'imported_from',
                'tagline',
                'runtime_minutes',
                'imdb_id',
                'tmdb_id',
            ] as $col) {
                if (Schema::hasColumn('movies', $col)) {
                    if ($col === 'tmdb_id') {
                        // Named unique index — drop explicitly before column.
                        try {
                            $table->dropUnique(['tmdb_id']);
                        } catch (\Throwable) {
                            // Already dropped or never existed — no-op.
                        }
                    }
                    if ($col === 'imdb_id') {
                        try {
                            $table->dropIndex(['imdb_id']);
                        } catch (\Throwable) {
                        }
                    }
                    $table->dropColumn($col);
                }
            }
        });
    }
};
