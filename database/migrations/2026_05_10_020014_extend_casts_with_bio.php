<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * X-Ray (O14) — Extend casts table with biographical metadata.
 *
 * Powers the Netflix-style "X-Ray" actor info overlay:
 *   - bio: AI-generated short biography in Bahasa Indonesia (~200 words)
 *   - wikipedia_url: source link for further reading
 *   - birth_date / nationality: structured facts
 *   - bio_generated_at: idempotency marker (CastBiographyEnricher skips if set)
 *   - tmdb_id: optional cross-reference to TMDB person ID
 *
 * Idempotent: every column add is wrapped in hasColumn() so re-running is safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('casts')) {
            return;
        }

        Schema::table('casts', function (Blueprint $table) {
            if (!Schema::hasColumn('casts', 'bio')) {
                $table->text('bio')->nullable()->after('profile_path');
            }
            if (!Schema::hasColumn('casts', 'wikipedia_url')) {
                $table->string('wikipedia_url')->nullable()->after('bio');
            }
            if (!Schema::hasColumn('casts', 'birth_date')) {
                $table->date('birth_date')->nullable()->after('wikipedia_url');
            }
            if (!Schema::hasColumn('casts', 'nationality')) {
                $table->string('nationality')->nullable()->after('birth_date');
            }
            if (!Schema::hasColumn('casts', 'bio_generated_at')) {
                $table->timestamp('bio_generated_at')->nullable()->after('nationality');
            }
            if (!Schema::hasColumn('casts', 'tmdb_id')) {
                $table->integer('tmdb_id')->nullable()->after('bio_generated_at');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('casts')) {
            return;
        }

        Schema::table('casts', function (Blueprint $table) {
            foreach (['tmdb_id', 'bio_generated_at', 'nationality', 'birth_date', 'wikipedia_url', 'bio'] as $col) {
                if (Schema::hasColumn('casts', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
