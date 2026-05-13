<?php

namespace App\Providers;

use App\Events\SecurityEventLogged;
use App\Listeners\PushSecurityAlerts;
use App\Listeners\SendLoginAlert;
use Illuminate\Auth\Events\Login;
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

        // Security: detect sign-ins from new devices / countries and
        // alert the user via in-app notification + queued email.
        // Listener runs synchronously in the auth flow but defers the
        // mail to the queue.
        Login::class => [
            SendLoginAlert::class,
        ],

        // Security: real-time Slack/Discord fan-out for severity-gated
        // events fired by AuditLogger::security(). Listener no-ops when
        // SECURITY_ALERTS_ENABLED=false or webhooks are not configured.
        SecurityEventLogged::class => [
            PushSecurityAlerts::class,
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
