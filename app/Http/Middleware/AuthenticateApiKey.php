<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Security\ApiKeyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Service-to-service API key auth.
 *
 * Accepted credential locations (first non-empty wins):
 *   1. Authorization: Bearer flk_xxx...
 *   2. X-Api-Key: flk_xxx...
 *
 * On success, the verified {@see \App\Models\ApiKey} is exposed to downstream
 * handlers via:
 *   - $request->attributes->get('api_key')
 *   - $request->attributes->get('api_key_abilities')  (array<int,string>)
 *
 * On failure, a 401 JSON response is returned. We deliberately do NOT
 * differentiate "revoked" / "expired" / "unknown" — any of those collapse to
 * "Invalid API key" so the response can't be used as an enumeration oracle.
 *
 * Register in app/Http/Kernel.php under $routeMiddleware as 'auth.apikey' to
 * gate /api/* routes that should be machine-callable.
 */
class AuthenticateApiKey
{
    public function __construct(private readonly ApiKeyService $service)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $plaintext = $this->extractKey($request);

        if ($plaintext === null) {
            return $this->unauthorized('API key required.');
        }

        $key = $this->service->verify($plaintext);

        if ($key === null) {
            return $this->unauthorized('Invalid API key.');
        }

        $request->attributes->set('api_key', $key);
        $request->attributes->set('api_key_abilities', $key->abilities ?? []);

        return $next($request);
    }

    /**
     * Pull the plaintext token from either supported header.
     *
     * The Authorization header is parsed as "Bearer <token>" but we accept
     * any leading whitespace variations PHP-FPM might let through.
     */
    private function extractKey(Request $request): ?string
    {
        $header = (string) $request->header('Authorization', '');
        if ($header !== '' && preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $m) === 1) {
            return $m[1];
        }

        $headerKey = trim((string) $request->header('X-Api-Key', ''));

        return $headerKey !== '' ? $headerKey : null;
    }

    private function unauthorized(string $message): Response
    {
        return response()->json([
            'error'   => 'unauthorized',
            'message' => $message,
        ], Response::HTTP_UNAUTHORIZED, [
            // RFC 6750 — tell well-behaved clients which scheme to use.
            'WWW-Authenticate' => 'Bearer realm="api"',
        ]);
    }
}
