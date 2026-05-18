<?php

namespace App\Providers;

use App\Events\SecurityEventLogged;
use App\Listeners\Admin\FailedJobListener;
use App\Listeners\Admin\NewUserListener;
use App\Listeners\Admin\SecurityEventListener;
use App\Listeners\PushSecurityAlerts;
use App\Listeners\SendLoginAlert;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Queue\Events\JobFailed;
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
            // Admin bell: ping admin + super_admin on every new signup.
            // Queued (default queue, $tries=2) — failure isolated from
            // the registration flow.
            NewUserListener::class,
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
        //
        // Admin bell: same event drives in-app admin notifications via
        // SecurityEventListener (severity-gated, low-events throttled).
        SecurityEventLogged::class => [
            PushSecurityAlerts::class,
            SecurityEventListener::class,
        ],

        // Admin bell: any failed queued job pings admin + super_admin.
        // Escalates to 'critical' severity when the same job class has
        // failed ≥3 times in the past hour (rate counter is cached).
        JobFailed::class => [
            FailedJobListener::class,
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

        // Admin bell: ping moderators on every new TOP-LEVEL comment.
        // The listener itself skips replies (parent_id !== null) and
        // escalates severity to 'warning' when the AI spoiler detector
        // flagged the row. Queued — failure isolated from comment POST.
        \App\Models\Comment::created(function (\App\Models\Comment $comment) {
            try {
                \App\Listeners\Admin\NewCommentListener::dispatch($comment);
            } catch (\Throwable $e) {
                \Log::warning('NewCommentListener dispatch failed', [
                    'comment_id' => $comment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }
}
