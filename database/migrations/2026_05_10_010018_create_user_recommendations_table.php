<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('movie_id')->constrained()->cascadeOnDelete();

            // Relevance score 0.000 — 999.999
            $table->decimal('score', 5, 3)->default(0);

            // Human-readable explanation (e.g. "Karena kamu suka Sci-Fi & Christopher Nolan")
            $table->string('reason')->nullable();

            // Pipeline origin
            $table->enum('source', ['content_based', 'collaborative', 'ai_curated'])
                ->default('ai_curated');

            // Batch identifier (UUID) — groups one recompute run
            $table->string('batch_id', 36);

            // When this recommendation was generated
            $table->timestamp('generated_at')->useCurrent();

            $table->timestamps();

            // Same user can't have the same movie recommended twice within a single batch
            $table->unique(['user_id', 'movie_id', 'batch_id'], 'user_recos_unique');

            // Hot path: list-by-user-ordered-by-score-desc
            $table->index(['user_id', 'score'], 'user_recos_user_score_idx');
            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_recommendations');
    }
};
