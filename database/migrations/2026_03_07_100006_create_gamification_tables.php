<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Coins ledger
        Schema::create('coins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->integer('amount'); // positive = earn, negative = spend
            $table->string('type'); // daily_reward, watch_movie, achievement, purchase, spend
            $table->string('description')->nullable();
            $table->timestamps();
        });

        // Achievements
        Schema::create('achievements', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description');
            $table->string('icon')->nullable(); // emoji or icon class
            $table->integer('coin_reward')->default(0);
            $table->integer('xp_reward')->default(0);
            $table->string('condition_type'); // watch_count, rating_count, streak, etc.
            $table->integer('condition_value')->default(1);
            $table->enum('tier', ['bronze', 'silver', 'gold', 'platinum'])->default('bronze');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // User achievements
        Schema::create('user_achievements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('achievement_id')->constrained()->onDelete('cascade');
            $table->timestamp('unlocked_at');
            $table->timestamps();
            $table->unique(['user_id', 'achievement_id']);
        });

        // User levels
        Schema::create('user_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade')->unique();
            $table->integer('level')->default(1);
            $table->integer('xp')->default(0);
            $table->integer('total_coins')->default(0);
            $table->integer('watch_streak')->default(0);
            $table->date('last_streak_date')->nullable();
            $table->timestamps();
        });

        // Daily rewards
        Schema::create('daily_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->date('reward_date');
            $table->integer('day_number'); // 1-7 streak day
            $table->integer('coins_earned');
            $table->timestamps();
            $table->unique(['user_id', 'reward_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_rewards');
        Schema::dropIfExists('user_levels');
        Schema::dropIfExists('user_achievements');
        Schema::dropIfExists('achievements');
        Schema::dropIfExists('coins');
    }
};
