<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movies', function (Blueprint $table) {
            // AI-generated polished synopsis (Indonesian) for the public film detail page.
            // Distinct from `overview` (raw TMDB/manual). Falls back to `overview` if null.
            if (!Schema::hasColumn('movies', 'ai_synopsis')) {
                $table->text('ai_synopsis')->nullable()->after('overview');
            }

            if (!Schema::hasColumn('movies', 'ai_synopsis_generated_at')) {
                $table->timestamp('ai_synopsis_generated_at')->nullable()->after('ai_synopsis');
            }
        });
    }

    public function down(): void
    {
        Schema::table('movies', function (Blueprint $table) {
            if (Schema::hasColumn('movies', 'ai_synopsis_generated_at')) {
                $table->dropColumn('ai_synopsis_generated_at');
            }

            if (Schema::hasColumn('movies', 'ai_synopsis')) {
                $table->dropColumn('ai_synopsis');
            }
        });
    }
};
