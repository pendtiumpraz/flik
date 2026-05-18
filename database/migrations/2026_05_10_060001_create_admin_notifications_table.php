<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * admin_notifications
 * --------------------------------------------------------------------------
 * Separate broadcast queue for staff-facing realtime alerts (DISTINCT from
 * the user-facing `notifications` table which is owned by App\Models\Notification).
 *
 * Each row represents a single fan-out event ("comment.new",
 * "payment.success", "security.suspicious", etc.) whose audience is encoded
 * either as the literal `all_admins` token OR as a comma-separated list of
 * role names (e.g. `finance,super_admin`). The matching pattern is owned by
 * AdminNotification::scopeForUser so consumers never need to parse it.
 *
 * NOTE: this table does NOT carry a `read_at` column — each admin's read
 * state is stored on the join table `admin_notification_reads` so the same
 * notification can be marked read independently per recipient.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_notifications', function (Blueprint $table) {
            $table->id();

            // Namespaced category — dotted convention, mirrors audit_logs.action
            // so peer agents can pivot/group on it cheaply. Length 60 covers
            // every category we expect (e.g. "billing.subscription.cancelled").
            $table->string('category', 60);

            $table->string('title', 200);
            $table->text('message');

            // Severity drives the bell colour, sound, and toast urgency.
            // Validated at the service layer; the DB enum is the second line
            // of defence so a stray writer can never persist an unknown value.
            $table->enum('severity', ['info', 'warning', 'critical'])->default('info');

            // Free-form structured payload (target ids, diff snapshots, etc.).
            // Consumers SHOULD treat this as opaque — schema lives per-category
            // in the dispatching service, not here.
            $table->json('meta')->nullable();

            // Optional deep-link rendered as the "View" button in the bell list.
            $table->string('action_url')->nullable();

            // Audience encoding:
            //   - the literal token `all_admins` → broadcasts to every staff user
            //   - comma-separated role-name list → e.g. `finance,super_admin`
            //   - `super_admin_only` is just a 1-element list with `super_admin`
            //     stored as the canonical form.
            // 120 chars accommodates ~10 role names with their separators.
            $table->string('audience', 120);

            // Single timestamp — no updated_at because rows are immutable
            // once created (mutations happen on the reads pivot, not here).
            $table->timestamp('created_at')->useCurrent();

            // Query shapes we optimise for:
            //   - "stream of category X over time" (admin dashboards / RSS)
            //   - "scope to my audience, newest first" (bell list)
            //   - "critical-first" (severity badge filter)
            $table->index(['category', 'created_at']);
            $table->index(['audience', 'created_at']);
            $table->index('severity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_notifications');
    }
};
