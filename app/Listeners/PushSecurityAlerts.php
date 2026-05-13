<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\SecurityEventLogged;
use App\Services\Security\SecurityAlertService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * PushSecurityAlerts
 * --------------------------------------------------------------------------
 * Subscribes to {@see SecurityEventLogged} and forwards the payload to
 * {@see SecurityAlertService::notify()} for Slack/Discord fan-out.
 *
 * The listener is intentionally synchronous (no ShouldQueue): webhook
 * delivery uses a 4-second timeout and we want operators to know about
 * critical events even if the queue worker is dead. The service handles
 * its own throttling, severity gating, and SSRF safety, so this class
 * is a thin failure-isolation wrapper.
 */
class PushSecurityAlerts
{
    public function __construct(
        private readonly SecurityAlertService $alerts,
    ) {
    }

    public function handle(SecurityEventLogged $event): void
    {
        try {
            // Merge the persisted audit log id into context so the alert
            // can deep-link to /admin/audit-logs without the publisher
            // having to remember to thread it through.
            $context = $event->context;
            $context['audit_log_id'] = $context['audit_log_id'] ?? $event->auditLog->id;

            $this->alerts->notify($event->event, $context);
        } catch (Throwable $e) {
            // Alerting must never break the request that produced the
            // security event. Log and swallow.
            Log::warning('PushSecurityAlerts: notify failed', [
                'event' => $event->event,
                'audit_log_id' => $event->auditLog->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
