<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * follows
 * --------------------------------------------------------------------------
 * Edges of the social graph: `follower_id` (the actor) follows `followed_id`
 * (the target). The semantics are intentionally directional — Twitter-style,
 * not Facebook-style — so a one-way follow does NOT imply a back-follow.
 *
 * Constraints:
 *   - UNIQUE (follower_id, followed_id) — idempotent follows; second insert
 *     short-circuits in the trait via `firstOrCreate`, but the unique key is
 *     the authoritative defence against a race between two concurrent POSTs.
 *   - CHECK (follower_id != followed_id) on engines that support it. We
 *     deliberately skip SQLite because Laravel's schema builder cannot
 *     express CHECK constraints there reliably; the User\Concerns\Follows
 *     trait enforces self-follow rejection in app code regardless of engine.
 *
 * Indexes:
 *   - (followed_id, created_at) — drives "who started following me, newest
 *     first" (followers list view) and the activity-feed JOINs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follows', function (Blueprint $table) {
            $table->id();

            // Both edges cascade on user deletion so a removed account
            // never leaves orphaned follow rows behind. GDPR-friendly: a
            // self-service account erase wipes both halves of the graph.
            $table->foreignId('follower_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('followed_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->timestamps();

            // Prevents duplicate follows even under race conditions.
            $table->unique(['follower_id', 'followed_id'], 'follows_pair_unique');

            // Reverse-lookup index: "list everyone following user X, newest
            // first". The pair-unique key already covers the forward
            // "following list" lookup via its leading column.
            $table->index(['followed_id', 'created_at']);
        });

        // ---- DB-level guard against self-follow ----
        // SQLite's Laravel schema builder doesn't reliably emit CHECK
        // constraints in older versions; the application layer is the
        // authoritative guard there. MySQL/MariaDB/Postgres all accept
        // a CHECK so we add one as defence-in-depth.
        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
            try {
                DB::statement(
                    'ALTER TABLE follows ADD CONSTRAINT follows_no_self_follow '
                    .'CHECK (follower_id <> followed_id)'
                );
            } catch (\Throwable $e) {
                // Older MariaDB versions silently ignore CHECK; we don't
                // want migrate to fail if the constraint can't be added.
                // The trait + Follows::follow() guard remains authoritative.
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('follows');
    }
};
