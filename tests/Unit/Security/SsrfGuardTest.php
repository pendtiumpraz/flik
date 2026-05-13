<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Exceptions\SsrfException;
use App\Services\Security\SsrfGuard;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour fixture for {@see SsrfGuard}.
 *
 * NOTE: this suite is "fixture-style" — it documents the contract the guard
 * must satisfy. Some cases hit live DNS (github.com, example.com) and are
 * therefore network-dependent; mark this group with `@group ssrf-net` so CI
 * can skip it where outbound DNS is restricted (e.g. Phpunit's --exclude-group).
 *
 * @group ssrf-net
 */
final class SsrfGuardTest extends TestCase
{
    private SsrfGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new SsrfGuard();
    }

    /**
     * Localhost (loopback) must always be rejected.
     */
    public function test_blocks_localhost_literal(): void
    {
        $this->expectException(SsrfException::class);
        $this->expectExceptionMessageMatches('/blocked_private_ip|dns_resolution_failed/');
        $this->guard->assertUrlAllowed('http://127.0.0.1:8080/admin');
    }

    /**
     * The "localhost" hostname resolves to 127.0.0.1 → block.
     */
    public function test_blocks_localhost_hostname(): void
    {
        $this->expectException(SsrfException::class);
        $this->guard->assertUrlAllowed('http://localhost:9000/');
    }

    /**
     * AWS / GCP / Azure metadata endpoint — the canonical SSRF target.
     */
    public function test_blocks_aws_imds_metadata(): void
    {
        $this->expectException(SsrfException::class);
        $this->guard->assertUrlAllowed('http://169.254.169.254/latest/meta-data/');
    }

    /**
     * GCP metadata hostname is on the hard-block list even before DNS.
     */
    public function test_blocks_gcp_metadata_hostname(): void
    {
        $this->expectException(SsrfException::class);
        $this->expectExceptionMessageMatches('/blocked_hostname/');
        $this->guard->assertUrlAllowed('http://metadata.google.internal/computeMetadata/v1/');
    }

    /**
     * Kubernetes control-plane DNS — blocked even on the in-cluster service.
     */
    public function test_blocks_kubernetes_default(): void
    {
        $this->expectException(SsrfException::class);
        $this->guard->assertUrlAllowed('https://kubernetes.default.svc/api');
    }

    /**
     * RFC 1918 (private LAN).
     */
    public function test_blocks_rfc1918_private_ranges(): void
    {
        $this->expectException(SsrfException::class);
        $this->guard->assertUrlAllowed('http://10.0.0.5/');
    }

    public function test_blocks_192_168_range(): void
    {
        $this->expectException(SsrfException::class);
        $this->guard->assertUrlAllowed('http://192.168.1.10/admin');
    }

    public function test_blocks_172_16_range(): void
    {
        $this->expectException(SsrfException::class);
        $this->guard->assertUrlAllowed('http://172.16.5.5/');
    }

    /**
     * IPv6 loopback (::1) must be rejected.
     */
    public function test_blocks_ipv6_loopback(): void
    {
        $this->expectException(SsrfException::class);
        $this->guard->assertUrlAllowed('http://[::1]:8080/');
    }

    /**
     * IPv6 link-local (fe80::/10) — blocked.
     */
    public function test_blocks_ipv6_link_local(): void
    {
        $this->expectException(SsrfException::class);
        $this->guard->assertUrlAllowed('http://[fe80::1]/');
    }

    /**
     * IPv6 unique-local (fc00::/7) — blocked.
     */
    public function test_blocks_ipv6_unique_local(): void
    {
        $this->expectException(SsrfException::class);
        $this->guard->assertUrlAllowed('http://[fc00::1]/');
    }

    /**
     * IPv4-mapped IPv6 sneaking past validation (::ffff:127.0.0.1) — blocked.
     */
    public function test_blocks_ipv4_mapped_ipv6_loopback(): void
    {
        $this->expectException(SsrfException::class);
        $this->guard->assertUrlAllowed('http://[::ffff:127.0.0.1]/');
    }

    /**
     * Public host (github.com) is allowed. Hits live DNS — skipped if offline.
     */
    public function test_allows_github(): void
    {
        if (!@gethostbyname('github.com') || gethostbyname('github.com') === 'github.com') {
            $this->markTestSkipped('No DNS available; skipping public-host check.');
        }

        $this->expectNotToPerformAssertions();
        $this->guard->assertUrlAllowed('https://github.com/anthropic/claude');
    }

    /**
     * gopher:// must be rejected on the scheme alone — never reaches DNS.
     */
    public function test_blocks_gopher_scheme(): void
    {
        $this->expectException(SsrfException::class);
        $this->expectExceptionMessageMatches('/blocked_scheme:gopher/');
        $this->guard->assertUrlAllowed('gopher://example.com/x');
    }

    /**
     * file:// — local file disclosure attempt.
     */
    public function test_blocks_file_scheme(): void
    {
        $this->expectException(SsrfException::class);
        $this->expectExceptionMessageMatches('/blocked_scheme:file/');
        $this->guard->assertUrlAllowed('file:///etc/passwd');
    }

    /**
     * ftp:// — also rejected by scheme allow list.
     */
    public function test_blocks_ftp_scheme(): void
    {
        $this->expectException(SsrfException::class);
        $this->expectExceptionMessageMatches('/blocked_scheme:ftp/');
        $this->guard->assertUrlAllowed('ftp://anonymous@example.com/');
    }

    /**
     * Empty / malformed input — rejected with a "malformed_url" code.
     */
    public function test_rejects_empty_url(): void
    {
        $this->expectException(SsrfException::class);
        $this->expectExceptionMessageMatches('/malformed_url/');
        $this->guard->assertUrlAllowed('');
    }

    public function test_rejects_garbage_url(): void
    {
        $this->expectException(SsrfException::class);
        $this->guard->assertUrlAllowed('not-a-url');
    }

    /**
     * 0.0.0.0 — "this network" wildcard, often used to bypass ACLs.
     */
    public function test_blocks_zero_address(): void
    {
        $this->expectException(SsrfException::class);
        $this->guard->assertUrlAllowed('http://0.0.0.0:8080/');
    }

    /**
     * Per-call extra allowlist lets a service bypass the IP check for a
     * known internal hostname (e.g. an in-cluster model gateway).
     */
    public function test_extra_allowlist_bypasses_ip_check(): void
    {
        $this->expectNotToPerformAssertions();
        // 127.0.0.1 hostname literally bypassed via per-call allowlist.
        $this->guard->assertUrlAllowed('http://127.0.0.1:9000/', ['127.0.0.1']);
    }

    /**
     * Hard-blocked metadata hosts ignore the allowlist (defence-in-depth).
     */
    public function test_metadata_blocked_even_when_allowlisted(): void
    {
        $this->expectException(SsrfException::class);
        $this->guard->assertUrlAllowed(
            'http://169.254.169.254/latest/',
            ['169.254.169.254'],
        );
    }
}
