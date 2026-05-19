<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * push_messages
 * --------------------------------------------------------------------------
 * Admin-composed broadcast push message + delivery stats. One row per
 * "campaign" — every individual delivery is fanned out by the
 * BroadcastPushMessage job, which updates sent_count / success_count /
 * failure_count as it iterates.
 *
 * Audience encoding (string, max 80 chars):
 *   - 'all'                — every subscription
 *   - 'role:rolename'      — users holding a specific role
 *   - 'user:<id>'          — a single user (legacy alias for /push/test)
 *   - 'segment:<name>'     — pluggable segment, resolved by the broadcaster
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_messages', function (Blueprint $table) {
            $table->id();

            $table->string('title', 200);
            $table->text('body');

            // Optional asset overrides — the front-end falls back to the
            // PWA logo + badge when these are NULL.
            $table->string('icon_url')->nullable();
            $table->string('badge_url')->nullable();

            // Deep-link the notification opens when clicked. Leave NULL to
            // simply focus the most recent FLiK tab.
            $table->string('action_url')->nullable();

            // Notification tag — browsers collapse repeated pushes with the
            // same tag (e.g. "newrelease") into a single notification.
            $table->string('tag', 40)->nullable();

            // See header docblock for audience encoding semantics.
            $table->string('audience', 80)->default('all');

            // Delivery stats. sent_at stamps when the broadcast job started;
            // *_count fields accumulate as the chunked iterator progresses.
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('failure_count')->default(0);

            // Author audit — who composed/scheduled this push.
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index('sent_at');
            $table->index('audience');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_messages');
    }
};
