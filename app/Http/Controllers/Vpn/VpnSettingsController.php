<?php

namespace App\Http\Controllers\Vpn;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Vpn\VpnCompany;
use App\Models\Vpn\VpnSite;
use App\Models\Vpn\VpnUser;
use App\Services\Vpn\Microsoft;
use App\Services\Vpn\VpnSettings;
use Illuminate\Http\Request;

/** Global admins: the Microsoft 365 app, the staff page host name, the device limit, and every company's VPN at a glance. */
class VpnSettingsController extends Controller
{
    public function index()
    {
        return view('vpn.admin.settings', [
            'clientId' => VpnSettings::clientId(),
            'hasSecret' => VpnSettings::hasClientSecret(),
            'portalHost' => VpnSettings::portalHost(),
            'maxDevices' => VpnSettings::maxDevices(),
            'proxyIps' => implode(', ', VpnSettings::proxyIps()),
            'mail' => [
                'smtp_host' => VpnSettings::get('smtp_host'),
                'smtp_port' => VpnSettings::get('smtp_port'),
                'smtp_encryption' => VpnSettings::get('smtp_encryption', 'tls'),
                'smtp_username' => VpnSettings::get('smtp_username'),
                'has_password' => VpnSettings::secret('smtp_password') !== null,
                'mail_from_address' => VpnSettings::get('mail_from_address'),
                'mail_from_name' => VpnSettings::get('mail_from_name'),
                'support_email' => \App\Http\Controllers\Vpn\AppSupportController::supportAddress(),
                'invite_subject' => VpnSettings::inviteSubject(),
                'invite_body' => VpnSettings::inviteBody(),
            ],
            'setupClientId' => VpnSettings::get('setup_client_id'),
            'setupHasSecret' => VpnSettings::secret('setup_client_secret') !== null,
            'setupRedirect' => \App\Services\Vpn\MicrosoftSetup::redirectUri(),
            'redirects' => [VpnSettings::portalUrl('/vpn/callback'), VpnSettings::portalUrl('/vpn/consent/callback')],
            'companies' => Company::orderBy('name')->get()->map(fn ($c) => [
                'company' => $c,
                'tenant' => VpnCompany::where('company_id', $c->id)->value('ms_tenant_name') ?? (VpnCompany::where('company_id', $c->id)->value('ms_tenant_id') ? 'connected' : null),
                'sites' => VpnSite::where('company_id', $c->id)->where('status', 'ready')->count(),
                'people' => VpnUser::where('company_id', $c->id)->count(),
            ]),
        ]);
    }

    public function updateEmail(Request $request)
    {
        $v = $request->validate([
            'smtp_host' => ['nullable', 'regex:/^[A-Za-z0-9.-]{1,190}$/'],
            'smtp_port' => ['nullable', 'integer', 'between:1,65535'],
            'smtp_encryption' => ['required', 'in:tls,ssl,none'],
            'smtp_username' => ['nullable', 'string', 'max:190'],
            'smtp_password' => ['nullable', 'string', 'max:300'],
            'mail_from_address' => ['nullable', 'email', 'max:190'],
            'mail_from_name' => ['nullable', 'string', 'max:120'],
            'support_email' => ['nullable', 'email', 'max:190'],
            'invite_subject' => ['required', 'string', 'max:200'],
            'invite_body' => ['required', 'string', 'max:5000'],
        ]);
        if (!str_contains($v['invite_body'], '{link}')) {
            return back()->withErrors(['invite_body' => 'The invitation must contain {link}, the set-password link.'])->withInput();
        }
        foreach (['smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'mail_from_address', 'mail_from_name', 'support_email', 'invite_subject', 'invite_body'] as $k) {
            VpnSettings::set($k, isset($v[$k]) ? (string) $v[$k] : '');
        }
        if (!empty($v['smtp_password'])) {
            VpnSettings::set('smtp_password', $v['smtp_password']); // blank keeps the saved password
        }
        return redirect()->route('vpn.settings')->with('success', 'Email settings saved.');
    }

    public function testEmail(Request $request, \App\Services\Vpn\VpnMailer $mailer)
    {
        $to = $request->user()->email;
        try {
            $mailer->send($to, 'CFBTEL VPN test email', "This is a test from PFCentral's VPN email settings.\n\nIf you can read this, invitations will be delivered.");
        } catch (\Throwable $e) {
            return redirect()->route('vpn.settings')->with('error', 'Sending failed: ' . $e->getMessage());
        }
        return redirect()->route('vpn.settings')->with('success', "Test email sent to {$to}.");
    }

    /** CFBTEL's setup app for one-click Microsoft 365 setup. */
    public function updateSetup(Request $request)
    {
        $v = $request->validate([
            'setup_client_id' => ['nullable', 'string', 'max:36'],
            'setup_client_secret' => ['nullable', 'string', 'max:200'],
        ]);
        $id = strtolower(trim((string) ($v['setup_client_id'] ?? '')));
        if ($id !== '' && !Microsoft::isGuid($id)) {
            return back()->withErrors(['setup_client_id' => 'The application (client) ID looks like 00000000-0000-0000-0000-000000000000.'])->withInput();
        }
        if ($id !== '' && $id === VpnSettings::clientId()) {
            return back()->withErrors(['setup_client_id' => 'Use a separate app registration for setup, not the shared sign-in app.'])->withInput();
        }
        VpnSettings::set('setup_client_id', $id);
        if (!empty($v['setup_client_secret'])) {
            VpnSettings::set('setup_client_secret', $v['setup_client_secret']); // blank keeps the saved secret
        }
        if ($id === '') {
            VpnSettings::set('setup_client_secret', '');
        }
        return redirect()->route('vpn.settings')->with('success', 'One-click setup app saved.');
    }

    public function update(Request $request)
    {
        $v = $request->validate([
            'ms_client_id' => ['nullable', 'string', 'max:36'],
            'ms_client_secret' => ['nullable', 'string', 'max:200'],
            'portal_host' => ['nullable', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i', 'max:120'],
            'max_devices' => ['required', 'integer', 'between:1,50'],
            'proxy_ips' => ['nullable', 'string', 'max:300'],
        ]);
        $proxies = array_values(array_filter(array_map('trim', explode(',', (string) ($v['proxy_ips'] ?? '')))));
        foreach ($proxies as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                return back()->withErrors(['proxy_ips' => "“{$ip}” isn't an IP address."])->withInput();
            }
        }
        if (!empty($v['ms_client_id']) && !Microsoft::isGuid($v['ms_client_id'])) {
            return back()->withErrors(['ms_client_id' => 'The application (client) ID looks like 00000000-0000-0000-0000-000000000000.'])->withInput();
        }
        $host = strtolower((string) ($v['portal_host'] ?? ''));
        $adminHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        if ($host !== '' && $adminHost && strcasecmp($host, $adminHost) === 0) {
            return back()->withErrors(['portal_host' => 'Use a separate name (e.g. vpn.example.com): only the VPN pages are served on it.'])->withInput();
        }
        VpnSettings::set('ms_client_id', strtolower((string) ($v['ms_client_id'] ?? '')));
        if (!empty($v['ms_client_secret'])) {
            VpnSettings::set('ms_client_secret', $v['ms_client_secret']); // blank keeps the saved secret
        }
        VpnSettings::set('portal_host', $host);
        VpnSettings::set('max_devices', (string) $v['max_devices']);
        VpnSettings::set('proxy_ips', implode(',', $proxies));
        return redirect()->route('vpn.settings')->with('success', 'VPN settings saved.');
    }
}
