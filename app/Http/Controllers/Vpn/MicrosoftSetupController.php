<?php

namespace App\Http\Controllers\Vpn;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Vpn\VpnAuditLog;
use App\Models\Vpn\VpnCompany;
use App\Models\Vpn\VpnUser;
use App\Services\Auth\MspSignIn;
use App\Services\Vpn\MicrosoftSetup;
use App\Services\Vpn\VpnException;
use App\Services\Vpn\VpnSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * "Connect Microsoft 365 automatically", on the VPN page's host so the link also works for a
 * company's own IT admin who has no PFCentral login:
 *
 *   GET  /vpn/setup?t=…        what will happen, and a "Continue to Microsoft" button
 *   POST /vpn/setup            → Microsoft sign-in + consent with CFBTEL's setup app
 *   GET  /vpn/setup/callback   creates the company's "CFBTEL VPN" app and saves it on the company
 *
 * The link (t) is encrypted, names one company, works 7 days, and stops working once a setup has finished.
 */
class MicrosoftSetupController extends Controller
{
    private const PENDING = 'vpn_ms_setup';
    public const MSP_APP_NAME = 'PFCentral sign-in';
    public const LINK_DAYS = 7;

    /** The setup link for a company (shown on its VPN page in the console). */
    public static function link(Company $company, string $by): string
    {
        $now = now()->getTimestamp();
        $t = Crypt::encryptString(json_encode(['c' => $company->id, 'iat' => $now, 'exp' => $now + self::LINK_DAYS * 86400, 'by' => $by]));
        return VpnSettings::portalUrl('/vpn/setup') . '?' . http_build_query(['t' => $t]);
    }

    /**
     * The one-time link for setting up PFCentral's own "Sign in with Microsoft 365" (Sign-in settings).
     * It runs through this same page so it can use the setup app's registered redirect address.
     */
    public static function mspLink(string $by, string $callbackUri, string $backUrl): string
    {
        $now = now()->getTimestamp();
        // "ms" (milliseconds) makes the link single-use even when a new one is made in the same second.
        $t = Crypt::encryptString(json_encode(['msp' => 1, 'iat' => $now, 'ms' => (int) floor(microtime(true) * 1000), 'exp' => $now + 3600, 'by' => $by, 'cb' => $callbackUri, 'back' => $backUrl]));

        return VpnSettings::portalUrl('/vpn/setup') . '?' . http_build_query(['t' => $t]);
    }

    /** The MSP sign-in setup link's data, or null when it is not one, expired or already used. */
    private function mspFromLink(?string $t): ?array
    {
        try {
            $data = json_decode(Crypt::decryptString((string) $t), true);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($data) || empty($data['msp']) || ($data['exp'] ?? 0) < now()->getTimestamp()) {
            return null;
        }
        $done = (int) MspSignIn::get('auto_setup_ms');
        if ($done && (int) ($data['ms'] ?? 0) <= $done) {
            return null;
        }

        return $data;
    }

    /** @return array{0: Company, 1: VpnCompany, 2: array}|null */
    private function fromLink(?string $t): ?array
    {
        try {
            $data = json_decode(Crypt::decryptString((string) $t), true);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($data) || ($data['exp'] ?? 0) < now()->getTimestamp() || !($company = Company::find($data['c'] ?? 0))) {
            return null;
        }
        $vc = VpnCompany::forCompany($company);
        if ($vc->ms_auto_setup_at && (int) ($data['iat'] ?? 0) <= $vc->ms_auto_setup_at->getTimestamp()) {
            return null; // already used
        }
        return [$company, $vc, $data];
    }

    private function page(string $title, string $text, int $status = 200, array $extra = [])
    {
        return response()->view('vpn.portal.setup', compact('title', 'text') + $extra, $status);
    }

    private function expired()
    {
        return $this->page('Link expired', 'This setup link has expired, was already used, or is invalid. Ask CFBTEL for a new one.', 400);
    }

