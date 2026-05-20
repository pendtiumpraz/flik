<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Editorial Blog — posts (markdown source + rendered HTML cache).
 *
 * Lifecycle: draft → scheduled → published → archived. `scheduled_for`
 * is the wall-clock the cron flip aims for; `published_at` is the actual
 * moment the post became publicly visible.
 *
 * body holds the markdown source (round-trippable), body_html caches the
 * render so /blog/{slug} never has to re-parse on every request.
 *
 * SoftDeletes so editors can recover an accidentally-deleted post.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('blog_posts')) {
            return;
        }

        Schema::create('blog_posts', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 160)->unique();
            $table->string('title', 200);
            $table->text('excerpt')->nullable();
            $table->longText('body');             // markdown source
            $table->longText('body_html')->nullable(); // rendered cache
            $table->string('cover_image')->nullable();

            $table->enum('status', ['draft', 'scheduled', 'published', 'archived'])->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('scheduled_for')->nullable();

            $table->foreignId('author_user_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('category_id')
                ->nullable()
                ->constrained('blog_categories')
                ->nullOnDelete();

            $table->smallInteger('reading_minutes')->default(0);
            $table->unsignedInteger('views_count')->default(0);

            $table->string('seo_title', 200)->nullable();
            $table->text('seo_description')->nullable();

            $table->boolean('is_featured')->default(false);

            $table->timestamps();
            $table->softDeletes();

            // Hot read paths: published timeline, slug lookup, by-category,
            // and the featured-on-home spotlight.
            $table->index(['status', 'published_at']);
            $table->index(['category_id', 'published_at']);
            $table->index(['is_featured', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_posts');
    }
};
