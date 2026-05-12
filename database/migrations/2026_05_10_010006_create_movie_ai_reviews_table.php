<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movie_ai_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movie_id')->constrained()->cascadeOnDelete();

            // Reviewer perspective — one of: critic, casual, family, academic
            $table->enum('perspective', ['critic', 'casual', 'family', 'academic']);

            $table->string('title');
            $table->text('body');

            // Optional star rating (e.g. 7.5/10) — not all perspectives produce one
            $table->decimal('rating', 3, 1)->nullable();

            // Provenance: which AI provider/model produced this review
            $table->string('provider_used');
            $table->timestamp('generated_at');

            $table->timestamps();

            $table->index(['movie_id', 'perspective']);
            $table->unique(['movie_id', 'perspective'], 'movie_perspective_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movie_ai_reviews');
    }
};
