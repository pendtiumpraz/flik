<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soundtrack Analysis (FIX #7 — wire dead SoundtrackAnalyzer service).
 *
 * Persists the JSON shape returned by SoundtrackAnalyzer::analyze() so we
 * pay the LLM cost once per film and serve cached output on every detail
 * render. Stored as JSON (vs a separate table) because the payload is a
 * single-row composite — no per-row queries needed by the consumer.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('movies', function (Blueprint $table) {
            if (! Schema::hasColumn('movies', 'soundtrack_analysis')) {
                $table->json('soundtrack_analysis')->nullable()->after('seo_keywords');
            }
            if (! Schema::hasColumn('movies', 'soundtrack_analyzed_at')) {
                $table->timestamp('soundtrack_analyzed_at')->nullable()->after('soundtrack_analysis');
            }
        });
    }

    public function down(): void
    {
        Schema::table('movies', function (Blueprint $table) {
            foreach (['soundtrack_analyzed_at', 'soundtrack_analysis'] as $col) {
                if (Schema::hasColumn('movies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
