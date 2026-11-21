<?php

namespace App\Services\Vpn;

use Illuminate\Http\Request;

/**
 * The visitor's address on the staff VPN page. When that page is reached through a reverse proxy
 * (VPN settings → proxy IPs, e.g. the Coolify server), the proxy's X-Forwarded-For is used, but only
 * for requests that really come from that proxy on the portal host name, so it can't be spoofed.
 */
final class ClientIp
{
    public static function of(Request $request): string
    {
        $remote = (string) $request->server('REMOTE_ADDR', $request->ip());
        $host = VpnSettings::portalHost();
        if (!$host || strcasecmp($request->getHost(), $host) !== 0 || !in_array($remote, VpnSettings::proxyIps(), true)) {
            return $remote;
        }
        // The proxy appends the address it saw: the right-most valid entry is the real visitor.
        $hops = array_reverse(array_map('trim', explode(',', (string) $request->header('X-Forwarded-For', ''))));
        foreach ($hops as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) && !in_array($ip, VpnSettings::proxyIps(), true)) {
                return $ip;
            }
        }
        return $remote;
    }
}
