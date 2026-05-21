<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Services\Security\SecurityAlertService;
use App\Support\SecurityEvents;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for {@see SecurityAlertService::severity()}.
 *
 * Historically this method shipped a parallel hard-coded lookup whose
 * string keys did not match SecurityEvents::* — every event therefore
 * collapsed to "low" and the configured min_severity='high' floor
 * silently filtered out every alert (including criticals like
 * ACCOUNT_DELETED). This test pins severity() to SecurityEvents so a
 * future refactor cannot reintroduce the desync.
 */
final class SecurityAlertServiceTest extends TestCase
{
    public function test_severity_delegates_to_security_events_taxonomy(): void
    {
        $service = new SecurityAlertService();

        // Critical bucket — must propagate the canonical "critical" value
        // so the SECURITY_ALERTS_MIN_SEVERITY='high' floor cannot drop it.
        $this->assertSame('critical', $service->severity(SecurityEvents::PRIVILEGE_ESCALATION_ATTEMPT));
        $this->assertSame('critical', $service->severity(SecurityEvents::ACCOUNT_DELETED));
        $this->assertSame('critical', $service->severity(SecurityEvents::ADMIN_USER_DELETED));

        // High bucket — clears the default 'high' alert floor.
        $this->assertSame('high', $service->severity(SecurityEvents::DRM_KEY_DENIED));
        $this->assertSame('high', $service->severity(SecurityEvents::HONEYPOT_HIT));
        $this->assertSame('high', $service->severity(SecurityEvents::WAF_IP_BANNED));
        $this->assertSame('high', $service->severity(SecurityEvents::LOGIN_LOCKED_OUT));

        // Medium bucket — visible in digest, gated out by 'high' floor.
        $this->assertSame('medium', $service->severity(SecurityEvents::WAF_BLOCKED));
        $this->assertSame('medium', $service->severity(SecurityEvents::NEW_COUNTRY_LOGIN));

        // Low / unknown — explicit fallback, never raises a page.
        $this->assertSame('low', $service->severity(SecurityEvents::LOGIN_SUCCESS));
        $this->assertSame('low', $service->severity('totally.unknown.event'));
    }
}
