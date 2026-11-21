<?php

namespace App\Http\Controllers\Vpn;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Firewall;
use App\Models\Vpn\VpnAccessGroup;
use App\Models\Vpn\VpnAccessGroupLink;
use App\Models\Vpn\VpnAuditLog;
use App\Models\Vpn\VpnCompany;
use App\Models\Vpn\VpnDevice;
use App\Models\Vpn\VpnSite;
use App\Models\Vpn\VpnUser;
use App\Services\Vpn\Microsoft;
use App\Services\Vpn\VpnAccess;
use App\Services\Vpn\VpnException;
use App\Services\Vpn\VpnManager;
use App\Services\Vpn\VpnSettings;
use App\Services\Vpn\WireGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

/**
 * A company's VPN in the PFCentral console (Companies → company → VPN).
 * Global admins and that company's admins manage it; other company users can look.
 */
class CompanyVpnController extends Controller
{
    public const MAX_MS_GROUPS = 20;

    public function __construct(private VpnManager $vpn, private \App\Services\Vpn\VpnAccounts $accounts)
    {
    }

    /** The company's own Microsoft app if it has one, otherwise CFBTEL's shared app. */
    private function microsoft(VpnCompany $vc): Microsoft
    {
        return Microsoft::for($vc);
    }

    private function canManage(Request $request, Company $company): bool
    {
        $u = $request->user();
        return $u->isGlobalAdmin() || ($u->isCompanyAdmin() && (int) $u->company_id === (int) $company->id);
    }

    private function authorizeManage(Request $request, Company $company): void
    {
        abort_unless($this->canManage($request, $company), 403, 'Only global admins and this company\'s admins can change its VPN.');
    }

    private function actor(Request $request): string
    {
        return $request->user()->email;
    }

    public function show(Request $request, Company $company)
    {
        $vc = VpnCompany::forCompany($company);
        $q = trim((string) $request->query('q', ''));
        $results = null;
        $searchError = null;
        if ($vc->ms_tenant_id && ($q !== '' || $request->has('search')) && $this->canManage($request, $company)) {
            try {
                $have = VpnUser::where('company_id', $company->id)->pluck('ms_oid')->flip();
                $results = collect($this->microsoft($vc)->searchUsers($vc->ms_tenant_id, $q))->filter(fn ($u) => $u['enabled'] && !isset($have[$u['oid']]))->values();
            } catch (\Throwable $e) {
                $searchError = $e instanceof VpnException ? $e->getMessage() : 'Couldn\'t search Microsoft 365.';
            }
        }
        $consentUrl = null;
        $consentError = null;
        if (!$vc->hasOwnApp()) {
            try {
                $state = Crypt::encryptString(json_encode(['c' => $company->id, 'exp' => time() + 7 * 86400]));
                $consentUrl = Microsoft::for(null)->adminConsentUrl(VpnSettings::portalUrl('/vpn/consent/callback'), $state);
            } catch (VpnException $e) {
                $consentError = 'CFBTEL\'s shared Microsoft app isn\'t set up. Use this company\'s own Microsoft app below, or set up the shared app in VPN settings.';
            }
        }
        $setupReady = \App\Services\Vpn\MicrosoftSetup::make()->configured();
        $setupLink = $setupReady && $this->canManage($request, $company) ? MicrosoftSetupController::link($company, $this->actor($request)) : null;
        $sites = VpnSite::with('firewall')->where('company_id', $company->id)->get()->keyBy('firewall_id');
        $accessGroups = VpnAccess::groups($company->id);

        return view('vpn.admin.company', [
            'company' => $company,
            'vc' => $vc,
            'firewalls' => Firewall::where('company_id', $company->id)->orderBy('name')->get(),
            'sites' => $sites,
            'accessGroups' => $accessGroups,
            'canMakeGroups' => VpnAccess::canMakeGroups($company->id),
            'siteAccess' => VpnAccess::describeSites($sites, $accessGroups),
            'users' => VpnUser::where('company_id', $company->id)->withCount('activeDevices')->orderBy('name')->get(),
            'devices' => VpnDevice::with(['user', 'site.firewall'])->whereIn('vpn_site_id', $sites->pluck('id'))->orderByDesc('created_at')->get(),
            'audit' => VpnAuditLog::where('company_id', $company->id)->orderByDesc('id')->limit(40)->get(),
            'mfaCompany' => \App\Services\Vpn\VpnMfa::companyMode($company->id),
            'supportReports' => \App\Models\Vpn\VpnSupportReport::where('company_id', $company->id)->orderByDesc('id')->limit(20)
                ->get(['id', 'reference', 'contact_name', 'contact_email', 'subject', 'computer', 'device_name', 'problems', 'warnings', 'emailed_at', 'email_error', 'created_at']),
            'q' => $q,
            'results' => $results,
            'searchError' => $searchError,
            'consentUrl' => $consentUrl,
            'consentError' => $consentError,
            'setupReady' => $setupReady,
            'setupLink' => $setupLink,
            'canManage' => $this->canManage($request, $company),
            'portalUrl' => VpnSettings::portalUrl('/vpn'),
            'callbackUrl' => VpnSettings::portalUrl('/vpn/callback'),
        ]);
    }

