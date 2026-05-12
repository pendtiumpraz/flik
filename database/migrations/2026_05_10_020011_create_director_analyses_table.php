<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Director auteur analyses (O12) — caches AI-generated auteur breakdowns
 * keyed by director name. One row per director.
 *
 * Idempotent: safe to re-run when the table already exists or is partially
 * populated by a prior migration attempt.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('director_analyses')) {
            return;
        }

        Schema::create('director_analyses', function (Blueprint $table) {
            $table->id();
            $table->string('director_name', 200)->unique();
            $table->string('slug', 220)->unique();
            $table->json('data');
            $table->json('source_urls')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->index('generated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('director_analyses');
    }
};
