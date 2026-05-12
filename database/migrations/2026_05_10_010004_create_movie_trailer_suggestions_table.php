<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movie_trailer_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movie_id')->constrained()->cascadeOnDelete();

            // Time window inside the source film
            $table->float('start_seconds');
            $table->float('end_seconds');
            $table->float('duration_seconds')->comment('end_seconds - start_seconds (denormalised for queries)');

            // Quality scoring
            $table->decimal('score', 4, 2)->comment('1.00 - 10.00 — composite ranking score');
            $table->text('reason')->nullable()->comment('Why this window was picked (AI explanation or heuristic tag)');
            $table->decimal('audio_intensity', 4, 2)->nullable()->comment('Loudness proxy (LUFS-derived 0-10) when audio approach used');

            // Editorial flag — admin can promote one suggestion as the chosen trailer cut
            $table->boolean('is_selected')->default(false);

            $table->timestamps();

            $table->index('movie_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movie_trailer_suggestions');
    }
};
