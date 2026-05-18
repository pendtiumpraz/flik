<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Exceptions\SsrfException;

/**
 * SSRF (Server-Side Request Forgery) hostname / IP guard.
 *
 * Validates an outbound URL before any HTTP client touches it. The check is
 * intentionally conservative — we'd rather reject a legitimate-looking
 * request than accidentally let a user-controlled string pivot us into the
 * private network, the cloud-provider metadata service, or the loopback
 * interface.
 *
 * Resolution & DNS-rebinding strategy:
 *  - assertUrlAllowed() resolves the hostname and validates EVERY returned A
 *    / AAAA record against the deny list. If any IP is blocked the URL is
 *    rejected outright (defence-in-depth — even if the attacker controls the
 *    DNS round-robin order).
 *  - resolveSafely() returns the validated IPs so {@see SafeHttp} can pin
 *    cURL to a specific IP via CURLOPT_RESOLVE, closing the TOCTOU rebinding
 *    gap between this check and the request being sent.
 *
 * Allowlist:
 *  - SSRF_ALLOWED_HOSTS env (comma-separated, case-insensitive). Hostnames
 *    on that list bypass the IP check entirely. This exists for legitimate
 *    internal-cluster integrations (e.g. a private model gateway) — leave
 *    it unset in production unless you really need it.
 *
 * Static deny list (cloud metadata / cluster control plane):
 *  - 169.254.169.254 (AWS, Alibaba, OpenStack, GCP IMDSv1/v2, Azure)
 *  - metadata.google.internal, metadata.aws, metadata.azure
 *  - kubernetes.default.svc, kubernetes.default.svc.cluster.local
 *  These are blocked even if SSRF_ALLOWED_HOSTS is set — they're never legit.
 */
class SsrfGuard
{
    /**
     * Internal IP-family discriminators. Decoupled from PHP's self::FAMILY_INET / self::FAMILY_INET6
     * (which require the sockets extension — not always loaded on Windows).
     * The numeric value doesn't matter, only that ::PRIVATE_RANGES and the
     * runtime ip-classification helpers agree.
     */
    private const FAMILY_INET = 4;
    private const FAMILY_INET6 = 6;

    /**
     * Cloud / cluster metadata hostnames that should NEVER be reachable from
     * user-influenced URLs. Compared case-insensitively against the URL host.
     *
     * @var list<string>
     */
    private const HARD_BLOCKED_HOSTS = [
        '169.254.169.254',
        '[fd00:ec2::254]',
        'metadata.google.internal',
        'metadata.goog',
        'metadata.aws',
        'metadata.azure',
        'metadata.azure.com',
        'metadata.packet.net',
        'metadata.platformequinix.com',
        'metadata.tencentyun.com',
        'kubernetes.default',
        'kubernetes.default.svc',
        'kubernetes.default.svc.cluster.local',
        'localhost.localdomain',
    ];

