<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The in-app updater runs on the command line, which can't clear PHP-FPM's opcode cache. With
 * opcache.validate_timestamps off, web workers would keep running the old code (stale routes →
 * "Route not defined" 500s) until PHP-FPM is restarted. When the VERSION file changes, the first
 * web request clears the cache once, so every following request runs the new release.
 */
class ResetOpcacheAfterUpdate
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $version = @filemtime(base_path('VERSION')) ?: 0;
            $marker = storage_path('framework/opcache-version');
            if ($version && function_exists('opcache_reset') && (int) @file_get_contents($marker) !== $version) {
                if (@file_put_contents($marker, (string) $version, LOCK_EX) !== false) {
                    opcache_reset();
                }
            }
        } catch (\Throwable) {
            // never block a request over this
        }
        return $next($request);
    }
}
