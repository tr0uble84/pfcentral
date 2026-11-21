<?php

namespace App\Services\Vpn;

use App\Models\Company;
use App\Models\Firewall;
use App\Models\Vpn\VpnAuditLog;
use App\Models\Vpn\VpnCompany;
use App\Models\Vpn\VpnDevice;
use App\Models\Vpn\VpnSite;
use App\Models\Vpn\VpnUser;
use App\Services\OpnSenseApiService;
use App\Services\PfSenseApiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/** Devices, removals and the periodic access check. */
class VpnManager
{
    /** @var (\Closure(Firewall): WireGuardDriver)|null */
    private static ?\Closure $driverResolver = null;

    /** Tests swap in fake firewalls. */
    public static function resolveDriversWith(?\Closure $resolver): void
    {
        self::$driverResolver = $resolver;
    }

    public static function driverFor(Firewall $firewall): WireGuardDriver
    {
        if (self::$driverResolver) {
            return (self::$driverResolver)($firewall);
        }
        return $firewall->isOpnSense()
            ? new OpnSenseWireGuardDriver(new OpnSenseApiService($firewall))
            : new PfSenseWireGuardDriver((new PfSenseApiService($firewall))->setApiTimeout(60));
    }

    public function driver(VpnSite $site): WireGuardDriver
    {
        return self::driverFor($site->firewall);
    }

    /** One change at a time per firewall, so two people are never given the same address. */
    private function locked(VpnSite $site, \Closure $fn): mixed
    {
        return Cache::lock("vpn_site:{$site->id}", 120)->block(60, $fn);
    }

    /** @return \Illuminate\Support\Collection<int, VpnSite> */
    public function readySites(int $companyId)
    {
        return VpnSite::with('firewall')->where('company_id', $companyId)->where('status', 'ready')->get()->filter->isReady()->values();
    }

    /**
     * Create a key pair and a peer; return the client configuration (shown once, never stored).
     * With $publicKey (the CFBTEL VPN app made its own key pair), the private key never leaves that computer:
     * config is then null and 'peer' holds what the app needs.
     *
     * @return array{device: VpnDevice, config: ?string, peer: array{address: string, preshared_key: string}}
     */
    public function addDevice(VpnUser $user, VpnSite $site, string $name, ?string $publicKey = null): array
    {
        if ($user->isBlocked()) {
            throw new VpnException('Your VPN access is blocked. Contact your IT admin.');
        }
        if ($publicKey !== null && (!WireGuard::isKey($publicKey) || VpnDevice::where('public_key', $publicKey)->exists())) {
            throw new VpnException('This computer\'s key is invalid or already in use. Start the sign-in again from the app.');
        }
        if ($user->activeDevices()->count() >= VpnSettings::maxDevices()) {
            throw new VpnException('You already have ' . VpnSettings::maxDevices() . ' devices. Remove one first.');
        }
        if ($user->activeDevices()->where('vpn_site_id', $site->id)->whereRaw('lower(name) = ?', [strtolower($name)])->exists()) {
            throw new VpnException("You already have a device called \"{$name}\" there.");
        }

        return $this->locked($site, function () use ($user, $site, $name, $publicKey) {
            $driver = $this->driver($site);
            $onFirewall = collect($driver->peers($site->tunnel))->pluck('addresses')->flatten()->all();
            $inDb = VpnDevice::where('vpn_site_id', $site->id)->pluck('address')->all();
            $address = WireGuard::allocate($site->subnet, array_merge($onFirewall, $inDb, [WireGuard::firstHost($site->subnet)]));
            if (!$address) {
                throw new VpnException('No VPN addresses are left at that site. Contact your IT admin.');
            }
            $keys = $publicKey !== null ? ['public' => $publicKey, 'private' => null] : WireGuard::keyPair();
            $psk = WireGuard::presharedKey();
            $device = VpnDevice::create(['vpn_user_id' => $user->id, 'vpn_site_id' => $site->id, 'name' => $name, 'public_key' => $keys['public'], 'address' => $address]);
            try {
                $driver->addPeer($site->tunnel, [
                    'name' => $device->peerName(),
                    'public_key' => $keys['public'],
                    'preshared_key' => $psk,
                    'address' => $address,
                    'label' => "{$user->upn} {$name}",
                ]);
            } catch (\Throwable $e) {
                $device->delete();
                Log::error('VPN: adding a peer failed', ['site' => $site->id, 'error' => $e->getMessage()]);
                throw new VpnException('The office firewall didn\'t accept the new device. Try again shortly.', 0, $e);
            }
            VpnAuditLog::record($site->company_id, $user->upn, "device_added {$name} @ {$site->firewall->name} ({$address})");
            $config = $keys['private'] === null ? null : WireGuard::clientConfig($site->only(['dns', 'server_public_key', 'allowed_ips', 'endpoint']), [
                'label' => preg_replace('/[^\x20-\x7E]/', '?', "{$site->firewall->name} - {$name}"),
                'private_key' => $keys['private'],
                'preshared_key' => $psk,
                'address' => $address,
            ]);
            return ['device' => $device, 'config' => $config, 'peer' => ['address' => $address, 'preshared_key' => $psk]];
        });
    }

