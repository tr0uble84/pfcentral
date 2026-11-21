<?php

namespace App\Http\Middleware;

use App\Services\Security\FirewallGui;
use App\Services\Security\SecuritySettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The firewall web interface host name (System → Security, e.g. fwgui.cfbtel.com) serves only proxied firewall
 * web interfaces: no PFCentral page, login or API answers there, and no PFCentral session cookie is used.
 */
class FirewallGuiHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = SecuritySettings::guiHost();
        if (!$host || strcasecmp($request->getHost(), $host) !== 0) {
            return $next($request);
        }
        if (!SecuritySettings::guiProxyEnabled()) {
            abort(404);
        }
        return FirewallGui::handle($request);
    }
}
