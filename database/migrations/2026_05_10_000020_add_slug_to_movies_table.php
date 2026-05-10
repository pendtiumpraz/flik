<?php

use App\Models\Movie;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up()
    {
        Schema::table('movies', function (Blueprint $table) {
            $table->string('slug', 200)->nullable()->after('title');
        });

        // Backfill: generate slug from title for all existing movies
        Movie::query()->each(function ($movie) {
            $base = Str::slug($movie->title) ?: 'movie-' . $movie->id;
            $slug = $base;
            $i = 1;
            while (Movie::where('slug', $slug)->where('id', '!=', $movie->id)->exists()) {
                $slug = $base . '-' . (++$i);
            }
            $movie->slug = $slug;
            $movie->saveQuietly();
        });

        Schema::table('movies', function (Blueprint $table) {
            $table->unique('slug');
        });
    }

    public function down()
    {
        Schema::table('movies', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