    /**
     * IPv4 / IPv6 CIDR blocks that must never be the destination of an
     * outbound request. Encodes RFC 1918, link-local, loopback, multicast,
     * documentation, IPv4-mapped IPv6, etc.
     *
     * @var list<array{network:string, bits:int, family:int}>
     */
    private const PRIVATE_RANGES = [
        // IPv4 — RFC 1918 + reserved
        ['network' => '0.0.0.0',         'bits' => 8,  'family' => self::FAMILY_INET],   // "this network"
        ['network' => '10.0.0.0',        'bits' => 8,  'family' => self::FAMILY_INET],   // RFC 1918
        ['network' => '100.64.0.0',      'bits' => 10, 'family' => self::FAMILY_INET],   // CGNAT
        ['network' => '127.0.0.0',       'bits' => 8,  'family' => self::FAMILY_INET],   // loopback
        ['network' => '169.254.0.0',     'bits' => 16, 'family' => self::FAMILY_INET],   // link-local + AWS metadata
        ['network' => '172.16.0.0',      'bits' => 12, 'family' => self::FAMILY_INET],   // RFC 1918
        ['network' => '192.0.0.0',       'bits' => 24, 'family' => self::FAMILY_INET],   // IETF
        ['network' => '192.0.2.0',       'bits' => 24, 'family' => self::FAMILY_INET],   // TEST-NET-1
        ['network' => '192.168.0.0',     'bits' => 16, 'family' => self::FAMILY_INET],   // RFC 1918
        ['network' => '198.18.0.0',      'bits' => 15, 'family' => self::FAMILY_INET],   // benchmark
        ['network' => '198.51.100.0',    'bits' => 24, 'family' => self::FAMILY_INET],   // TEST-NET-2
        ['network' => '203.0.113.0',     'bits' => 24, 'family' => self::FAMILY_INET],   // TEST-NET-3
        ['network' => '224.0.0.0',       'bits' => 4,  'family' => self::FAMILY_INET],   // multicast
        ['network' => '240.0.0.0',       'bits' => 4,  'family' => self::FAMILY_INET],   // reserved
        ['network' => '255.255.255.255', 'bits' => 32, 'family' => self::FAMILY_INET],   // broadcast

        // IPv6
        ['network' => '::',              'bits' => 128, 'family' => self::FAMILY_INET6], // unspecified
        ['network' => '::1',             'bits' => 128, 'family' => self::FAMILY_INET6], // loopback
        ['network' => '::ffff:0:0',      'bits' => 96,  'family' => self::FAMILY_INET6], // IPv4-mapped (handled extra below)
        ['network' => '64:ff9b::',       'bits' => 96,  'family' => self::FAMILY_INET6], // NAT64
        ['network' => '100::',           'bits' => 64,  'family' => self::FAMILY_INET6], // discard
        ['network' => '2001:db8::',      'bits' => 32,  'family' => self::FAMILY_INET6], // documentation
        ['network' => 'fc00::',          'bits' => 7,   'family' => self::FAMILY_INET6], // ULA
        ['network' => 'fe80::',          'bits' => 10,  'family' => self::FAMILY_INET6], // link-local
        ['network' => 'ff00::',          'bits' => 8,   'family' => self::FAMILY_INET6], // multicast
    ];

    /**
     * Throw {@see SsrfException} if the URL is unsafe. Returns void on success.
     *
     * Pass per-call host overrides via $extraAllowedHosts (e.g. when a job
     * legitimately needs to talk to a CIDR-internal host configured in
     * Helm/operator values). The global SSRF_ALLOWED_HOSTS env list is also
     * honoured.
     *
     * @param  list<string>  $extraAllowedHosts  Additional hostnames to allow for this call.
     */
    public function assertUrlAllowed(string $url, array $extraAllowedHosts = []): void
    {
        $url = trim($url);
        if ($url === '') {
            throw new SsrfException('malformed_url:empty');
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new SsrfException('malformed_url:' . substr($url, 0, 80));
        }

        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new SsrfException('blocked_scheme:' . $scheme);
        }

        // Strip IPv6 brackets for comparison.
        $host = strtolower((string) $parts['host']);
        $hostForCompare = trim($host, '[]');

        // Hard-block cloud metadata + k8s control-plane regardless of allowlist.
        foreach (self::HARD_BLOCKED_HOSTS as $blocked) {
            if ($hostForCompare === strtolower($blocked)) {
                throw new SsrfException('blocked_hostname:' . $hostForCompare);
            }
        }

        if ($this->hostIsAllowlisted($hostForCompare, $extraAllowedHosts)) {
            return;
        }

        // Resolve every IP for the hostname and validate each one.
        $ips = $this->resolveAll($hostForCompare);
        if ($ips === []) {
            throw new SsrfException('dns_resolution_failed:' . $hostForCompare);
        }

