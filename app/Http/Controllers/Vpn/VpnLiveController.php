<?php

namespace App\Http\Controllers\Vpn;

use App\Http\Controllers\Controller;
use App\Models\Vpn\VpnAuditLog;
use App\Models\Vpn\VpnDevice;
use App\Models\Vpn\VpnSite;
use App\Models\Vpn\VpnUser;
use App\Services\Vpn\VpnLive;
use App\Services\Vpn\VpnManager;
use App\Services\Vpn\VpnMfa;
use Illuminate\Http\Request;

/** Global admins: who is on the VPN right now, across every company, with disconnect and block. */
class VpnLiveController extends Controller
{
    public function __construct(private VpnManager $vpn, private VpnLive $live)
    {
    }

    public function index()
    {
        return view('vpn.admin.live', [
            'devices' => VpnLive::devices(),
            'sites' => VpnLive::sites(),
            'connectedSeconds' => VpnLive::CONNECTED_SECONDS,
        ]);
    }

    /** The device and firewall lists (no firewall is asked here). */
    public function devices()
    {
        return response()->json(['devices' => VpnLive::devices(), 'sites' => VpnLive::sites(), 'now' => time()]);
    }

    /** What one firewall's WireGuard says right now. */
    public function site(VpnSite $site)
    {
        $site->loadMissing('firewall');
        return response()->json($this->live->site($site) + ['now' => time()]);
    }

    /** The detail panel: the person, their other devices and their recent VPN activity. */
    public function device(VpnDevice $device)
    {
        abort_if($device->revoked_at || !$device->user, 404);
        $device->load(['user.company', 'site.firewall']);
        $u = $device->user;
        $activity = VpnAuditLog::where('company_id', $u->company_id)
            ->where(fn ($q) => $q->where('actor', $u->upn)->orWhere('action', 'like', '%' . addcslashes($u->upn, '%_\\') . '%')->orWhere('action', 'like', '%' . addcslashes($device->name, '%_\\') . ' @ %'))
            ->orderByDesc('id')->limit(12)->get()
            ->map(fn ($l) => ['at' => $l->created_at?->toIso8601String(), 'actor' => $l->actor, 'action' => str_replace('_', ' ', $l->action)]);

        return response()->json([
            'device' => [
                'id' => $device->id,
                'name' => $device->name,
                'address' => $device->address,
                'added' => $device->created_at?->toIso8601String(),
                'firewall' => $device->site?->firewall?->name,
                'endpoint' => $device->site?->endpoint,
            ],
            'person' => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->upn,
                'signs_in_with' => $u->isLocal() ? 'Email & password' : ($u->viaGroup() ? 'Microsoft 365 (through a group)' : 'Microsoft 365'),
                'two_factor' => $u->isLocal() ? VpnMfa::label($u) : 'Microsoft 365 handles it',
                'last_sign_in' => $u->last_login_at?->toIso8601String(),
                'added_by' => $u->added_by,
                'blocked' => $u->isBlocked(),
                'blocked_by' => $u->blocked_by,
                'blocked_at' => $u->blocked_at?->toIso8601String(),
            ],
            'company' => ['id' => $u->company_id, 'name' => $u->company?->name, 'url' => route('vpn.company', $u->company_id)],
            'others' => $u->devices()->whereNull('revoked_at')->where('id', '!=', $device->id)->with('site.firewall')->get()
                ->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'firewall' => $d->site?->firewall?->name, 'address' => $d->address, 'site' => $d->vpn_site_id])->values(),
            'activity' => $activity,
        ]);
    }

    /** Disconnect now: the device's key is removed from the firewall. To come back, the person signs in again. */
    public function disconnect(Request $request, VpnDevice $device)
    {
        abort_if($device->revoked_at, 404);
        $siteId = $device->vpn_site_id;
        try {
            $this->vpn->removeDevice($device, $request->user()->email);
            VpnLive::forget($siteId);
            return response()->json(['ok' => true, 'message' => "“{$device->name}” is disconnected and removed. To reconnect, the person signs in again."]);
        } catch (\Throwable $e) {
            VpnLive::forget($siteId);
            return response()->json(['ok' => false, 'message' => "“{$device->name}” is switched off here, but the firewall didn't answer. It's removed from the firewall at the next automatic check (within 15 minutes)."], 202);
        }
    }

    /** Block: every device of the person is removed now, and they can't sign in again until unblocked. */
    public function block(Request $request, VpnUser $vpnUser)
    {
        abort_if($vpnUser->isBlocked(), 409, 'Already blocked.');
        $sites = $vpnUser->devices()->pluck('vpn_site_id')->unique();
        $r = $this->vpn->blockUser($vpnUser, $request->user()->email);
        $sites->each(fn ($id) => VpnLive::forget((int) $id));
        $msg = "{$vpnUser->name} is blocked. {$r['removed']} device(s) disconnected.";
        if ($r['pending']) {
            $msg .= " {$r['pending']} firewall(s) didn't answer; those devices are removed at the next automatic check.";
        }
        return response()->json(['ok' => true, 'message' => $msg]);
    }

    public function unblock(Request $request, VpnUser $vpnUser)
    {
        $this->vpn->unblockUser($vpnUser, $request->user()->email);
        return response()->json(['ok' => true, 'message' => "{$vpnUser->name} can sign in again. Their devices were removed, so they add them again from the VPN page or app."]);
    }

    /** Blocked people (they have no devices, so they're not in the live table). */
    public function blocked()
    {
        return response()->json(VpnUser::with('company')->whereNotNull('blocked_at')->orderByDesc('blocked_at')->get()
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->upn, 'company' => $u->company?->name, 'blocked_by' => $u->blocked_by, 'blocked_at' => $u->blocked_at?->toIso8601String()]));
    }
}
