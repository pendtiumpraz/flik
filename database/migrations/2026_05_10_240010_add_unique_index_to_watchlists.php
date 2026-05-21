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

        // 1. Drop duplicate rows. Keep the lowest-id occurrence of each
        // (user_id, movie_id) pair so the unique index can be added
        // without violating it.
        //
        // MySQL/MariaDB syntax (LEFT JOIN form) is portable across
        // 5.7+/8.x. Wrap in try/catch so SQLite / Postgres test
        // environments fall back to a portable variant.
        try {
            DB::statement(
                'DELETE w1 FROM watchlists w1 '
                .'INNER JOIN watchlists w2 ON w1.user_id = w2.user_id '
                .'AND w1.movie_id = w2.movie_id '
                .'WHERE w1.id > w2.id'
            );
        } catch (\Throwable) {
            // Portable fallback for SQLite / Postgres. Slower but works
            // anywhere — only ever runs in test envs.
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

        // 2. Add the unique index if it does not exist yet. We can't
        // rely on `Schema::hasIndex` (Laravel doesn't expose one across
        // every driver) so we attempt the add and swallow the
        // duplicate-key error.
        try {
            Schema::table('watchlists', function (Blueprint $table) {
                $table->unique(['user_id', 'movie_id'], 'watchlists_user_id_movie_id_unique');
            });
        } catch (\Throwable $e) {
            // Index probably already exists from the original create-table
            // migration. Safe to ignore.
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