    public function show(Request $request)
    {
        if ($msp = $this->mspFromLink($request->query('t'))) {
            if (!MicrosoftSetup::make()->configured()) {
                return $this->page('Not available yet', 'One-click Microsoft 365 setup is not configured yet (VPN Access → settings: setup app).', 503);
            }

            return $this->page('Set up Microsoft 365 sign-in for ' . config('brand.name'), '', 200, [
                'confirmMsp' => true, 't' => (string) $request->query('t'), 'appName' => self::MSP_APP_NAME, 'back' => $msp['back'] ?? null,
            ]);
        }
        $link = $this->fromLink($request->query('t'));
        if (!$link) {
            return $this->expired();
        }
        [$company, $vc] = $link;
        if (!MicrosoftSetup::make()->configured()) {
            return $this->page('Not available yet', 'One-click Microsoft 365 setup isn\'t configured at CFBTEL yet. Contact CFBTEL support.', 503);
        }
        return $this->page("Connect {$company->name} to CFBTEL VPN", '', 200, [
            'confirm' => true, 't' => (string) $request->query('t'), 'company' => $company, 'vc' => $vc,
            'appName' => MicrosoftSetup::APP_NAME,
        ]);
    }

    public function start(Request $request)
    {
        $link = $this->mspFromLink($request->input('t')) ?? $this->fromLink($request->input('t'));
        if (!$link) {
            return $this->expired();
        }
        try {
            $auth = MicrosoftSetup::make()->authorizeUrl();
        } catch (VpnException $e) {
            return $this->page('Not available yet', $e->getMessage(), 503);
        }
        $request->session()->put(self::PENDING, $auth['pending'] + ['t' => (string) $request->input('t'), 'exp' => time() + 15 * 60]);
        return redirect()->away($auth['url']);
    }

