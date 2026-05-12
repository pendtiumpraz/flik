<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movie_quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movie_id')->constrained()->cascadeOnDelete();

            // Language of the quote (BCP-47 — matches movie_subtitles convention)
            $table->string('language_code', 20)->default('id');

            // The quote itself (original language)
            $table->text('quote');

            // Optional translation (e.g. EN translation of an ID quote)
            $table->text('translation')->nullable();

            // Speaker (if known from script/subtitle context)
            $table->string('character_name', 120)->nullable();

            // Timestamp in source video (sourced from subtitle cue start) — seconds
            $table->decimal('timestamp_seconds', 8, 3)->nullable();

            // Brief scene context / why this quote is memorable
            $table->text('context')->nullable();

            // Social share counter (deep-link to /movie/{slug}?quote={id})
            $table->unsignedInteger('share_count')->default(0);

            $table->timestamps();

            $table->index(['movie_id', 'language_code'], 'movie_quotes_movie_lang_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movie_quotes');
    }
};
