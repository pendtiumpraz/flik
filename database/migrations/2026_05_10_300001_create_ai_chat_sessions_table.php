<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persistent per-user AI chat history.
 *
 * Each `ai_chat_sessions` row represents one conversation thread. Messages
 * (`ai_chat_messages`) belong to a session and to a user. Loading the most
 * recent session lets the chatbot pick up where the user left off + feed
 * the last N messages back into the AI for context continuity.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_chat_sessions')) {
            Schema::create('ai_chat_sessions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('title', 160)->nullable();
                $table->unsignedInteger('messages_count')->default(0);
                $table->timestamp('last_message_at')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'last_message_at']);
            });
        }

        if (! Schema::hasTable('ai_chat_messages')) {
            Schema::create('ai_chat_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ai_chat_session_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('role', 16); // 'user' | 'bot' | 'system'
                $table->text('text');
                $table->string('provider', 40)->nullable();
                $table->string('model', 80)->nullable();
                $table->boolean('used_web_search')->default(false);
                $table->json('web_sources')->nullable();
                $table->json('context_films')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['ai_chat_session_id', 'created_at']);
                $table->index(['user_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chat_messages');
        Schema::dropIfExists('ai_chat_sessions');
    }
};
