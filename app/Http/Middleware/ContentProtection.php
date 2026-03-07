<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ContentProtection
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // Add security headers
        if (method_exists($response, 'header')) {
            $response->header('X-Content-Type-Options', 'nosniff');
            $response->header('X-Frame-Options', 'SAMEORIGIN');
            $response->header('Referrer-Policy', 'strict-origin-when-cross-origin');
        }

        return $response;
    }
}
