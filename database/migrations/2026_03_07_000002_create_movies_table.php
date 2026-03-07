<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('movies', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('original_title')->nullable();
            $table->text('overview')->nullable();
            $table->string('poster_path')->nullable();
            $table->string('backdrop_path')->nullable();
            $table->date('release_date')->nullable();
            $table->decimal('vote_average', 3, 1)->default(0);
            $table->integer('vote_count')->default(0);
            $table->decimal('popularity', 10, 2)->default(0);
            $table->boolean('is_popular')->default(false);
            $table->boolean('is_trending')->default(false);
            $table->string('video_url')->nullable();
            $table->string('youtube_key')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('movies');
    }
};
