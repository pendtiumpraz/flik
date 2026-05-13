<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

/**
 * @psalm-suppress UndefinedClass
 */
class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * '*' trusts X-Forwarded-* from any upstream — required when the app
     * sits behind one or more load balancers / reverse proxies / CDNs (we
     * run behind Bunny edge + an AWS ALB / Cloudflare-style fronting). If
     * you're terminating TLS on a single fixed appliance, narrow this to
     * the appliance's CIDR for defence-in-depth.
     *
     * @var array<int, string>|string|null
     */
    protected $proxies = '*';

    /**
     * The headers that should be used to detect proxies.
     *
     * Includes HEADER_X_FORWARDED_PROTO so $request->isSecure() returns
     * true behind an HTTPS-terminating proxy — without it, the SecurityHeaders
     * middleware would skip HSTS and the ForceHttps redirect would loop.
     *
     * @var int
     */
    protected $headers =
    Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;
}
