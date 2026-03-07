<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Free, Basic, Premium, Ultra
            $table->string('slug')->unique();
            $table->decimal('price', 12, 2)->default(0);
            $table->string('billing_cycle')->default('monthly'); // monthly, yearly
            $table->integer('max_screens')->default(1);
            $table->string('video_quality')->default('720p'); // 480p, 720p, 1080p, 4K
            $table->boolean('ads_free')->default(false);
            $table->boolean('download_enabled')->default(false);
            $table->text('features')->nullable(); // JSON features list
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('subscription_plan_id')->constrained()->onDelete('cascade');
            $table->string('status')->default('active'); // active, cancelled, expired, paused
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('transaction_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('subscription_plans');
    }
};
