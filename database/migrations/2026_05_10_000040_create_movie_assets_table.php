<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('movie_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movie_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['slider', 'poster', 'backdrop']);
            $table->string('path', 500);
            $table->string('label', 120)->nullable()->comment('e.g., "Variant A", "Lebaran 2026", "Promo Original"');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('rotation_hours')->default(0)
                  ->comment('0 = no rotation (use sort_order), >0 = rotate every X hours deterministically');
            $table->timestamps();

            $table->index(['movie_id', 'type', 'is_active']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('movie_assets');
    }
};
