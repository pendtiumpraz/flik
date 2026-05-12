<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auto-skip intro / outro / recap markers (in seconds, 3-decimal precision
 * to align with HLS segment boundaries).
 *
 * Used by the player to render "Skip Intro" / "Next Episode" affordances.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movies', function (Blueprint $table) {
            if (!Schema::hasColumn('movies', 'intro_start_seconds')) {
                $table->decimal('intro_start_seconds', 8, 3)->nullable();
            }
            if (!Schema::hasColumn('movies', 'intro_end_seconds')) {
                $table->decimal('intro_end_seconds', 8, 3)->nullable();
            }
            if (!Schema::hasColumn('movies', 'outro_start_seconds')) {
                $table->decimal('outro_start_seconds', 8, 3)->nullable();
            }
            if (!Schema::hasColumn('movies', 'recap_end_seconds')) {
                $table->decimal('recap_end_seconds', 8, 3)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('movies', function (Blueprint $table) {
            foreach (['recap_end_seconds', 'outro_start_seconds', 'intro_end_seconds', 'intro_start_seconds'] as $col) {
                if (Schema::hasColumn('movies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
