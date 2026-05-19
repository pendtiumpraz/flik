<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend `movies` so a single row can represent either a standalone
 * movie OR a multi-season TV series.
 *
 * Idempotent: every column add is guarded by Schema::hasColumn() so
 * partial re-runs (e.g. seeding swarm states) do not double-add.
 * Default is 'movie' so every existing row keeps current semantics
 * with zero behavioural change — series rows are opt-in via the admin
 * form (or `flik:dev:seed-test-series` helper).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movies', function (Blueprint $table) {
            if (! Schema::hasColumn('movies', 'content_type')) {
                // MySQL ENUM keeps the column tight + indexable. Default 'movie'
                // means existing rows + future seeds default to the old shape.
                $table->enum('content_type', ['movie', 'series'])
                    ->default('movie')
                    ->after('youtube_key')
                    ->index();
            }

            if (! Schema::hasColumn('movies', 'total_seasons')) {
                $table->unsignedSmallInteger('total_seasons')
                    ->nullable()
                    ->after('content_type');
            }

            if (! Schema::hasColumn('movies', 'total_episodes')) {
                $table->unsignedSmallInteger('total_episodes')
                    ->nullable()
                    ->after('total_seasons');
            }
        });
    }

    public function down(): void
    {
        Schema::table('movies', function (Blueprint $table) {
            if (Schema::hasColumn('movies', 'total_episodes')) {
                $table->dropColumn('total_episodes');
            }
            if (Schema::hasColumn('movies', 'total_seasons')) {
                $table->dropColumn('total_seasons');
            }
            if (Schema::hasColumn('movies', 'content_type')) {
                // Index is auto-dropped with the column on MySQL/Postgres.
                $table->dropColumn('content_type');
            }
        });
    }
};
