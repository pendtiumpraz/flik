<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ensure `watchlists(user_id, movie_id)` is unique at the DB layer.
 *
 * The original create-table migration declares the unique index, but
 * environments that ran an earlier draft of the schema (or that
 * imported fixtures via raw inserts) can end up with duplicate rows
 * and no enforcement at the DB level. The audit (FIX #8 / E-5) calls
 * this out as a race-condition foot-gun: WatchlistController::toggle
 * does `where()->first()` then `create()`, which two browser tabs can
 * race past, leaving a duplicate that the next toggle only half-removes.
 *
 * This migration:
 *   1. Deletes duplicates first (keep the LOWEST id per (user, movie)).
 *   2. Adds the unique compound index if it isn't already there.
 *
 * Both steps are idempotent / safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('watchlists')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        // 1. Drop duplicate rows (keep lowest id per user+movie). Driver-aware:
        // the MySQL multi-table DELETE is invalid on Postgres and — because PG
        // aborts the whole transaction on a failed statement — must NOT be
        // attempted there. Postgres/SQLite use the portable grouped delete.
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement(
                'DELETE w1 FROM watchlists w1 '
                .'INNER JOIN watchlists w2 ON w1.user_id = w2.user_id '
                .'AND w1.movie_id = w2.movie_id '
                .'WHERE w1.id > w2.id'
            );
        } else {
            $dups = DB::table('watchlists')
                ->select('user_id', 'movie_id', DB::raw('MIN(id) as keep_id'))
                ->groupBy('user_id', 'movie_id')
                ->havingRaw('COUNT(*) > 1')
                ->get();
            foreach ($dups as $row) {
                DB::table('watchlists')
                    ->where('user_id', $row->user_id)
                    ->where('movie_id', $row->movie_id)
                    ->where('id', '!=', $row->keep_id)
                    ->delete();
            }
        }

        // 2. Add the unique index only if absent — check first (portable) rather
        // than catch a duplicate error, which would abort the PG transaction.
        // On a fresh DB the index already exists (create-table) → clean no-op.
        $indexName = 'watchlists_user_id_movie_id_unique';
        $exists = match ($driver) {
            'pgsql' => DB::selectOne(
                'SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ? LIMIT 1',
                ['watchlists', $indexName]
            ) !== null,
            'mysql', 'mariadb' => DB::selectOne(
                'SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
                ['watchlists', $indexName]
            ) !== null,
            default => false,
        };
        if (! $exists) {
            try {
                Schema::table('watchlists', function (Blueprint $table) use ($indexName) {
                    $table->unique(['user_id', 'movie_id'], $indexName);
                });
            } catch (\Throwable) {
                // race / already exists — fine
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('watchlists')) {
            return;
        }

        try {
            Schema::table('watchlists', function (Blueprint $table) {
                $table->dropUnique('watchlists_user_id_movie_id_unique');
            });
        } catch (\Throwable) {
            // Already dropped or never created — fine.
        }
    }
};