    /** Remove one device now. Throws if the firewall can't be reached; the device stays revoked and the sync retries. */
    public function removeDevice(VpnDevice $device, string $actor): void
    {
        $device->update(['revoked_at' => $device->revoked_at ?? now()]);
        $site = $device->site()->with('firewall')->first();
        $this->locked($site, function () use ($device, $site) {
            if ($site->tunnel) {
                $this->driver($site)->removePeers($site->tunnel, [$device->public_key]);
            }
            $device->delete();
        });
        VpnAuditLog::record($site->company_id, $actor, "device_removed {$device->name} @ {$site->firewall->name}");
    }

    /**
     * Finish removing a site's switched-off devices: take their peers off the firewall and forget them.
     * Throws if the firewall can't be reached (the rows stay, the sync retries).
     */
    public function clearRevoked(VpnSite $site): int
    {
        $site->loadMissing('firewall');
        $keys = VpnDevice::where('vpn_site_id', $site->id)->whereNotNull('revoked_at')->pluck('public_key')->all();
        if (!$keys) {
            return 0;
        }
        return $this->locked($site, function () use ($site, $keys) {
            $n = $site->tunnel ? $this->driver($site)->removePeers($site->tunnel, $keys) : 0;
            VpnDevice::where('vpn_site_id', $site->id)->whereNotNull('revoked_at')->delete();
            return $n;
        });
    }

    /**
     * Block a person: every device is removed from the firewalls now, and they can't sign in or add a device
     * until unblocked. The person stays on the list (a Microsoft 365 group can't add them back).
     *
     * @return array{removed: int, pending: int}
     */
    public function blockUser(VpnUser $user, string $actor): array
    {
        $user->update(['blocked_at' => now(), 'blocked_by' => mb_substr($actor, 0, 255)]);
        $devices = $user->devices()->with('site.firewall')->get();
        VpnDevice::whereIn('id', $devices->pluck('id'))->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $removed = 0;
        $pending = 0;
        foreach ($devices->groupBy('vpn_site_id') as $siteDevices) {
            $site = $siteDevices->first()->site;
            try {
                $this->locked($site, function () use ($site, $siteDevices, &$removed) {
                    if ($site->tunnel) {
                        $this->driver($site)->removePeers($site->tunnel, $siteDevices->pluck('public_key')->all());
                    }
                    VpnDevice::whereIn('id', $siteDevices->pluck('id'))->delete();
                    $removed += $siteDevices->count();
                });
            } catch (\Throwable $e) {
                $pending += $siteDevices->count();
                Log::warning('VPN: removing devices of a blocked person failed; the sync will retry', ['site' => $site->id, 'error' => $e->getMessage()]);
            }
        }
        VpnAuditLog::record($user->company_id, $actor, "vpn_access_blocked {$user->upn} ({$removed} device(s) removed" . ($pending ? ", {$pending} pending" : '') . ')');
        return ['removed' => $removed, 'pending' => $pending];
    }

