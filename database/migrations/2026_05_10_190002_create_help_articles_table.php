<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Help Center: knowledge-base articles authored by support / content team.
 *
 * - `body` stores the markdown source (always authoritative).
 * - `body_html` is the rendered cache, populated by HelpArticle::setBodyAttribute.
 * - `tags` is a JSON array of free-form strings (e.g. ["billing","ios"]) used
 *   by HelpSearch::relatedTo for relevance fallback when category is missing.
 * - `last_reviewed_at` is a compliance-review timestamp shown in the public
 *   article header — surfacing it builds trust in the doc's freshness.
 * - FULLTEXT(title, body) is only added on MySQL/MariaDB so the migration is
 *   safe to run on SQLite (used by the test suite). SQLite falls back to
 *   LIKE-based search inside HelpArticle::search().
 *
 * Idempotent: bails when the table already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('help_articles')) {
            return;
        }

        Schema::create('help_articles', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 160)->unique();
            $table->string('title', 200);
            $table->longText('body');
            $table->longText('body_html')->nullable();
            $table->text('excerpt')->nullable();

            $table->foreignId('category_id')
                ->nullable()
                ->constrained('help_categories')
                ->nullOnDelete();

            $table->enum('status', ['draft', 'published'])->default('draft');

            $table->integer('views_count')->default(0);
            $table->integer('helpful_count')->default(0);
            $table->integer('not_helpful_count')->default(0);
            $table->smallInteger('sort_order')->default(0);

            $table->json('tags')->nullable();

            $table->foreignId('author_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('last_reviewed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'sort_order']);
            $table->index(['category_id', 'sort_order']);
        });

        // MySQL/MariaDB only — FULLTEXT is not portable to SQLite/PG. The
        // model's search() helper feature-detects and falls back to LIKE.
        if (DB::connection()->getDriverName() === 'mysql') {
            try {
                DB::statement('ALTER TABLE help_articles ADD FULLTEXT help_articles_fulltext (title, body)');
            } catch (\Throwable $e) {
                // Best-effort: storage engine may not support FULLTEXT (rare
                // on modern InnoDB). Search degrades to LIKE — no crash.
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('help_articles');
    }
};
