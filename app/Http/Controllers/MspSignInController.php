<?php

namespace App\Http\Controllers;

use App\Services\Auth\MspSignIn;
use App\Http\Controllers\Vpn\MicrosoftSetupController;
use App\Services\Vpn\Microsoft;
use App\Services\Vpn\MicrosoftSetup;
use App\Services\Vpn\VpnException;
use App\Services\Vpn\VpnSettings;
use Illuminate\Http\Request;

/** Sign-in settings (global administrators): Microsoft 365 sign-in for the MSP's staff and which groups may use it. */
class MspSignInController extends Controller
{
    public function show()
    {
        return view('system.signin', [
            'enabled' => MspSignIn::get('enabled') === '1',
            'ready' => MspSignIn::enabled(),
            'tenantId' => MspSignIn::tenantId(),
            'groups' => MspSignIn::groups(),
            'clientId' => MspSignIn::clientId(),
            'hasSecret' => (bool) MspSignIn::clientSecret(),
            'vpnApp' => VpnSettings::clientId(),
            'vpnAppReady' => VpnSettings::clientId() && VpnSettings::hasClientSecret(),
            'redirectUri' => MspSignIn::redirectUri(),
            'autoReady' => MicrosoftSetup::make()->configured(),
            'autoSetupAt' => MspSignIn::get('auto_setup_at'),
            'secretExpires' => MspSignIn::get('secret_expires_at'),
        ]);
    }

    /** One click: a Global Administrator signs in to Microsoft and the app is created with everything filled in. */
    public function autoSetup(Request $request)
    {
        if (!MicrosoftSetup::make()->configured()) {
            return back()->withErrors(['auto' => 'Automatic setup needs the CFBTEL setup app (VPN Access → settings → One-click Microsoft 365 setup).']);
        }

        return redirect()->away(MicrosoftSetupController::mspLink(
            (string) $request->user()->email,
            MspSignIn::redirectUri(),
            route('system.signin'),
        ));
    }

    public function save(Request $request)
    {
        $v = $request->validate([
            'tenant_id' => ['nullable', 'string', 'max:36'],
            'client_id' => ['nullable', 'string', 'max:36'],
            'client_secret' => ['nullable', 'string', 'max:255'],
            'ms_groups' => ['array', 'max:' . MspSignIn::MAX_GROUPS],
            'ms_groups.*' => ['string', 'max:36'],
        ]);
        $tenant = strtolower(trim((string) ($v['tenant_id'] ?? '')));
        $client = strtolower(trim((string) ($v['client_id'] ?? '')));
        if ($tenant !== '' && !Microsoft::isGuid($tenant)) {
            return back()->withInput()->withErrors(['tenant_id' => 'The tenant ID is a GUID, for example 0a1b2c3d-1234-4e5f-8a9b-0123456789ab.']);
        }
        if ($client !== '' && !Microsoft::isGuid($client)) {
            return back()->withInput()->withErrors(['client_id' => 'The application (client) ID is a GUID.']);
        }

        $tenantChanged = $tenant !== (string) MspSignIn::tenantId();
        MspSignIn::set('tenant_id', $tenant ?: null);
        MspSignIn::set('client_id', $client ?: null);
        if ($client === '' || $request->boolean('clear_secret')) {
            MspSignIn::setClientSecret(null);
        }
        if ($client !== '' && !empty($v['client_secret'])) {
            MspSignIn::setClientSecret($v['client_secret']);
        }

        // Groups: keep names already known; look up new ones in Microsoft 365. A new tenant starts with no groups.
        $known = $tenantChanged ? [] : collect(MspSignIn::groups())->pluck('name', 'id')->all();
        $groups = [];
        foreach (array_unique(array_map('strtolower', $v['ms_groups'] ?? [])) as $gid) {
            if (!Microsoft::isGuid($gid)) {
                return back()->withInput()->withErrors(['ms_groups' => 'One of the groups is not a Microsoft 365 group ID.']);
            }
            if (isset($known[$gid])) {
                $groups[] = ['id' => $gid, 'name' => $known[$gid]];
                continue;
            }
            try {
                $g = MspSignIn::microsoft()->group($tenant, $gid);
            } catch (\Throwable $e) {
                return back()->withInput()->withErrors(['ms_groups' => $e instanceof VpnException ? $e->getMessage() : 'Could not read the group from Microsoft 365.']);
            }
            if (!$g) {
                return back()->withInput()->withErrors(['ms_groups' => 'A group you picked was not found in this tenant.']);
            }
            $groups[] = ['id' => $gid, 'name' => $g['name']];
        }
        MspSignIn::setGroups($groups);
        MspSignIn::set('enabled', $request->boolean('enabled') ? '1' : null);

        $message = 'Sign-in settings saved.';
        if ($request->boolean('enabled') && !MspSignIn::enabled()) {
            $message .= ' Microsoft 365 sign-in stays off until the tenant ID, at least one group and the Microsoft app are all set.';
        }

        return redirect()->route('system.signin')->with('success', $message);
    }

    public function searchGroups(Request $request)
    {
        $tenant = MspSignIn::tenantId();
        if (!Microsoft::isGuid($tenant)) {
            return response()->json(['error' => 'Save your Microsoft 365 tenant ID first, then pick groups.'], 422);
        }
        if (!MspSignIn::appReady()) {
            return response()->json(['error' => 'The Microsoft app is not set up yet.'], 422);
        }
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 100);
        try {
            $groups = MspSignIn::microsoft()->searchGroups($tenant, $q);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e instanceof VpnException ? $e->getMessage() : 'Could not search Microsoft 365 groups.'], 502);
        }

        return response()->json(array_values(array_map(fn ($g) => [
            'id' => strtolower($g['id']),
            'name' => $g['name'],
            'kind' => $g['m365'] ? 'Microsoft 365 group' : ($g['security'] ? 'Security group' : 'Group'),
        ], $groups)));
    }
}
