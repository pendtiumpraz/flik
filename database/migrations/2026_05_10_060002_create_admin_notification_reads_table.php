<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * admin_notification_reads
 * --------------------------------------------------------------------------
 * Per-recipient read state for staff alerts. A row exists iff the given
 * staff user has acknowledged the given admin notification. Absence of a
 * row = unread.
 *
 * The (admin_notification_id, user_id) unique constraint makes the model's
 * `markReadFor()` helper an idempotent upsert — repeated bell-click bursts
 * never produce duplicate rows even under contention.
 *
 * Cascade on delete on BOTH FKs because:
 *   - dropping a notification (rare; admin housekeeping) should sweep its
 *     read receipts; they'd be orphaned otherwise.
 *   - deleting a user (GDPR erasure) should erase their read history; the
 *     parent notification stays for the rest of the staff.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_notification_reads', function (Blueprint $table) {
            $table->id();

            $table->foreignId('admin_notification_id')
                ->constrained('admin_notifications')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->timestamp('read_at')->useCurrent();

            // Idempotency guard for the upsert path in markReadFor().
            $table->unique(['admin_notification_id', 'user_id'], 'adm_notif_reads_unique');

            // "What did I read recently?" — drives the per-user feed view.
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_notification_reads');
    }
};
