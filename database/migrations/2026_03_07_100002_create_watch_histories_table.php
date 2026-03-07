<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watch_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('movie_id')->constrained()->onDelete('cascade');
            $table->integer('progress_seconds')->default(0);
            $table->integer('duration_seconds')->nullable();
            $table->boolean('completed')->default(false);
            $table->timestamp('last_watched_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'movie_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watch_histories');
    }
};