    // ---- people ----

    public function addUser(Request $request, Company $company)
    {
        $this->authorizeManage($request, $company);
        $oid = strtolower((string) $request->input('oid'));
        $vc = VpnCompany::forCompany($company);
        if (!$vc->ms_tenant_id || !Microsoft::isGuid($oid)) {
            return back()->with('error', 'Pick someone from the search results.');
        }
        if (VpnUser::where('company_id', $company->id)->where('ms_oid', $oid)->exists()) {
            return back()->with('error', 'That person already has VPN access.');
        }
        try {
            $u = $this->microsoft($vc)->userStatus($vc->ms_tenant_id, $oid); // name and UPN come from Microsoft, never from the form
        } catch (\Throwable $e) {
            return back()->with('error', $e instanceof VpnException ? $e->getMessage() : 'Couldn\'t reach Microsoft 365.');
        }
        if (!$u || !$u['enabled']) {
            return back()->with('error', 'That account doesn\'t exist or is disabled in this company\'s Microsoft 365.');
        }
        if (VpnUser::where('auth_type', 'local')->where('upn', $u['upn'])->exists()) {
            return back()->with('error', 'That email already has an email-and-password VPN account. Remove it first.');
        }
        VpnUser::create([
            'company_id' => $company->id,
            'auth_type' => 'microsoft',
            'ms_oid' => $oid,
            'upn' => $u['guest'] && $u['mail'] ? strtolower($u['mail']) : $u['upn'],
            'name' => $u['name'],
            'added_by' => $this->actor($request),
        ]);
        VpnAuditLog::record($company->id, $this->actor($request), "vpn_access_given {$u['upn']}");
        return redirect()->route('vpn.company', $company)->with('success', "{$u['name']} can now sign in at the VPN page and add devices.");
    }

    /** Email domains (all companies) and, optionally, the company's own Microsoft app. */
    public function saveMicrosoft(Request $request, Company $company)
    {
        $this->authorizeManage($request, $company);
        $vc = VpnCompany::forCompany($company);
        $v = $request->validate([
            'email_domains' => ['nullable', 'string', 'max:500'],
            'ms_tenant_id' => ['nullable', 'string', 'max:36'],
            'ms_client_id' => ['nullable', 'string', 'max:36'],
            'ms_client_secret' => ['nullable', 'string', 'max:300'],
        ]);
        $domains = array_values(array_unique(array_filter(array_map(fn ($d) => strtolower(trim(ltrim(trim($d), '@'))), preg_split('/[\s,;]+/', (string) ($v['email_domains'] ?? ''))))));
        foreach ($domains as $d) {
            if (!preg_match('/^(?=.{3,253}$)([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}$/', $d)) {
                return back()->withErrors(['email_domains' => "“{$d}” isn't a domain name."])->withInput();
            }
            $other = VpnCompany::where('id', '!=', $vc->id)->get()->first(fn ($o) => in_array($d, $o->domains(), true));
            if ($other) {
                return back()->withErrors(['email_domains' => "{$d} is already used by another company."])->withInput();
            }
        }

        $tenant = strtolower(trim((string) ($v['ms_tenant_id'] ?? '')));
        $client = strtolower(trim((string) ($v['ms_client_id'] ?? '')));
        $secret = (string) ($v['ms_client_secret'] ?? '');
        $ownApp = $tenant !== '' || $client !== '' || $secret !== '';
        if ($ownApp) {
            if (!Microsoft::isGuid($tenant) || !Microsoft::isGuid($client)) {
                return back()->withErrors(['ms_client_id' => 'Enter the Directory (tenant) ID and the Application (client) ID from the company\'s app registration.'])->withInput();
            }
            if ($secret === '' && !($vc->hasOwnApp() && $vc->ms_client_id === $client)) {
                return back()->withErrors(['ms_client_secret' => 'Enter the client secret.'])->withInput();
            }
            if (VpnCompany::where('ms_tenant_id', $tenant)->where('id', '!=', $vc->id)->exists()) {
                return back()->withErrors(['ms_tenant_id' => 'This Microsoft 365 tenant is already linked to another company.'])->withInput();
            }
            if ($vc->ms_tenant_id && $vc->ms_tenant_id !== $tenant && VpnUser::where('company_id', $company->id)->exists()) {
                return back()->withErrors(['ms_tenant_id' => 'This company is linked to another tenant and already has VPN people. Remove them first.'])->withInput();
            }
        }

        $vc->email_domains = $domains ? implode(',', $domains) : null;
        if ($ownApp) {
            if ($vc->ms_client_id !== $client || $secret !== '') {
                // Entered by hand: the one-click setup's details no longer describe this app/secret.
                $vc->ms_app_object_id = $vc->ms_client_id === $client ? $vc->ms_app_object_id : null;
                $vc->ms_secret_key_id = null;
                $vc->ms_secret_expires_at = null;
            }
            $vc->ms_tenant_id = $tenant;
            $vc->ms_client_id = $client;
            if ($secret !== '') {
                $vc->ms_client_secret = $secret;
            }
        }
        $vc->save();
        VpnAuditLog::record($company->id, $this->actor($request), 'microsoft_settings ' . ($ownApp ? "own app {$client} in {$tenant}" : 'shared app') . ($domains ? ' domains ' . implode(',', $domains) : ''));

        if ($ownApp) {
            // Check the app can read this tenant's users (User.Read.All with admin consent).
            try {
                $ms = Microsoft::for($vc->fresh());
                $ms->searchUsers($tenant, '');
                $vc->update(['ms_tenant_name' => $ms->tenantName($tenant)]);
            } catch (\Throwable $e) {
                return back()->with('warning', 'Saved, but Microsoft refused the app: ' . $e->getMessage() . ' Check the secret, the User.Read.All application permission and "Grant admin consent".');
            }
            return back()->with('success', 'Saved. Staff of this company now sign in through its own Microsoft app.');
        }
        return back()->with('success', 'Saved.');
    }

