<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * A few seconds of memory for firewall configuration reads, so moving between
 * pages (and pages the browser loads ahead on hover) doesn't wait on the firewall
 * again for the same data.
 *
 * - Only configuration reads are kept. Anything live (status, logs, diagnostics,
 *   leases, states, traffic, commands) always goes to the firewall.
 * - Any change sent to a firewall through PFCentral forgets everything kept for it.
 * - Kept values are encrypted, because configuration can hold keys and secrets.
 * - Set API_CACHE_SECONDS=0 to turn it off.
 */
class FirewallApiCache
{
    /** Endpoints that always go to the firewall. */
    private const LIVE = '~(status|diagnostic|log|command|ping|traceroute|dirty|graph|traffic|state|arp|ndp|lease|version|update|upgrade|uptime|health|monitor|online|connected|session|reconfigure|apply|restart|reboot|halt|backup|download|capture|top|pf_?info|dns_?lookup|test)~i';

    public static function seconds(): int
    {
        return max(0, (int) config('pfcentral.api_cache_seconds', 15));
    }

    public static function ttlFor(string $endpoint): int
    {
        $ttl = self::seconds();
        if ($ttl === 0 || preg_match(self::LIVE, $endpoint)) {
            return 0;
        }
        return $ttl;
    }

    public static function key(int $firewallId, string $method, string $endpoint, array $params): string
    {
        ksort($params);
        return 'fwapi:' . $firewallId . ':' . self::generation($firewallId) . ':' . sha1($method . ' ' . $endpoint . '?' . http_build_query($params));
    }

    /** @return array{0: bool, 1: mixed} */
    public static function get(string $key): array
    {
        $raw = Cache::get($key);
        if (!is_string($raw)) {
            return [false, null];
        }
        try {
            return [true, unserialize(Crypt::decryptString($raw), ['allowed_classes' => false])];
        } catch (\Throwable) {
            Cache::forget($key);
            return [false, null];
        }
    }

    public static function put(string $key, mixed $value, int $ttl): void
    {
        try {
            Cache::put($key, Crypt::encryptString(serialize($value)), $ttl);
        } catch (\Throwable) {
            // Kept only to save time; never worth failing a page over.
        }
    }

    /** Forget everything kept for a firewall (after any change to it). */
    public static function forget(int $firewallId): void
    {
        try {
            Cache::put('fwapi-gen:' . $firewallId, self::generation($firewallId) + 1, now()->addDay());
        } catch (\Throwable) {
        }
    }

    private static function generation(int $firewallId): int
    {
        try {
            return (int) Cache::get('fwapi-gen:' . $firewallId, 0);
        } catch (\Throwable) {
            return 0;
        }
    }
}
