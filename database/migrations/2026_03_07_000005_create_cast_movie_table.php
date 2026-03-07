<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('cast_movie', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cast_id')->constrained()->onDelete('cascade');
            $table->foreignId('movie_id')->constrained()->onDelete('cascade');
            $table->string('character')->nullable();
            $table->integer('order')->default(0);
            $table->unique(['cast_id', 'movie_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('cast_movie');
    }
};
