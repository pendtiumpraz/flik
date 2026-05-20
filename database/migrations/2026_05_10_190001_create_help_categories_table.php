<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Help Center: category buckets that group articles.
 *
 * articles_count is a denormalised counter maintained by HelpArticle on
 * save/delete so the public landing page doesn't COUNT(*) per card.
 *
 * Idempotent: bails early if the table already exists so re-running
 * `migrate` against a partially-seeded database is safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('help_categories')) {
            return;
        }

        Schema::create('help_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('icon', 40)->nullable();
            $table->smallInteger('sort_order')->default(0);
            $table->integer('articles_count')->default(0);
            $table->timestamps();

            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_categories');
    }
};
