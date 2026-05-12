<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Actor — nullable because system/cron actions have no user
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // What happened — namespaced verb, e.g. "movie.uploaded", "subscription.created"
            $table->string('action', 80);

            // Target — polymorphic-style pointer (no Eloquent morph relation required)
            $table->string('subject_type', 80)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            // Request context
            $table->string('client_ip', 45)->nullable();  // IPv6 max length
            $table->string('user_agent', 255)->nullable();

            // Arbitrary structured detail (before/after diffs, amounts, etc.)
            $table->json('meta')->nullable();

            $table->timestamps();

            // Indexes match expected query shapes:
            //   - "all actions by user, latest first"
            //   - "all actions on a given subject"
            //   - "filter by action over a time window"
            $table->index(['user_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
