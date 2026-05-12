<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movies', function (Blueprint $table) {
            // AI-generated taxonomy: mood, era, themes, audience, intensity.
            // Populated by App\Services\Ai\Tasks\MovieTagger.
            if (!Schema::hasColumn('movies', 'ai_tags')) {
                $table->json('ai_tags')->nullable()->after('youtube_key');
            }

            if (!Schema::hasColumn('movies', 'ai_tagged_at')) {
                $table->timestamp('ai_tagged_at')->nullable()->after('ai_tags');
            }
        });
    }

    public function down(): void
    {
        Schema::table('movies', function (Blueprint $table) {
            if (Schema::hasColumn('movies', 'ai_tagged_at')) {
                $table->dropColumn('ai_tagged_at');
            }

            if (Schema::hasColumn('movies', 'ai_tags')) {
                $table->dropColumn('ai_tags');
            }
        });
    }
};
