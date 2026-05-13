<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\AuditLog;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * SecurityEventLogged
 * --------------------------------------------------------------------------
 * Fired by {@see \App\Services\Audit\AuditLogger::security()} immediately
 * after a security-flavoured row has been persisted to `audit_logs`.
 *
 * Subscribers (notably {@see \App\Listeners\PushSecurityAlerts}) use the
 * event name + context to decide whether to fan out to Slack / Discord /
 * pager. The persisted {@see AuditLog} is included so listeners can deep-
 * link to /admin/audit-logs/{id} without re-querying.
 *
 * The event is intentionally simple (no broadcast, no queue) because:
 *  - We want the alert hop to run in-process so Slack/Discord delivery is
 *    visible in the same request lifecycle as the offending action.
 *  - Throttling/dedup happens inside SecurityAlertService, not here.
 */
class SecurityEventLogged
{
    use Dispatchable, SerializesModels;

    /**
     * @param  string                $event     SecurityEvents::* constant value (e.g. "auth.login.failed").
     * @param  array<string,mixed>   $context   Free-form structured context (user_id, ip, meta, etc.).
     * @param  \App\Models\AuditLog  $auditLog  The just-persisted audit row.
     */
    public function __construct(
        public readonly string $event,
        public readonly array $context,
        public readonly AuditLog $auditLog,
    ) {
    }
}