    public function callback(Request $request)
    {
        $pending = $request->session()->pull(self::PENDING);
        if (!is_array($pending) || ($pending['exp'] ?? 0) < time() || !hash_equals((string) ($pending['state'] ?? ''), (string) $request->query('state', ''))) {
            return $this->page('Setup expired', 'This setup took too long or was started in another browser. Open the setup link again.', 400);
        }
        if ($msp = $this->mspFromLink($pending['t'] ?? null)) {
            return $this->mspCallback($request, $pending, $msp);
        }
        $link = $this->fromLink($pending['t'] ?? null);
        if (!$link) {
            return $this->expired();
        }
        [$company, $vc, $data] = $link;
        if ($request->query('error')) {
            $why = strtok((string) $request->query('error_description', 'The sign-in was cancelled.'), "\r\n");
            return $this->page('Not connected', "Microsoft didn't allow the setup: {$why}", 400, ['retry' => $pending['t']]);
        }

        $setup = MicrosoftSetup::make();
        try {
            $admin = $setup->redeem((string) $request->query('code', ''), $pending);
        } catch (\Throwable $e) {
            Log::warning('VPN setup: Microsoft sign-in failed', ['company' => $company->id, 'error' => $e->getMessage()]);
            return $this->page('Not connected', 'Microsoft sign-in failed. Please try again.', 400, ['retry' => $pending['t']]);
        }
        $tid = $admin['tid'];
        if (VpnCompany::where('ms_tenant_id', $tid)->where('id', '!=', $vc->id)->exists()) {
            return $this->page('Already connected', 'This Microsoft 365 tenant is already linked to another company. Contact CFBTEL.', 409);
        }
        if ($vc->ms_tenant_id && $vc->ms_tenant_id !== $tid && VpnUser::where('company_id', $company->id)->where('auth_type', 'microsoft')->exists()) {
            return $this->page('Different tenant', "{$company->name} is already linked to another Microsoft 365 tenant and has VPN people from it. You signed in as {$admin['upn']}. Contact CFBTEL.", 409);
        }

        try {
            try {
                $org = $setup->organization();
            } catch (\Throwable $e) {
                // The name and domains are a convenience: carry on without them.
                Log::notice('VPN setup: organization not readable', ['company' => $company->id, 'error' => $e->getMessage()]);
                $org = ['name' => null, 'domains' => []];
            }
            $reuse = $vc->ms_tenant_id === $tid ? $vc->ms_client_id : null;
            $app = $setup->provision(VpnSettings::portalUrl('/vpn/callback'), $reuse);
        } catch (\Throwable $e) {
            Log::warning('VPN setup failed', ['company' => $company->id, 'tid' => $tid, 'error' => $e->getMessage()]);
            VpnAuditLog::record($company->id, $admin['upn'], 'microsoft_auto_setup_failed');
            $msg = $e instanceof VpnException ? $e->getMessage() : 'Microsoft returned an error.';
            return $this->page('Not connected', $msg, 502, ['retry' => $pending['t']]);
        }

        // Email domains: keep the ones already listed, add the tenant's verified domains no other company uses.
        $taken = VpnCompany::where('id', '!=', $vc->id)->get()->flatMap(fn ($o) => $o->domains())->all();
        $domains = array_values(array_unique(array_merge($vc->domains(), array_diff($org['domains'], $taken))));
        $oldKey = ($vc->ms_client_id === $app['client_id']) ? $vc->ms_secret_key_id : null;

        $vc->fill([
            'ms_tenant_id' => $tid,
            'ms_tenant_name' => $org['name'] ?? $vc->ms_tenant_name,
            'ms_client_id' => $app['client_id'],
            'ms_client_secret' => $app['client_secret'],
            'ms_app_object_id' => $app['object_id'],
            'ms_secret_key_id' => $app['key_id'],
            'ms_secret_expires_at' => $app['expires_at'],
            'ms_auto_setup_at' => now(),
            'email_domains' => $domains ? implode(',', $domains) : null,
        ])->save();

        if ($oldKey && $oldKey !== $app['key_id']) {
            $setup->removeSecret($app['object_id'], $oldKey);
        }
        $cleaned = $setup->removeSetupAccess();
        VpnAuditLog::record($company->id, $admin['upn'], "microsoft_auto_setup " . ($app['reused'] ? 'renewed' : 'created') . " app {$app['client_id']} in {$tid}" . ($cleaned ? '' : ' (setup access not removed)'));

        return $this->page('Microsoft 365 connected', '', 200, [
            'done' => true, 'company' => $company, 'vc' => $vc, 'admin' => $admin, 'cleaned' => $cleaned, 'reused' => $app['reused'],
        ]);
    }
    /** Create (or renew) the "PFCentral sign-in" app in the MSP's own tenant and save it in Sign-in settings. */
    private function mspCallback(Request $request, array $pending, array $msp)
    {
        $back = $msp['back'] ?? null;
        if ($request->query('error')) {
            $why = strtok((string) $request->query('error_description', 'The sign-in was cancelled.'), "\r\n");

            return $this->page('Not set up', "Microsoft didn't allow the setup: {$why}", 400, ['retry' => $pending['t'], 'back' => $back]);
        }
        $setup = MicrosoftSetup::make();
        try {
            $admin = $setup->redeem((string) $request->query('code', ''), $pending);
            $tid = strtolower($admin['tid']);
            $reuse = strtolower((string) MspSignIn::tenantId()) === $tid ? MspSignIn::clientId() : null;
            $app = $setup->provision((string) $msp['cb'], $reuse, self::MSP_APP_NAME,
                'Created by PFCentral (CFBTEL) for "Sign in with Microsoft 365" to the PFCentral console. Deleting it stops that sign-in.');
        } catch (\Throwable $e) {
            Log::warning('PFCentral sign-in setup failed', ['error' => $e->getMessage()]);

            return $this->page('Not set up', $e instanceof VpnException ? $e->getMessage() : 'Microsoft returned an error. Please try again.', 502, ['retry' => $pending['t'], 'back' => $back]);
        }

        $oldKey = MspSignIn::clientId() === $app['client_id'] ? MspSignIn::get('secret_key_id') : null;
        if ($reuse === null && MspSignIn::tenantId() && strtolower((string) MspSignIn::tenantId()) !== $tid) {
            MspSignIn::setGroups([]); // groups belong to the old tenant
        }
        MspSignIn::set('tenant_id', $tid);
        MspSignIn::set('client_id', $app['client_id']);
        MspSignIn::setClientSecret($app['client_secret']);
        MspSignIn::set('app_object_id', $app['object_id']);
        MspSignIn::set('secret_key_id', $app['key_id']);
        MspSignIn::set('secret_expires_at', $app['expires_at']->toIso8601String());
        MspSignIn::set('auto_setup_at', (string) now()->getTimestamp());
        MspSignIn::set('auto_setup_ms', (string) (int) floor(microtime(true) * 1000));
        if ($oldKey && $oldKey !== $app['key_id']) {
            $setup->removeSecret($app['object_id'], $oldKey);
        }
        $cleaned = $setup->removeSetupAccess();
        Log::info('PFCentral sign-in app ' . ($app['reused'] ? 'renewed' : 'created'), ['tid' => $tid, 'by' => $admin['upn'], 'app' => $app['client_id']]);

        return $this->page('Microsoft 365 sign-in is set up', '', 200, [
            'doneMsp' => true, 'admin' => $admin, 'reused' => $app['reused'], 'cleaned' => $cleaned,
            'appName' => self::MSP_APP_NAME, 'expires' => $app['expires_at'], 'back' => $back,
        ]);
    }
}
