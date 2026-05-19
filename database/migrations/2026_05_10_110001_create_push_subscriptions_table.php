<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * push_subscriptions
 * --------------------------------------------------------------------------
 * Web Push (RFC 8030 / 8291 / 8292) subscription registry. One row per
 * (browser, device, user) tuple — anonymous opt-in is allowed so visitors
 * who never log in can still receive promotional push (user_id NULL).
 *
 * Lifecycle:
 *   - INSERT on `/api/push/subscribe` (upsert by endpoint — re-subscribing
 *     the same browser does not duplicate rows).
 *   - last_used_at is touched on every successful send.
 *   - failure_count is bumped on transient delivery errors. Hard 410/404
 *     responses from the push service mean the subscription has been
 *     revoked browser-side → the row is soft-deleted (deleted_at set, but
 *     we use a simple delete() here since we never resurrect expired subs).
 *
 * Indexes:
 *   - user_id for "send to a single user" queries.
 *   - endpoint UNIQUE so the upsert can target it directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();

            // Nullable FK — anonymous subscribers are allowed. Cascade so
            // hard-deleting a user drops their push subs without manual
            // cleanup (push delivery would 410 anyway since the browser
            // session is unrelated to our user table).
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->cascadeOnDelete();

            // Push service endpoint URL — typically ~300 chars for FCM,
            // Mozilla autopush is similar. text() picks LONGTEXT under
            // MySQL which leaves room for any future provider.
            $table->text('endpoint');

            // Client public key (P-256 ECDH) — 65 raw bytes → 88 base64url
            // chars max. 128 leaves headroom.
            $table->string('p256dh', 128);

            // Auth secret — 16 raw bytes → 24 base64url chars max.
            $table->string('auth_key', 40);

            $table->text('user_agent')->nullable();

            // Coarse device classification — 'mobile', 'tablet', 'desktop'.
            // Used by the admin broadcaster to scope sends.
            $table->string('device_type', 20)->nullable();

            $table->timestamps();

            // Last successful delivery. Lets the admin UI surface "active in
            // last 30 days" segmentation later.
            $table->timestamp('last_used_at')->nullable();

            // Consecutive failed sends — when a single sub repeatedly fails
            // a transient error we eventually give up and prune it.
            $table->unsignedTinyInteger('failure_count')->default(0);

            $table->index('user_id');
            // Endpoint is TEXT (LONGTEXT on MySQL) so we cannot index it
            // directly. A separate, model-maintained sha1 hash gives us a
            // tight index for the upsert path AND keeps the schema portable
            // (SQLite test suites don't support generated columns).
            // See PushSubscription::saving() for the writer.
            $table->string('endpoint_hash', 40)->nullable();
            $table->unique('endpoint_hash', 'push_subs_endpoint_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