    public function unblockUser(VpnUser $user, string $actor): void
    {
        $user->update(['blocked_at' => null, 'blocked_by' => null]);
        VpnAuditLog::record($user->company_id, $actor, "vpn_access_unblocked {$user->upn}");
    }

    /**
     * Take away a person's VPN access: their devices are revoked at once and removed from the firewalls.
     *
     * @return array{removed: int, pending: int}
     */
    public function removeUser(VpnUser $user, string $actor): array
    {
        $devices = $user->devices()->with('site.firewall')->get();
        VpnDevice::whereIn('id', $devices->pluck('id'))->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $companyId = $user->company_id;
        $upn = $user->upn;
        $user->delete(); // devices keep their row (vpn_user_id = null) until the firewall confirms
        $removed = 0;
        $pending = 0;
        foreach ($devices->groupBy('vpn_site_id') as $siteDevices) {
            $site = $siteDevices->first()->site;
            try {
                $this->locked($site, function () use ($site, $siteDevices, &$removed) {
                    if ($site->tunnel) {
                        $this->driver($site)->removePeers($site->tunnel, $siteDevices->pluck('public_key')->all());
                    }
                    VpnDevice::whereIn('id', $siteDevices->pluck('id'))->delete();
                    $removed += $siteDevices->count();
                });
            } catch (\Throwable $e) {
                $pending += $siteDevices->count();
                Log::warning('VPN: removing devices failed; the sync will retry', ['site' => $site->id, 'error' => $e->getMessage()]);
            }
        }
        VpnAuditLog::record($companyId, $actor, "vpn_access_removed {$upn} ({$removed} device(s) removed" . ($pending ? ", {$pending} pending" : '') . ')');
        return ['removed' => $removed, 'pending' => $pending];
    }

