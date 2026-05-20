<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Help Center: per-article helpful / not-helpful feedback ledger.
 *
 * Authenticated users: composite unique on (help_article_id, user_id) so
 * each user can only vote once per article.
 *
 * Anonymous users: the feedback controller stores a SHA-256 hash of the
 * IP (`ip_hash`). We can't add a clean partial unique index across all
 * DB engines (MySQL lacks WHERE-clause indexes), so de-duplication for
 * anonymous votes is handled in the controller via a lookup + 429.
 *
 * Idempotent: bails when the table already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('help_article_feedback')) {
            return;
        }

        Schema::create('help_article_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('help_article_id')
                ->constrained('help_articles')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->cascadeOnDelete();
            $table->boolean('is_helpful');
            $table->text('comment')->nullable();
            $table->char('ip_hash', 64)->nullable();
            $table->timestamp('created_at')->nullable();

            // Auth users: 1 vote per article. Pair has been used widely in
            // other engagement tables (ratings/watchlist) — same shape.
            $table->unique(['help_article_id', 'user_id'], 'help_feedback_article_user_unique');

            // Lookup index for anonymous dedup (controller checks before insert).
            $table->index(['help_article_id', 'ip_hash'], 'help_feedback_article_ip_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_article_feedback');
    }
};
