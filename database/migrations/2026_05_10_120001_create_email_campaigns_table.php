<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email Campaigns — admin-composed bulk email broadcasts.
 *
 * Pairs with `email_recipients` (per-user tracking row) and `email_link_clicks`
 * (per-click row). Counts on this row are denormalised aggregates kept in
 * sync by App\Http\Controllers\EmailTrackingController + App\Jobs\SendCampaignEmail.
 * Treat them as cached values — the per-recipient rows are the source of truth.
 *
 * `segment_definition` mirrors the small DSL parsed by App\Services\Email\SegmentBuilder.
 * Shape examples:
 *   {"type":"all"}
 *   {"type":"role","name":"subscriber"}
 *   {"type":"plan","plan_id":1}
 *   {"type":"inactive_days","days":30}
 *   {"type":"new_signups","days":7}
 *   {"type":"custom_emails","emails":["a@b.com","c@d.com"]}
 *   {"type":"and","children":[ {...}, {...} ]}
 *   {"type":"or","children":[ {...}, {...} ]}
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_campaigns')) {
            return;
        }

        Schema::create('email_campaigns', function (Blueprint $table) {
            $table->id();

            $table->string('name', 160);
            $table->string('subject', 200);
            $table->string('preheader', 160)->nullable();
            $table->longText('html_body');
            $table->longText('plain_body')->nullable();

            // Segment DSL — see class docblock above.
            $table->json('segment_definition');

            // Snapshot count taken at create time so the list view doesn't
            // have to re-resolve the segment for every row. Recomputed on
            // demand from /admin/email-campaigns/{id}/preview-audience.
            $table->unsignedInteger('audience_estimated')->default(0);

            $table->enum('status', ['draft', 'queued', 'sending', 'sent', 'cancelled'])
                  ->default('draft');

            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();

            // Denormalised aggregates — kept in sync by job + tracking endpoints.
            $table->unsignedInteger('send_count')->default(0);
            $table->unsignedInteger('open_count')->default(0);
            $table->unsignedInteger('click_count')->default(0);
            $table->unsignedInteger('bounce_count')->default(0);

            $table->foreignId('created_by_user_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->timestamps();

            $table->index('status');
            $table->index('scheduled_at');
            $table->index(['created_by_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_campaigns');
    }
};
