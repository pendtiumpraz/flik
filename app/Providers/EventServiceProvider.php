<?php

namespace App\Providers;

use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
    ];

    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot()
    {
        // Auto-moderate new comments via AI (CommentModerator → Gemini Flash-Lite / DeepSeek)
        \App\Models\Comment::created(function (\App\Models\Comment $comment) {
            try {
                app(\App\Listeners\ModerateNewComment::class)->handle($comment);
            } catch (\Throwable $e) {
                \Log::warning('Comment moderation dispatch failed', [
                    'comment_id' => $comment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        // AI spoiler detection — runs alongside moderation, separate task on `ai-realtime`.
        \App\Models\Comment::created(function (\App\Models\Comment $comment) {
            try {
                app(\App\Listeners\DetectSpoilerOnComment::class)->handle($comment);
            } catch (\Throwable $e) {
                \Log::warning('Spoiler detection dispatch failed', [
                    'comment_id' => $comment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }
}