    /** Use a Microsoft 365 group for the whole company ("company") or link it to an access group ("group:<id>"). */
    public function assignGroup(Request $request, Company $company)
    {
        $this->authorizeManage($request, $company);
        $vc = VpnCompany::forCompany($company);
        $v = $request->validate(['group_id' => ['required', 'string', 'max:36'], 'target' => ['required', 'string', 'max:20']]);
        $access = null;
        if ($v['target'] !== 'company') {
            $access = preg_match('/^group:(\d+)$/', $v['target'], $m) ? VpnAccessGroup::where('company_id', $company->id)->find((int) $m[1]) : null;
            abort_unless($access, 404);
        }
        if (!$vc->ms_tenant_id || !Microsoft::isGuid($v['group_id'])) {
            return back()->with('error', 'Connect Microsoft 365 first, then pick a group.');
        }
        try {
            $g = $this->microsoft($vc)->group($vc->ms_tenant_id, strtolower($v['group_id']));
        } catch (\Throwable $e) {
            return back()->with('error', $e instanceof VpnException ? $e->getMessage() : 'Couldn\'t read the group from Microsoft 365.');
        }
        if (!$g) {
            return back()->with('error', 'That group wasn\'t found in this company\'s Microsoft 365.');
        }
        if (!$access) {
            $list = collect($vc->companyGroups())->reject(fn ($x) => $x['id'] === strtolower($g['id']))->push(['id' => strtolower($g['id']), 'name' => $g['name']])->values()->all();
            $vc->update(['access_groups' => $list]);
            VpnAuditLog::record($company->id, $this->actor($request), "group_set {$g['name']} for the whole company");
            return redirect()->route('vpn.company', $company)->with('success', "Members of the Microsoft 365 group “{$g['name']}” now get VPN access.");
        }
        VpnAccessGroupLink::updateOrCreate(
            ['vpn_access_group_id' => $access->id, 'provider' => 'microsoft', 'external_id' => strtolower($g['id'])],
            ['external_name' => $g['name']],
        );
        VpnAuditLog::record($company->id, $this->actor($request), "access_group_linked {$access->name} ← Microsoft 365 {$g['name']}");
        return redirect()->route('vpn.company', $company)->with('success', "Members of the Microsoft 365 group “{$g['name']}” are now in the access group “{$access->name}”.");
    }

    public function clearGroup(Request $request, Company $company)
    {
        $this->authorizeManage($request, $company);
        VpnCompany::forCompany($company)->update(['access_groups' => null, 'access_group_id' => null, 'access_group_name' => null]);
        VpnAuditLog::record($company->id, $this->actor($request), 'group_cleared for the whole company');
        return redirect()->route('vpn.company', $company)->with('success', 'No Microsoft 365 group for the whole company any more. People who came in through it lose access at the next sync unless another group still includes them.');
    }

