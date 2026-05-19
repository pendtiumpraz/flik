<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-recipient tracking row for email campaigns.
 *
 * One row is created per resolved audience member at enqueue time by
 * App\Services\Email\CampaignDispatcher::enqueue. user_id is nullable
 * because the {custom_emails} segment may include addresses that don't
 * map to a User row.
 *
 * `tracking_id` is the 32-char random token embedded in the open-pixel +
 * click-redirect URLs. It is the primary join key for the public, unauth
 * tracking endpoints (see App\Http\Controllers\EmailTrackingController).
 * Random + unique so the pixel URL is unguessable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_recipients')) {
            return;
        }

        Schema::create('email_recipients', function (Blueprint $table) {
            $table->id();

            $table->foreignId('email_campaign_id')
                  ->constrained('email_campaigns')
                  ->cascadeOnDelete();

            // Nullable: custom_emails segment can include addresses without
            // a matching user account. Cascade so user deletion takes the
            // tracking history with it.
            $table->foreignId('user_id')
                  ->nullable()
                  ->constrained('users')
                  ->cascadeOnDelete();

            $table->string('email');

            // 32-char unguessable token embedded in the pixel + click URLs.
            // CHAR(32) (not VARCHAR) because every value is exactly 32 bytes —
            // saves a length byte per row at the storage layer.
            $table->char('tracking_id', 32)->unique();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('first_clicked_at')->nullable();
            $table->timestamp('bounced_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('error_reason')->nullable();

            $table->timestamps();

            // Reporting queries hit (campaign, opened) for funnel stats.
            $table->index(['email_campaign_id', 'opened_at']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_recipients');
    }
};
