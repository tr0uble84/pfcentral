<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class IntegrationApiBoundary
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/v1', 'api/v1/*')) {
            return $next($request);
        }

        $request->headers->set('Accept', 'application/json');
        if (config('integration-api.require_https') && ! $request->isSecure()) {
            return response()->json(['message' => 'HTTPS is required.'], 403,
                ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
        }

        // Global middleware executes before Laravel's authentication priority ordering.
        // A route throttle placed before auth can otherwise be reordered after auth.
        $key = 'integration-api-ip:'.hash('sha256', (string) $request->ip());
        $maximum = config('integration-api.unauthenticated_requests_per_minute');
        if (RateLimiter::tooManyAttempts($key, $maximum)) {
            return response()->json(['message' => 'Too many requests.'], 429, [
                'Retry-After' => RateLimiter::availableIn($key),
                'Cache-Control' => 'no-store, private',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }
        RateLimiter::hit($key, 60);

        // Error responses are rendered by the exception handler, outside this middleware.
        $response = $next($request);
        if ($response->isRedirection()) {
            $response = response()->json(['message' => 'API unavailable until application setup is complete.'], 503);
        }
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
