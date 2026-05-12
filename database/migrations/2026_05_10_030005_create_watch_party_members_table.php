<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Membership record for a watch_parties room. The host is also stored
 * here so member counts + presence checks are uniform.
 *
 * left_at non-null = soft-left (still in room history but not present).
 * Unique (watch_party_id, user_id) prevents duplicate joins; a re-join
 * just clears left_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('watch_party_members')) {
            return;
        }

        Schema::create('watch_party_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('watch_party_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            $table->unique(['watch_party_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watch_party_members');
    }
};
