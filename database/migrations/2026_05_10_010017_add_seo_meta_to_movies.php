<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add SEO meta columns to movies table.
 *
 * Columns are populated by App\Services\Ai\Tasks\SeoMetaGenerator
 * (and the GenerateMovieSeo job / flik:ai:seo-all command).
 *
 * Idempotent — safe to run multiple times.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movies', function (Blueprint $table) {
            if (!Schema::hasColumn('movies', 'seo_title')) {
                $table->string('seo_title', 160)->nullable()->after('youtube_key');
            }
            if (!Schema::hasColumn('movies', 'seo_description')) {
                $table->string('seo_description', 320)->nullable()->after('seo_title');
            }
            if (!Schema::hasColumn('movies', 'seo_keywords')) {
                $table->string('seo_keywords', 500)->nullable()->after('seo_description');
            }
            if (!Schema::hasColumn('movies', 'seo_generated_at')) {
                $table->timestamp('seo_generated_at')->nullable()->after('seo_keywords');
            }
        });
    }

    public function down(): void
    {
        Schema::table('movies', function (Blueprint $table) {
            foreach (['seo_generated_at', 'seo_keywords', 'seo_description', 'seo_title'] as $col) {
                if (Schema::hasColumn('movies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
