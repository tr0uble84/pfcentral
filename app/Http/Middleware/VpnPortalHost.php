<?php

namespace App\Http\Middleware;

use App\Services\Vpn\VpnSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * The staff VPN page has its own public host name (VPN settings → portal host, e.g. vpn.example.com).
 * On that host only the /vpn pages and their static files are served: the PFCentral console,
 * its login and its API answer 404 there. Links and the session cookie are bound to that host.
 */
class VpnPortalHost
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $host = VpnSettings::portalHost();
        } catch (\Throwable) {
            $host = null; // database not ready (fresh install / migrations pending)
        }
        if (!$host || strcasecmp($request->getHost(), $host) !== 0) {
            // Undo a portal-host root left over in a long-running worker.
            URL::forceRootUrl(config('app.url'));
            return $next($request);
        }

        config(['session.domain' => null]);
        URL::forceRootUrl('https://' . $host);
        URL::forceScheme('https');

        $path = trim($request->path(), '/');
        if ($path === '') {
            return redirect('/vpn');
        }
        $allowed = $path === 'vpn' || str_starts_with($path, 'vpn/') || str_starts_with($path, 'vpn-assets/') || $path === 'favicon.ico' || $path === 'up';
        abort_unless($allowed, 404);

        $response = $next($request);
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'no-store, private');
        return $response;
    }
}