    /** Instant search for the group picker: JSON [{id, name, kind}]. */
    public function searchGroups(Request $request, Company $company)
    {
        $this->authorizeManage($request, $company);
        $vc = VpnCompany::forCompany($company);
        if (!$vc->ms_tenant_id) {
            return response()->json(['error' => 'Connect Microsoft 365 first.'], 422);
        }
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 100);
        try {
            $groups = $this->microsoft($vc)->searchGroups($vc->ms_tenant_id, $q);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e instanceof VpnException ? $e->getMessage() : 'Couldn\'t search Microsoft 365 groups.'], 502);
        }
        return response()->json(array_values(array_map(fn ($g) => [
            'id' => strtolower($g['id']),
            'name' => $g['name'],
            'kind' => $g['m365'] ? 'Microsoft 365 group' : ($g['security'] ? 'Security group' : 'Group'),
        ], $groups)));
    }

    /**
     * Turn the picker's IDs into [{id, name}], checking each new ID with Microsoft (names of IDs already saved are kept).
     *
     * @param  array<string, string>  $known  id => name already saved
     * @return array<int, array{id: string, name: string}>
     */
    private function resolveMsGroups(VpnCompany $vc, array $ids, array $known): array
    {
        $ids = array_values(array_unique(array_map('strtolower', array_filter($ids, 'is_string'))));
        if (count($ids) > self::MAX_MS_GROUPS) {
            throw new VpnException('Pick at most ' . self::MAX_MS_GROUPS . ' Microsoft 365 groups.');
        }
        $out = [];
        foreach ($ids as $id) {
            if (!Microsoft::isGuid($id)) {
                throw new VpnException('One of the groups isn\'t a Microsoft 365 group ID.');
            }
            if (isset($known[$id])) {
                $out[] = ['id' => $id, 'name' => $known[$id]];
                continue;
            }
            if (!$vc->ms_tenant_id) {
                throw new VpnException('Connect Microsoft 365 first, then pick groups.');
            }
            try {
                $g = $this->microsoft($vc)->group($vc->ms_tenant_id, $id);
            } catch (\Throwable $e) {
                throw new VpnException($e instanceof VpnException ? $e->getMessage() : 'Couldn\'t read the group from Microsoft 365.');
            }
            if (!$g) {
                throw new VpnException('A group you picked wasn\'t found in this company\'s Microsoft 365.');
            }
            $out[] = ['id' => $id, 'name' => $g['name']];
        }
        return $out;
    }

    /** Save the Microsoft 365 groups whose members get VPN access (the whole list at once; empty = none). */
    public function saveCompanyGroups(Request $request, Company $company)
    {
        $this->authorizeManage($request, $company);
        $vc = VpnCompany::forCompany($company);
        $v = $request->validate(['ms_groups' => ['array'], 'ms_groups.*' => ['string', 'max:36']]);
        $known = collect($vc->companyGroups())->pluck('name', 'id')->all();
        try {
            $list = $this->resolveMsGroups($vc, $v['ms_groups'] ?? [], $known);
        } catch (VpnException $e) {
            return back()->with('error', $e->getMessage());
        }
        $vc->update(['access_groups' => $list ?: null, 'access_group_id' => null, 'access_group_name' => null]);
        $added = array_diff(array_column($list, 'id'), array_keys($known));
        $removed = array_diff(array_keys($known), array_column($list, 'id'));
        if ($added || $removed) {
            $name = fn ($id) => collect($list)->firstWhere('id', $id)['name'] ?? $known[$id] ?? $id;
            VpnAuditLog::record($company->id, $this->actor($request), 'company_groups'
                . ($added ? ' added ' . implode(', ', array_map($name, $added)) : '')
                . ($removed ? ' removed ' . implode(', ', array_map($name, $removed)) : ''));
        }
        $msg = $list ? 'Saved. Members of ' . implode(', ', array_map(fn ($g) => "“{$g['name']}”", $list)) . ' get VPN access.' : 'Saved. No Microsoft 365 group gives VPN access to the whole company.';
        return redirect()->route('vpn.company', $company)->with('success', $msg . ($removed ? ' People who came in only through a removed group lose access at the next sync (within 15 minutes).' : ''));
    }

    /** Create (no id) or change an access group: name, firewalls, everyone / people picked by hand. */
    public function saveAccessGroup(Request $request, Company $company, ?VpnAccessGroup $group = null)
    {
        $this->authorizeManage($request, $company);
        if ($group) {
            abort_unless($group->company_id === $company->id, 404);
        } elseif (!VpnAccess::canMakeGroups($company->id)) {
            return back()->with('error', 'Access groups need at least ' . VpnAccess::MIN_FIREWALLS_FOR_GROUPS . ' firewalls with VPN. With one firewall, everyone with VPN access uses it.');
        }
        $v = $request->validate([
            'name' => ['required', 'string', 'max:80', \Illuminate\Validation\Rule::unique('vpn_access_groups', 'name')->where('company_id', $company->id)->ignore($group?->id)],
            'sites' => ['required', 'array', 'min:1'],
            'sites.*' => ['integer'],
            'users' => ['array'],
            'users.*' => ['integer'],
            'everyone' => ['nullable', 'boolean'],
            'ms_groups' => ['array'],
            'ms_groups.*' => ['string', 'max:36'],
        ], ['sites.required' => 'Pick at least one firewall for the group.']);
        $siteIds = VpnSite::where('company_id', $company->id)->whereIn('id', $v['sites'])->pluck('id');
        if ($siteIds->isEmpty()) {
            return back()->withInput()->with('error', 'Pick at least one of this company\'s firewalls.');
        }
        $userIds = VpnUser::where('company_id', $company->id)->whereIn('id', $v['users'] ?? [])->pluck('id');
        $msGroups = null;
        if ($request->boolean('ms_groups_present')) {
            $vc = VpnCompany::forCompany($company);
            $known = $group ? $group->links()->where('provider', 'microsoft')->pluck('external_name', 'external_id')->map(fn ($n) => (string) $n)->all() : [];
            try {
                $msGroups = $this->resolveMsGroups($vc, $v['ms_groups'] ?? [], $known);
            } catch (VpnException $e) {
                return back()->withInput()->with('error', $e->getMessage());
            }
        }
        $first = !$group && !VpnAccessGroup::where('company_id', $company->id)->exists();
        $group ??= new VpnAccessGroup(['company_id' => $company->id]);
        $group->fill(['name' => trim($v['name']), 'everyone' => (bool) ($v['everyone'] ?? false)])->save();
        $group->sites()->sync($siteIds);
        $group->users()->sync($userIds);
        if ($msGroups !== null) {
            $group->links()->where('provider', 'microsoft')->whereNotIn('external_id', array_column($msGroups, 'id') ?: [''])->delete();
            foreach ($msGroups as $g) {
                VpnAccessGroupLink::updateOrCreate(
                    ['vpn_access_group_id' => $group->id, 'provider' => 'microsoft', 'external_id' => $g['id']],
                    ['external_name' => $g['name']],
                );
            }
        }
        VpnAuditLog::record($company->id, $this->actor($request), ($group->wasRecentlyCreated ? 'access_group_created ' : 'access_group_changed ') . $group->name
            . ' (' . $siteIds->count() . ' firewall(s), ' . ($group->everyone ? 'everyone' : $userIds->count() . ' person(s)')
            . ($msGroups ? ', Microsoft 365: ' . implode(', ', array_column($msGroups, 'name')) : '') . ')');

        $msg = "Access group “{$group->name}” saved.";
        $blocked = $this->blockedFirewalls($company->id);
        if ($blocked) {
            $msg .= ($first ? ' Access groups are now on: ' : ' ') . 'Firewalls in no group are blocked: ' . implode(', ', $blocked) . '.';
        }
        return redirect()->route('vpn.company', $company)->with('success', $msg . ' People lose devices on firewalls their groups don\'t allow at the next sync (within 15 minutes).');
    }

    public function deleteAccessGroup(Request $request, Company $company, VpnAccessGroup $group)
    {
        $this->authorizeManage($request, $company);
        abort_unless($group->company_id === $company->id, 404);
        $name = $group->name;
        $group->delete();
        VpnAuditLog::record($company->id, $this->actor($request), "access_group_deleted {$name}");
        $left = VpnAccessGroup::where('company_id', $company->id)->exists();
        $blocked = $left ? $this->blockedFirewalls($company->id) : [];
        return redirect()->route('vpn.company', $company)->with('success', "Access group “{$name}” deleted."
            . ($left ? ($blocked ? ' Firewalls in no group are blocked: ' . implode(', ', $blocked) . '.' : '') : ' No access groups left: every firewall is open to everyone with VPN access again.'));
    }

    public function unlinkAccessGroup(Request $request, Company $company, VpnAccessGroupLink $link)
    {
        $this->authorizeManage($request, $company);
        $group = $link->group;
        abort_unless($group && $group->company_id === $company->id, 404);
        $link->delete();
        VpnAuditLog::record($company->id, $this->actor($request), "access_group_unlinked {$group->name} ← {$link->providerName()} {$link->external_name}");
        return redirect()->route('vpn.company', $company)->with('success', "“{$link->external_name}” is no longer linked to “{$group->name}”. Its members lose that access at the next sync.");
    }

    /** Names of the company's VPN firewalls that are in no access group. @return string[] */
    private function blockedFirewalls(int $companyId): array
    {
        return VpnSite::with('firewall')->where('company_id', $companyId)
            ->whereNotIn('id', \Illuminate\Support\Facades\DB::table('vpn_access_group_sites')->select('vpn_site_id'))
            ->get()->map(fn ($s) => $s->firewall?->name ?? "site {$s->id}")->all();
    }

    public function removeOwnApp(Request $request, Company $company)
    {
        $this->authorizeManage($request, $company);
        $vc = VpnCompany::forCompany($company);
        $vc->update(['ms_client_id' => null, 'ms_client_secret' => null, 'ms_app_object_id' => null, 'ms_secret_key_id' => null, 'ms_secret_expires_at' => null]);
        VpnAuditLog::record($company->id, $this->actor($request), 'microsoft_own_app_removed');
        return back()->with('success', 'The company\'s own Microsoft app was removed. Sign-in uses CFBTEL\'s shared app again (the company must approve it).');
    }

    // ---- email-and-password accounts (companies without Microsoft Entra) ----

    public function inviteUser(Request $request, Company $company)
    {
        $this->authorizeManage($request, $company);
        $name = trim((string) $request->input('name'));
        $email = \App\Services\Vpn\VpnAccounts::cleanEmail($request->input('email'));
        if ($name === '' || mb_strlen($name) > 120 || !$email) {
            return back()->withErrors(['invite' => 'Enter the person\'s name and a valid email address.'])->withInput();
        }
        if (\App\Services\Vpn\VpnAccounts::emailTaken($email)) {
            return back()->withErrors(['invite' => "{$email} already has VPN access."])->withInput();
        }
        $mfa = (string) $request->input('mfa_mode', '');
        $mfa = isset(\App\Services\Vpn\VpnMfa::MODES[$mfa]) ? $mfa : null;
        try {
            $this->accounts->invite($company, $name, $email, $this->actor($request), $mfa);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('VPN: invitation email failed', ['error' => $e->getMessage()]);
            return back()->with('warning', "{$name} was added, but the invitation email couldn't be sent: {$e->getMessage()} Check the email settings, then click Resend invite.");
        }
        return back()->with('success', "Invitation sent to {$email}. The link works for 7 days.");
    }

    public function updateUser(Request $request, Company $company, VpnUser $vpnUser)
    {
        $this->authorizeManage($request, $company);
        abort_unless((int) $vpnUser->company_id === (int) $company->id && $vpnUser->isLocal(), 404);
        $name = trim((string) $request->input('name'));
        $email = \App\Services\Vpn\VpnAccounts::cleanEmail($request->input('email'));
        if ($name === '' || mb_strlen($name) > 120 || !$email) {
            return back()->withErrors(['edit' => 'Enter a name and a valid email address.']);
        }
        if (\App\Services\Vpn\VpnAccounts::emailTaken($email, $vpnUser->id)) {
            return back()->withErrors(['edit' => "{$email} already has VPN access."]);
        }
        $changed = $email !== $vpnUser->upn;
        $mfa = (string) $request->input('mfa_mode', '');
        $mfa = isset(\App\Services\Vpn\VpnMfa::MODES[$mfa]) ? $mfa : null;
        $mfaChanged = $request->has('mfa_mode') && $mfa !== $vpnUser->mfa_mode;
        $vpnUser->update(['name' => $name, 'upn' => $email] + ($request->has('mfa_mode') ? ['mfa_mode' => $mfa] : []));
        VpnAuditLog::record($company->id, $this->actor($request), "vpn_account_updated {$email}" . ($changed ? ' (email changed)' : '')
            . ($mfaChanged ? ' (two-factor: ' . ($mfa ?? 'company setting') . ')' : ''));
        return back()->with('success', "Saved {$name}." . ($changed ? ' They now sign in with the new email address.' : ''));
    }

    /** Company setting for email-and-password accounts: two-factor optional or required. */
    public function saveMfaMode(Request $request, Company $company)
    {
        $this->authorizeManage($request, $company);
        $v = $request->validate(['mfa_mode' => ['required', 'in:' . implode(',', array_keys(\App\Services\Vpn\VpnMfa::MODES))]]);
        VpnCompany::forCompany($company)->update(['mfa_mode' => $v['mfa_mode']]);
        VpnAuditLog::record($company->id, $this->actor($request), "mfa_company_mode {$v['mfa_mode']}");
        return back()->with('success', $v['mfa_mode'] === 'required'
            ? 'Two-factor sign-in is now required for email-and-password accounts (unless set otherwise on a person). Anyone who hasn\'t set it up will do so at their next sign-in.'
            : 'Two-factor sign-in is now optional for email-and-password accounts (unless set otherwise on a person).');
    }

    /** The person lost their phone: remove their authenticator; they set it up again (if required) at the next sign-in. */
    public function resetMfa(Request $request, Company $company, VpnUser $vpnUser)
    {
        $this->authorizeManage($request, $company);
        abort_unless((int) $vpnUser->company_id === (int) $company->id && $vpnUser->isLocal(), 404);
        \App\Services\Vpn\VpnMfa::disable($vpnUser, $this->actor($request), 'mfa_reset');
        return back()->with('success', "Two-factor was reset for {$vpnUser->name}."
            . (\App\Services\Vpn\VpnMfa::required($vpnUser) ? ' They set it up again at their next sign-in.' : ' They can turn it on again on the VPN page.'));
    }

    public function resendInvite(Request $request, Company $company, VpnUser $vpnUser)
    {
        $this->authorizeManage($request, $company);
        abort_unless((int) $vpnUser->company_id === (int) $company->id && $vpnUser->isLocal(), 404);
        try {
            $this->accounts->sendLink($vpnUser, false);
        } catch (\Throwable $e) {
            return back()->with('error', "The email couldn't be sent: {$e->getMessage()}");
        }
        VpnAuditLog::record($company->id, $this->actor($request), "vpn_invite_resent {$vpnUser->upn}");
        return back()->with('success', "A new link was sent to {$vpnUser->upn}" . ($vpnUser->password ? ' (their current password keeps working until they set a new one).' : '.'));
    }

    public function removeUser(Request $request, Company $company, VpnUser $vpnUser)
    {
        $this->authorizeManage($request, $company);
        abort_unless((int) $vpnUser->company_id === (int) $company->id, 404);
        $r = $this->vpn->removeUser($vpnUser, $this->actor($request));
        return back()->with(
            $r['pending'] ? 'warning' : 'success',
            "{$vpnUser->name} no longer has VPN access. {$r['removed']} device(s) removed" . ($r['pending'] ? "; {$r['pending']} will be removed when the firewall answers (automatic check every 15 minutes)." : '.'),
        );
    }

    public function removeDevice(Request $request, Company $company, VpnDevice $device)
    {
        $this->authorizeManage($request, $company);
        abort_unless((int) $device->site?->company_id === (int) $company->id, 404);
        try {
            $this->vpn->removeDevice($device, $this->actor($request));
            return back()->with('success', "Device “{$device->name}” removed. It can no longer connect.");
        } catch (\Throwable $e) {
            return back()->with('warning', "Device “{$device->name}” was switched off; the firewall didn't answer, so it will be removed at the next automatic check.");
        }
    }

    // ---- firewalls ----

    public function enableFirewall(Request $request, Company $company, Firewall $firewall)
    {
        $this->authorizeManage($request, $company);
        abort_unless((int) $firewall->company_id === (int) $company->id, 404);
        $site = VpnSite::firstOrCreate(['firewall_id' => $firewall->id], ['company_id' => $company->id, 'status' => 'pending']);
        return redirect()->route('vpn.site', [$company, $site]);
    }

    private function siteOf(Company $company, VpnSite $site): VpnSite
    {
        abort_unless((int) $site->company_id === (int) $company->id, 404);
        return $site->load('firewall');
    }

    public function site(Request $request, Company $company, VpnSite $site)
    {
        $site = $this->siteOf($company, $site);
        $data = ['company' => $company, 'site' => $site, 'interfaces' => null, 'tunnels' => null, 'readError' => null, 'suggestedSubnet' => '10.0.70.0/24', 'suggestedHost' => parse_url((string) $site->firewall->url, PHP_URL_HOST) ?: '', 'canManage' => $this->canManage($request, $company), 'log' => session('vpn_log')];
        if (!$site->isReady()) {
            try {
                $driver = $this->vpn->driver($site);
                $data['interfaces'] = $driver->interfaces();
                $data['tunnels'] = $driver->tunnels();
                $data['needsPackage'] = $driver instanceof \App\Services\Vpn\PfSenseWireGuardDriver && !$driver->packageInstalled();
                $used = collect($data['interfaces'])->map(fn ($i) => implode('.', array_slice(explode('.', (string) $i['ipaddr']), 0, 3)))->flip();
                for ($k = 70; $k < 100; $k++) {
                    if (!isset($used["10.0.{$k}"])) {
                        $data['suggestedSubnet'] = "10.0.{$k}.0/24";
                        break;
                    }
                }
                $wan = collect($data['interfaces'])->firstWhere('id', 'wan')['ipaddr'] ?? null;
                if ($wan && filter_var($wan, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    $data['suggestedHost'] = $wan;
                }
            } catch (\Throwable $e) {
                $data['readError'] = $e->getMessage();
            }
        }
        return view('vpn.admin.site', $data);
    }

    public function setup(Request $request, Company $company, VpnSite $site)
    {
        $this->authorizeManage($request, $company);
        $site = $this->siteOf($company, $site);
        $v = $request->validate([
            'lan' => ['required', 'regex:/^[a-z0-9_]{1,20}$/i'],
            'wan' => ['required', 'regex:/^[a-z0-9_]{1,20}$/i'],
            'port' => ['required', 'integer', 'between:1024,65535'],
            'subnet' => ['required', 'string', 'max:20'],
            'endpoint_host' => ['required', 'regex:/^[A-Za-z0-9.-]{1,120}$/'],
        ]);
        // Installing pfSense's WireGuard package can take a few minutes: finish even if the browser gives up.
        @set_time_limit(600);
        ignore_user_abort(true);
        try {
            WireGuard::parseSubnet($v['subnet']);
            $driver = $this->vpn->driver($site);
            // A port already used by a tunnel on this firewall: nothing is changed; adopt that tunnel or pick another port.
            // (A "CFBTEL VPN" tunnel left by an earlier attempt that stopped part-way is finished instead.)
            $taken = collect($driver->tunnels())->first(fn ($t) => (int) ($t['listen_port'] ?? 0) === (int) $v['port'] && ($t['name'] ?? '') !== 'CFBTEL VPN');
            if ($taken) {
                $what = trim(($taken['ref'] ?? '') . (($taken['name'] ?? '') !== '' && ($taken['name'] ?? '') !== ($taken['ref'] ?? '') ? " ({$taken['name']})" : ''));
                return redirect()->route('vpn.site', [$company, $site])->withInput()
                    ->with('error', "UDP {$v['port']} is already used by tunnel {$what} on this firewall. Use \"Adopt an existing tunnel\" below to use it, or choose another port. Nothing was changed.");
            }
            $r = $driver->setup(['listen_port' => (int) $v['port'], 'subnet' => $v['subnet'], 'lan' => $v['lan'], 'wan' => $v['wan']]);
        } catch (\Throwable $e) {
            $site->update(['status' => 'error', 'last_error' => $e->getMessage()]);
            return redirect()->route('vpn.site', [$company, $site])->with('error', 'Setup stopped: ' . $e->getMessage());
        }
        $site->update([
            'status' => 'ready', 'tunnel' => $r['ref'], 'tunnel_name' => $r['name'], 'server_public_key' => $r['public_key'],
            'subnet' => $v['subnet'], 'endpoint' => "{$v['endpoint_host']}:{$v['port']}", 'dns' => $r['gateway'] ?: WireGuard::firstHost($v['subnet']), 'last_error' => null,
        ]);
        VpnAuditLog::record($company->id, $this->actor($request), "wireguard_created {$site->firewall->name} {$r['name']} {$v['subnet']}");
        return redirect()->route('vpn.site', [$company, $site])->with('success', 'WireGuard is ready on this firewall.')->with('vpn_log', $r['log']);
    }

    public function adopt(Request $request, Company $company, VpnSite $site)
    {
        $this->authorizeManage($request, $company);
        $site = $this->siteOf($company, $site);
        $v = $request->validate([
            'tunnel' => ['required', 'string', 'max:64'],
            'subnet' => ['required', 'string', 'max:20'],
            'endpoint' => ['required', 'regex:/^[A-Za-z0-9.-]{1,120}:\d{2,5}$/'],
            'dns' => ['required', 'regex:/^[0-9., ]{7,100}$/'],
        ]);
        try {
            WireGuard::parseSubnet($v['subnet']);
            $t = collect($this->vpn->driver($site)->tunnels())->firstWhere('ref', $v['tunnel']);
            if (!$t || !WireGuard::isKey($t['public_key'])) {
                throw new VpnException('That tunnel wasn\'t found on the firewall.');
            }
        } catch (\Throwable $e) {
            return back()->with('error', 'Couldn\'t adopt: ' . $e->getMessage());
        }
        $site->update(['status' => 'ready', 'tunnel' => $t['ref'], 'tunnel_name' => $t['name'], 'server_public_key' => $t['public_key'], 'subnet' => $v['subnet'], 'endpoint' => $v['endpoint'], 'dns' => $v['dns'], 'last_error' => null]);
        VpnAuditLog::record($company->id, $this->actor($request), "wireguard_adopted {$site->firewall->name} {$t['name']} {$v['subnet']}");
        return redirect()->route('vpn.site', [$company, $site])->with('success', 'Tunnel adopted. People with VPN access can add devices now.');
    }

    public function settings(Request $request, Company $company, VpnSite $site)
    {
        $this->authorizeManage($request, $company);
        $site = $this->siteOf($company, $site);
        $v = $request->validate([
            'endpoint' => ['required', 'regex:/^[A-Za-z0-9.-]{1,120}:\d{2,5}$/'],
            'dns' => ['required', 'regex:/^[0-9., ]{7,100}$/'],
        ]);
        $site->update($v);
        VpnAuditLog::record($company->id, $this->actor($request), "site_settings {$site->firewall->name} {$v['endpoint']}");
        return back()->with('success', 'Saved. New devices use these settings.');
    }

    /** A support ticket from the Windows app: the message and the full diagnostic report (as text, or ?download=1). */
    public function supportReport(Request $request, Company $company, int $report)
    {
        $r = \App\Models\Vpn\VpnSupportReport::where('company_id', $company->id)->findOrFail($report);
        if ($request->boolean('download')) {
            return response($r->report, 200, [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="' . $r->reference() . '-diagnostics.txt"',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }
        return view('vpn.admin.support-report', ['company' => $company, 'r' => $r]);
    }

    public function destroySite(Request $request, Company $company, VpnSite $site)
    {
        $this->authorizeManage($request, $company);
        $site = $this->siteOf($company, $site);
        // Only devices that can still connect block this; switched-off ones ("being removed") are finished here.
        $active = $site->devices()->whereNull('revoked_at')->with('user')->get();
        if ($active->isNotEmpty()) {
            return back()->with('error', 'Remove this firewall\'s devices first (or the people who own them): '
                . $active->map(fn ($d) => $d->name . ($d->user ? " ({$d->user->upn})" : ''))->join(', ') . '.');
        }
        $leftover = '';
        $stale = $site->devices()->pluck('id');
        if ($stale->isNotEmpty()) {
            try {
                $this->vpn->clearRevoked($site);
            } catch (\Throwable $e) {
                $leftover = ' The firewall didn\'t answer, so these old WireGuard peers may still be on it — delete them there by hand: '
                    . $stale->map(fn ($id) => "cfbvpn-{$id}")->join(', ') . '.';
            }
        }
        $name = $site->firewall->name;
        $site->delete();
        VpnAuditLog::record($company->id, $this->actor($request), "site_removed {$name}" . ($stale->isNotEmpty() ? " ({$stale->count()} switched-off device(s) cleared)" : ''));
        return redirect()->route('vpn.company', $company)->with($leftover ? 'warning' : 'success', "VPN turned off for {$name} in PFCentral. The WireGuard tunnel on the firewall was left as it is." . $leftover);
    }
}
