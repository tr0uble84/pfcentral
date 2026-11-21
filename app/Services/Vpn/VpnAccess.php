<?php

namespace App\Services\Vpn;

use App\Models\Vpn\VpnAccessGroup;
use App\Models\Vpn\VpnAccessGroupLink;
use App\Models\Vpn\VpnCompany;
use App\Models\Vpn\VpnSite;
use App\Models\Vpn\VpnSite as Site;
use App\Models\Vpn\VpnUser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Who may use which firewall.
 *
 *  - VPN access: people added by hand, plus members of the company's Microsoft 365 group (optional) and of any
 *    sign-in provider group linked to one of the company's access groups.
 *  - Access groups (a company with 2 or more VPN firewalls can make them): each names some of the company's
 *    firewalls and its members — everyone with VPN access, people picked by hand, and/or linked provider groups.
 *    A person can use the firewalls of every group they're in.
 *  - No access groups: every firewall is open to everyone with VPN access.
 *    With access groups: a firewall that's in no group is blocked.
 *
 * Provider group membership (Microsoft 365 now, nested groups count) is cached for a few minutes;
 * adding a device and the 15-minute sync always ask the provider again.
 */
class VpnAccess
{
    public const CACHE_SECONDS = 300;
    /** Access groups are for companies with at least this many VPN firewalls. */
    public const MIN_FIREWALLS_FOR_GROUPS = 2;

    public function __construct(private VpnManager $vpn)
    {
    }

    /** @return Collection<int, VpnAccessGroup> with sites, users and links */
    public static function groups(int $companyId): Collection
    {
        return VpnAccessGroup::with(['sites.firewall', 'users', 'links'])->where('company_id', $companyId)->orderBy('name')->get();
    }

    public static function canMakeGroups(int $companyId): bool
    {
        return VpnSite::where('company_id', $companyId)->count() >= self::MIN_FIREWALLS_FOR_GROUPS;
    }

    /** Every Microsoft 365 group the company uses: the company group and those linked to access groups. @return string[] */
    public static function groupIds(VpnCompany $vc): array
    {
        $ids = VpnAccessGroupLink::where('provider', 'microsoft')
            ->whereIn('vpn_access_group_id', VpnAccessGroup::where('company_id', $vc->company_id)->select('id'))
            ->pluck('external_id')->all();
        foreach ($vc->companyGroups() as $g) {
            $ids[] = $g['id'];
        }
        return array_values(array_unique(array_map('strtolower', $ids)));
    }

    public static function usesGroups(VpnCompany $vc): bool
    {
        return self::groupIds($vc) !== [];
    }

    /**
     * Which of the company's Microsoft 365 groups this person is in.
     * @return string[]|null null when Microsoft couldn't be asked
     */
    public function membership(VpnCompany $vc, string $oid, bool $fresh = false): ?array
    {
        $ids = self::groupIds($vc);
        if (!$ids || !$vc->ms_tenant_id) {
            return [];
        }
        $key = 'vpn_groups:' . $vc->company_id . ':' . $oid . ':' . sha1(implode(',', $ids));
        if (!$fresh && is_array($hit = Cache::get($key))) {
            return $hit;
        }
        try {
            $in = Microsoft::for($vc)->memberOf($vc->ms_tenant_id, $oid, $ids);
        } catch (\Throwable $e) {
            Log::warning('VPN: group membership check failed', ['company' => $vc->company_id, 'error' => $e->getMessage()]);
            return null;
        }
        Cache::put($key, $in, self::CACHE_SECONDS);
        return $in;
    }

    /** Does group membership alone give this person VPN access (the company group or any linked group)? */
    public static function grantsAccess(VpnCompany $vc, array $membership): bool
    {
        return array_intersect(self::groupIds($vc), $membership) !== [];
    }

    /** Is this person a member of this access group? $microsoft: the Microsoft 365 groups they're in. */
    public static function isMember(VpnAccessGroup $g, VpnUser $user, array $microsoft): bool
    {
        if ($g->everyone || $g->users->contains('id', $user->id)) {
            return true;
        }
        $linked = $g->links->where('provider', 'microsoft')->pluck('external_id')->map(fn ($id) => strtolower($id))->all();
        return array_intersect($linked, $microsoft) !== [];
    }

    /**
     * The ids of the firewalls (VPN sites) this person may use; null = no access groups, all of them.
     * @param Collection<int, VpnAccessGroup> $groups
     */
    public static function allowedSiteIds(Collection $groups, VpnUser $user, array $microsoft): ?array
    {
        if ($groups->isEmpty()) {
            return null;
        }
        return $groups->filter(fn ($g) => self::isMember($g, $user, $microsoft))
            ->flatMap(fn ($g) => $g->sites->pluck('id'))->unique()->values()->all();
    }

    /** Does checking this person need their Microsoft 365 groups? */
    public static function needsMicrosoft(Collection $groups, VpnUser $user): bool
    {
        return !$user->isLocal() && $user->ms_oid && $groups->contains(fn ($g) => $g->links->contains('provider', 'microsoft'));
    }

    /** The firewalls this person can use. @return Collection<int, Site> */
    public function sitesFor(VpnUser $user, bool $fresh = false): Collection
    {
        $sites = $this->vpn->readySites($user->company_id);
        $groups = self::groups($user->company_id);
        if ($groups->isEmpty()) {
            return $sites;
        }
        $in = [];
        if (self::needsMicrosoft($groups, $user) && ($vc = VpnCompany::where('company_id', $user->company_id)->first())) {
            $in = $this->membership($vc, $user->ms_oid, $fresh) ?? []; // Microsoft down: only groups that don't depend on it
        }
        $allowed = self::allowedSiteIds($groups, $user, $in);
        return $sites->filter(fn (Site $s) => in_array($s->id, $allowed, true))->values();
    }

    /**
     * Who can use each of the company's VPN sites, for the console. @return array<int, string> site id => text
     * @param Collection<int, VpnAccessGroup> $groups
     */
    public static function describeSites(Collection $sites, Collection $groups): array
    {
        $out = [];
        foreach ($sites as $s) {
            if ($groups->isEmpty()) {
                $out[$s->id] = 'everyone with VPN access';
                continue;
            }
            $names = $groups->filter(fn ($g) => $g->sites->contains('id', $s->id))->pluck('name')->all();
            $out[$s->id] = $names ? 'group' . (count($names) > 1 ? 's ' : ' ') . implode(', ', $names) : 'blocked — not in any group';
        }
        return $out;
    }
}