        foreach ($ips as $ip) {
            if ($this->ipIsBlocked($ip)) {
                throw new SsrfException('blocked_private_ip:' . $ip . ' (host=' . $hostForCompare . ')');
            }
        }
    }

    /**
     * Validate the URL and return the resolved IPs so the caller can pin the
     * cURL request to a specific address (CURLOPT_RESOLVE) — preventing DNS
     * rebinding between this check and the actual fetch.
     *
     * @return array{host:string, port:int, ips:list<string>}
     */
    public function resolveSafely(string $url, array $extraAllowedHosts = []): array
    {
        $this->assertUrlAllowed($url, $extraAllowedHosts);

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        // Allowlisted hosts skip resolution; let cURL handle DNS itself.
        $ips = $this->hostIsAllowlisted($host, $extraAllowedHosts)
            ? []
            : $this->resolveAll($host);

        return [
            'host' => $host,
            'port' => $port,
            'ips'  => $ips,
        ];
    }

    /**
     * Read SSRF_ALLOWED_HOSTS from env and merge with any per-call list.
     * Hostname comparison is case-insensitive and exact (no wildcards — keep
     * the surface area small).
     *
     * @param  list<string>  $extraAllowedHosts
     */
    private function hostIsAllowlisted(string $host, array $extraAllowedHosts): bool
    {
        $envList = (string) (getenv('SSRF_ALLOWED_HOSTS') ?: ($_ENV['SSRF_ALLOWED_HOSTS'] ?? ''));
        $allowed = array_filter(array_map(
            static fn ($h) => strtolower(trim((string) $h)),
            array_merge(
                $envList === '' ? [] : explode(',', $envList),
                $extraAllowedHosts,
            )
        ));

        return in_array($host, $allowed, true);
    }

    /**
     * Resolve a hostname to all of its A and AAAA records. If the input is
     * already an IP literal, returns [$host] directly.
     *
     * @return list<string>
     */
    private function resolveAll(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = [];

        // dns_get_record returns false on failure — treat as "no records".
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (!empty($record['ip'])) {
                    $ips[] = $record['ip'];
                }
                if (!empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        // Fallback: gethostbynamel covers IPv4 on hosts where DNS_A lookup
        // fails (Windows + some libc setups). Don't bother on Windows-only
        // platforms where dns_get_record is not always reliable.
        if ($ips === []) {
            $v4 = @gethostbynamel($host);
            if (is_array($v4)) {
                $ips = $v4;
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * True if the IP falls in any reserved/private CIDR.
     */
    private function ipIsBlocked(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            // If we can't even parse it, deny by default.
            return true;
        }

        // Reject IPv4 expressed in IPv6-mapped form (::ffff:10.0.0.1).
        // PHP doesn't unwrap these for FILTER_FLAG_NO_PRIV_RANGE checks.
        if (str_starts_with($ip, '::ffff:') || str_starts_with($ip, '::FFFF:')) {
            $tail = substr($ip, 7);
            if (filter_var($tail, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $this->ipIsBlocked($tail);
            }
        }

        $family = str_contains($ip, ':') ? self::FAMILY_INET6 : self::FAMILY_INET;
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return true;
        }

        // Built-in PHP filter handles the well-known v4 ranges; useful as
        // a belt-and-braces check before our custom CIDR sweep.
        if ($family === self::FAMILY_INET) {
            $longCheck = filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
            if ($longCheck === false) {
                return true;
            }
        }

        foreach (self::PRIVATE_RANGES as $range) {
            if ($range['family'] !== $family) {
                continue;
            }
            if ($this->ipInCidr($packed, $range['network'], $range['bits'], $family)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Bit-wise CIDR containment check on the packed (binary) form of the IP.
     */
    private function ipInCidr(string $packedIp, string $network, int $bits, int $family): bool
    {
        $packedNet = @inet_pton($network);
        if ($packedNet === false) {
            return false;
        }

        $byteLen = $family === self::FAMILY_INET ? 4 : 16;
        if (strlen($packedIp) !== $byteLen || strlen($packedNet) !== $byteLen) {
            return false;
        }

        $fullBytes = intdiv($bits, 8);
        $remBits = $bits % 8;

        if ($fullBytes > 0 && substr($packedIp, 0, $fullBytes) !== substr($packedNet, 0, $fullBytes)) {
            return false;
        }

        if ($remBits === 0) {
            return true;
        }

        $mask = chr((0xff << (8 - $remBits)) & 0xff);
        return (($packedIp[$fullBytes] & $mask) === ($packedNet[$fullBytes] & $mask));
    }
}
