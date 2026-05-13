<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an outbound HTTP URL is rejected by {@see \App\Services\Security\SsrfGuard}.
 *
 * The message is intentionally specific (scheme, hostname, resolved IP, or
 * blocked-host token) so /admin/audit-logs and the application log channel
 * can attribute SSRF attempts to the originating request without leaking
 * internal IP topology to end users — never echo this message back into a
 * response body verbatim. Catch and translate at the controller boundary.
 *
 * Categories of rejections currently raised:
 *  - "blocked_scheme:..."            — non http(s) scheme (file, gopher, ...)
 *  - "blocked_hostname:..."          — hostname appears on the static
 *                                       cloud-metadata block list.
 *  - "dns_resolution_failed:..."     — DNS lookup returned no A/AAAA records.
 *  - "blocked_private_ip:<ip>"       — a resolved IP is in a reserved range.
 *  - "malformed_url:..."             — parse_url() failed entirely.
 */
class SsrfException extends RuntimeException
{
}
