<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `seasons` — direct children of a (series-flavoured) movie row.
 *
 * Cascade deletes from movies so that removing a series wipes its
 * entire tree of seasons + episodes + per-episode watch history
 * (the FKs below + on watch_histories.episode_id chain through).
 * The (movie_id, season_number) unique index keeps the catalog
 * shape sane — you can't accidentally end up with two "Season 2"s.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('seasons')) {
            return;
        }

        Schema::create('seasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movie_id')
                ->constrained('movies')
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('season_number');
            $table->string('title')->nullable();
            $table->text('overview')->nullable();
            $table->string('poster_path')->nullable();
            $table->date('air_date')->nullable();
            $table->unsignedSmallInteger('episode_count')->default(0);
            $table->timestamps();

            $table->unique(['movie_id', 'season_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seasons');
    }
};
