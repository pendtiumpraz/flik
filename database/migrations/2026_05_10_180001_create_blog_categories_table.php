<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Editorial Blog — categories (News / Reviews / Lists / etc.).
 *
 * Sits alongside the existing AI catalog tables (swarm 20+). Kept
 * deliberately small: just a slug, display name, brand color, and
 * sort order. Per-post category linkage lives on blog_posts.category_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('blog_categories')) {
            return;
        }

        Schema::create('blog_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('name', 120);
            // Gold default matches the site theme; admins can override per-cat.
            $table->string('color', 7)->default('#C5A55A');
            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_categories');
    }
};
