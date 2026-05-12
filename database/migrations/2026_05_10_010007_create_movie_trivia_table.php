<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movie_trivia', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movie_id')->constrained()->cascadeOnDelete();

            $table->text('fact');
            $table->enum('category', [
                'production',
                'cast',
                'reception',
                'behind_scenes',
                'easter_egg',
                'cultural',
            ])->default('production');

            $table->unsignedInteger('sort_order')->default(0);

            // Source URL — Wikipedia/web page kalau dari web search
            $table->string('source_url')->nullable();

            $table->boolean('is_verified')->default(false);

            $table->timestamps();

            $table->index('movie_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movie_trivia');
    }
};
