<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('year_in_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Year being summarised (e.g. 2025). smallint range is plenty.
            $table->smallInteger('year')->unsigned();

            // Aggregated stats snapshot (films, hours, top genres/actors/decades, …).
            $table->json('stats');

            // AI-written narrative (3–4 paragraphs Bahasa Indonesia).
            $table->text('narrative');

            // When the review was generated (separate from row created_at so we can
            // distinguish "first generated" from later updates if regen is added).
            $table->timestamp('generated_at')->nullable();

            // Bumped each time the user shares this recap.
            $table->unsignedInteger('shared_count')->default(0);

            $table->timestamps();

            // One review per user per year.
            $table->unique(['user_id', 'year'], 'year_in_reviews_user_year_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('year_in_reviews');
    }
};
