<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Editorial Blog — related-movies pivot.
 *
 * Drives the "movies mentioned in this article" rail on /blog/{slug}.
 * sort_order is admin-managed (drag-reorder in the post editor).
 * Cascade on both sides because the pivot has no useful state of its own
 * once either parent disappears.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('blog_post_movie')) {
            return;
        }

        Schema::create('blog_post_movie', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_post_id')->constrained('blog_posts')->cascadeOnDelete();
            $table->foreignId('movie_id')->constrained('movies')->cascadeOnDelete();
            $table->smallInteger('sort_order')->default(0);

            $table->unique(['blog_post_id', 'movie_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_post_movie');
    }
};