    /**
     * Keep the firewalls in line with who may use the VPN:
     *  - people whose Microsoft 365 account was deleted lose access; disabled accounts lose their devices;
     *  - revoked devices (and any cfbvpn- peer PFCentral no longer knows) are removed from the firewall.
     * Any Microsoft error stops the account check for that company without removing anything.
     *
     * @return array{removed: int, skipped: bool}
     */
    public function sync(Company $company, ?Microsoft $ms = null): array
    {
        $vc = VpnCompany::where('company_id', $company->id)->first();
        $tenant = $vc?->ms_tenant_id;
        $ms ??= Microsoft::for($vc);
        $skipped = false;
        if ($tenant) {
            $users = VpnUser::where('company_id', $company->id)->where('auth_type', 'microsoft')->whereNotNull('ms_oid')->get();
            $states = [];
            try {
                foreach ($users as $u) {
                    $states[$u->id] = $ms->userStatus($tenant, $u->ms_oid);
                }
            } catch (\Throwable $e) {
                $skipped = true;
                Log::warning('VPN sync: Microsoft unavailable; account check skipped', ['company' => $company->id, 'error' => $e->getMessage()]);
            }
            if (!$skipped) {
                foreach ($users as $u) {
                    $st = $states[$u->id];
                    if ($st === null) {
                        VpnDevice::where('vpn_user_id', $u->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
                        VpnAuditLog::record($company->id, 'system', "vpn_access_removed {$u->upn} (account deleted in Microsoft 365)");
                        $u->delete();
                    } elseif (!$st['enabled'] && $u->activeDevices()->exists()) {
                        $u->activeDevices()->update(['revoked_at' => now()]);
                        VpnAuditLog::record($company->id, 'system', "devices_revoked {$u->upn} (account disabled in Microsoft 365)");
                    }
                }
            }
        }

        // Microsoft 365 groups: people who came in through a group and left every group the company uses lose access.
        $member = []; // vpn user id => Microsoft 365 groups they're in (missing = unknown)
        if ($tenant && !$skipped && $vc && VpnAccess::usesGroups($vc)) {
            $ids = VpnAccess::groupIds($vc);
            $people = VpnUser::where('company_id', $company->id)->where('auth_type', 'microsoft')->whereNotNull('ms_oid')->get();
            try {
                foreach ($people as $u) {
                    $member[$u->id] = $ms->memberOf($tenant, $u->ms_oid, $ids);
                }
            } catch (\Throwable $e) {
                $member = [];
                $skipped = true;
                Log::warning('VPN sync: Microsoft groups unavailable; group check skipped', ['company' => $company->id, 'error' => $e->getMessage()]);
            }
            foreach ($member ? $people : [] as $u) {
                if ($u->viaGroup() && !array_intersect($ids, $member[$u->id])) {
                    VpnDevice::where('vpn_user_id', $u->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
                    VpnAuditLog::record($company->id, 'system', "vpn_access_removed {$u->upn} (no longer in the Microsoft 365 group)");
                    $u->delete();
                }
            }
        }

        // Access groups: devices on firewalls none of the person's groups allow (any more) are removed.
        $groups = VpnAccess::groups($company->id);
        if ($groups->isNotEmpty()) {
            $linked = $groups->flatMap(fn ($g) => $g->links->where('provider', 'microsoft')->pluck('external_id'))->map(fn ($id) => strtolower($id))->unique()->values()->all();
            $names = VpnSite::with('firewall')->where('company_id', $company->id)->get()->mapWithKeys(fn ($s) => [$s->id => $s->firewall?->name ?? "site {$s->id}"]);
            foreach (VpnUser::where('company_id', $company->id)->with('activeDevices')->get() as $u) {
                if ($u->activeDevices->isEmpty()) {
                    continue;
                }
                $needs = VpnAccess::needsMicrosoft($groups, $u);
                // Microsoft 365 groups unknown (Microsoft down): assume the person is in all of them, so only
                // devices that no group could allow are removed now.
                $in = !$needs ? [] : ($member[$u->id] ?? $linked);
                $allowed = VpnAccess::allowedSiteIds($groups, $u, $in);
                foreach ($u->activeDevices->reject(fn ($d) => in_array($d->vpn_site_id, $allowed, true))->groupBy('vpn_site_id') as $siteId => $gone) {
                    VpnDevice::whereIn('id', $gone->pluck('id'))->update(['revoked_at' => now()]);
                    VpnAuditLog::record($company->id, 'system', "devices_revoked {$u->upn} ({$gone->count()} on {$names[$siteId]}: no access group allows it)");
                }
            }
        }

        $removed = 0;
        foreach ($this->readySites($company->id) as $site) {
            try {
                $removed += $this->locked($site, function () use ($site) {
                    $driver = $this->driver($site);
                    $keep = VpnDevice::where('vpn_site_id', $site->id)->whereNull('revoked_at')->pluck('public_key')->flip();
                    $doomed = collect($driver->peers($site->tunnel))
                        ->filter(fn ($p) => str_starts_with($p['name'], WireGuard::PEER_PREFIX) && !isset($keep[$p['public_key']]))
                        ->pluck('public_key')->all();
                    $n = $doomed ? $driver->removePeers($site->tunnel, $doomed) : 0;
                    VpnDevice::where('vpn_site_id', $site->id)->whereNotNull('revoked_at')->delete();
                    return $n;
                });
            } catch (\Throwable $e) {
                Log::error('VPN sync: a site could not be checked', ['site' => $site->id, 'error' => $e->getMessage()]);
            }
        }
        if ($removed) {
            VpnAuditLog::record($company->id, 'system', "sync_removed {$removed} device(s) from the firewall");
        }
        return ['removed' => $removed, 'skipped' => $skipped];
    }
}
