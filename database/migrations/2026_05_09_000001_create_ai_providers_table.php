<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('ai_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('provider');
            $table->string('model');
            $table->text('api_key');
            $table->string('base_url')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('priority')->default(100);
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedBigInteger('total_tokens_used')->default(0);
            $table->decimal('total_cost_usd', 12, 4)->default(0);
            $table->timestamps();

            $table->index(['is_active', 'priority']);
            $table->index('provider');
        });
    }

    public function down()
    {
        Schema::dropIfExists('ai_providers');
    }
};
