<?php

namespace App\Services\Vpn;

use App\Models\Vpn\VpnDevice;
use App\Models\Vpn\VpnSite;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The live VPN page: every device, and what each firewall's WireGuard says about it right now.
 *
 * Each firewall is asked separately (the page polls them in parallel), its answer is cached for a few seconds
 * so several admins watching don't multiply the load, and a firewall that doesn't answer is left alone for a
 * minute. Nothing but public keys is read from the firewall, and no key is sent to the browser.
 */
class VpnLive
{
    /** A device counts as connected when its latest handshake is this recent (WireGuard re-handshakes every 2 minutes). */
    public const CONNECTED_SECONDS = 180;
    public const CACHE_SECONDS = 4;
    public const DOWN_SECONDS = 60;

    /** Every device with VPN access (not removed), for the table. */
    public static function devices(): array
    {
        return VpnDevice::with(['user', 'site.firewall', 'site.company'])
            ->whereNull('revoked_at')->whereNotNull('vpn_user_id')
            ->get()
            ->filter(fn ($d) => $d->user && $d->site)
            ->map(fn (VpnDevice $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'address' => $d->address,
                'added' => $d->created_at?->toIso8601String(),
                'site' => $d->vpn_site_id,
                'person' => ['id' => $d->user->id, 'name' => $d->user->name, 'email' => $d->user->upn, 'blocked' => $d->user->isBlocked()],
                'company' => ['id' => $d->site->company_id, 'name' => $d->site->company?->name ?? '—'],
                'firewall' => $d->site->firewall?->name ?? '—',
            ])
            ->values()->all();
    }

    /** The VPN firewalls, for the firewall strip and to know what to poll. */
    public static function sites(): array
    {
        return VpnSite::with(['firewall', 'company'])->get()->map(fn (VpnSite $s) => [
            'id' => $s->id,
            'firewall' => $s->firewall?->name ?? '—',
            'company' => ['id' => $s->company_id, 'name' => $s->company?->name ?? '—'],
            'ready' => $s->isReady(),
            'endpoint' => $s->endpoint,
            'subnet' => $s->subnet,
        ])->sortBy(fn ($s) => strtolower($s['company']['name'] . ' ' . $s['firewall']))->values()->all();
    }

    /**
     * Live state of one firewall's devices, keyed by device id.
     *
     * @return array{ok: bool, error: ?string, t: float, peers: array<int, array{hs: int, ep: ?string, rx: int, tx: int}>}
     */
    public function site(VpnSite $site): array
    {
        if (!$site->isReady()) {
            return ['ok' => false, 'error' => 'VPN not set up on this firewall yet', 't' => microtime(true), 'peers' => []];
        }
        $downKey = "vpn_live_down:{$site->id}";
        if ($down = Cache::get($downKey)) {
            return ['ok' => false, 'error' => $down, 't' => microtime(true), 'peers' => []];
        }
        return Cache::remember("vpn_live:{$site->id}", self::CACHE_SECONDS, function () use ($site, $downKey) {
            try {
                $rows = VpnManager::driverFor($site->firewall)->status($site->tunnel);
            } catch (\Throwable $e) {
                Log::notice('VPN live: firewall status failed', ['site' => $site->id, 'error' => $e->getMessage()]);
                $msg = 'The firewall didn\'t answer';
                Cache::put($downKey, $msg, self::DOWN_SECONDS);
                return ['ok' => false, 'error' => $msg, 't' => microtime(true), 'peers' => []];
            }
            $byKey = [];
            foreach ($rows as $r) {
                $byKey[$r['public_key']] = $r;
            }
            $peers = [];
            foreach (VpnDevice::where('vpn_site_id', $site->id)->whereNull('revoked_at')->get(['id', 'public_key']) as $d) {
                if ($r = $byKey[$d->public_key] ?? null) {
                    $peers[$d->id] = ['hs' => $r['handshake'], 'ep' => self::endpointIp($r['endpoint']), 'rx' => $r['rx'], 'tx' => $r['tx']];
                }
            }
            return ['ok' => true, 'error' => null, 't' => microtime(true), 'peers' => $peers];
        });
    }

    /** "203.0.113.7:51820" or "[2001:db8::1]:51820" → the address the person connects from. */
    public static function endpointIp(?string $endpoint): ?string
    {
        if (!$endpoint) {
            return null;
        }
        if (preg_match('/^\[([0-9a-fA-F:.]+)\]:\d+$/', $endpoint, $m)) {
            return $m[1];
        }
        if (preg_match('/^([0-9.]+):\d+$/', $endpoint, $m)) {
            return $m[1];
        }
        return filter_var($endpoint, FILTER_VALIDATE_IP) ? $endpoint : null;
    }

    /** Forget a firewall's cached answer (after a disconnect, so the page shows it at once). */
    public static function forget(int $siteId): void
    {
        Cache::forget("vpn_live:{$siteId}");
    }
}
