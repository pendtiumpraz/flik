<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * user_list_followers
 * --------------------------------------------------------------------------
 * Edge table: a {@see users} row follows a {@see user_lists} row. Distinct
 * from the user-to-user `follows` table (see Concerns\Follows trait) — those
 * are people, these are lists.
 *
 * Constraints:
 *   - UNIQUE (user_list_id, user_id) — idempotent follows; defends against
 *     race conditions even when the application-layer `firstOrCreate` would
 *     otherwise insert twice.
 *
 * Indexes:
 *   - (user_id) — drives the "Lists I follow" page (`/lists/following`).
 *     The unique key already covers reverse lookup (list -> followers).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_list_followers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_list_id')
                ->constrained('user_lists')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['user_list_id', 'user_id'], 'user_list_followers_pair_unique');

            // Lookup: "all lists this user follows".
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_list_followers');
    }
};
