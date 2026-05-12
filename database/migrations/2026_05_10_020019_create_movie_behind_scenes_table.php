<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Behind-the-Scenes narrative sections per movie.
 *
 * One movie has up to 6 sections (production, casting, filming,
 * post_production, reception, legacy). Idempotently regenerated
 * by BehindScenesGenerator (delete-by-movie + bulk insert).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('movie_behind_scenes')) {
            return;
        }

        Schema::create('movie_behind_scenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movie_id')->constrained()->cascadeOnDelete();

            $table->enum('section', [
                'production',
                'casting',
                'filming',
                'post_production',
                'reception',
                'legacy',
            ]);

            $table->string('title');
            $table->text('content');

            // List of source URLs (Wikipedia + web search hits)
            $table->json('source_urls')->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->index(['movie_id', 'section']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movie_behind_scenes');
    }
};
