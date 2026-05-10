<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('movie_subtitles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movie_id')->constrained()->cascadeOnDelete();

            // Language: BCP-47 code with optional script/variant
            // Examples: id, en, ja, ko, zh-Hans, zh-Hant, ar, ar-x-harakat, ms-MY
            $table->string('language_code', 20)->index();
            $table->string('label', 80)->comment('Display label e.g. "Bahasa Indonesia", "العربية (مع التشكيل)"');

            // Storage
            $table->string('webvtt_path', 500)->comment('Path to .vtt file in storage');
            $table->string('disk', 20)->default('public');

            // Provenance
            $table->boolean('is_auto_generated')->default(false)->comment('From transcription (Whisper)');
            $table->boolean('is_translated')->default(false)->comment('Translated from another language');
            $table->string('source_language', 20)->nullable()->comment('Original language if translated');
            $table->string('generator_model', 60)->nullable()->comment('e.g. gpt-4o-mini-transcribe, deepseek-v4-flash');

            // Variants (for languages with multiple scripts/variants)
            $table->string('variant', 40)->nullable()->comment('e.g. harakat-on, harakat-off, simplified, traditional');

            // Quality / status
            $table->enum('status', ['pending', 'processing', 'ready', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->unsignedInteger('cue_count')->nullable()->comment('Number of subtitle cues');
            $table->unsignedInteger('duration_seconds')->nullable();

            // Cost tracking
            $table->decimal('cost_usd', 10, 6)->default(0);

            // Default flag — one default per movie
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['movie_id', 'language_code', 'variant'], 'movie_lang_variant_unique');
            $table->index(['movie_id', 'is_active', 'is_default']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('movie_subtitles');
    }
};
