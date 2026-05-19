<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-click event for campaign links.
 *
 * Written by App\Http\Controllers\EmailTrackingController::click on every
 * redirect hit. A single recipient may have many click rows (multiple links
 * + repeated clicks); the campaign-level `click_count` aggregate counts
 * UNIQUE recipients via the `first_clicked_at` flag on email_recipients.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_link_clicks')) {
            return;
        }

        Schema::create('email_link_clicks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('email_recipient_id')
                  ->constrained('email_recipients')
                  ->cascadeOnDelete();

            $table->text('original_url');
            $table->timestamp('clicked_at');

            $table->timestamps();

            // Reporting: per-recipient click history sorted by recency.
            $table->index(['email_recipient_id', 'clicked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_link_clicks');
    }
};
