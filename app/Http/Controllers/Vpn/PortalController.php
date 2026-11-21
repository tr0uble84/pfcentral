<?php

namespace App\Http\Controllers\Vpn;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Vpn\VpnAuditLog;
use App\Models\Vpn\VpnCompany;
use App\Models\Vpn\VpnDevice;
use App\Models\Vpn\VpnSite;
use App\Models\Vpn\VpnUser;
use App\Services\Vpn\Microsoft;
use App\Services\Vpn\VpnException;
use App\Services\Vpn\VpnManager;
use App\Services\Vpn\VpnMfa;
use App\Services\Vpn\VpnSettings;
use App\Services\Vpn\WireGuard;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * The staff page (e.g. https://vpn.example.com/vpn): people a company admin gave VPN access sign in with
 * their own Microsoft 365 account and add or remove their devices. It never shows the PFCentral console.
 */
class PortalController extends Controller
{
    private const SESSION = 'vpn_portal';
    private const PENDING = 'vpn_portal_login';
    private const MFA_PENDING = 'vpn_portal_mfa';       // password OK, two-factor still to do
    private const MFA_SECRET = 'vpn_portal_mfa_secret'; // the secret shown on the setup page until confirmed
    public const PLATFORMS = ['windows' => 'Windows PC', 'macos' => 'Mac', 'iphone' => 'iPhone / iPad', 'android' => 'Android'];

    public function __construct(private VpnManager $vpn, private \App\Services\Vpn\VpnAccounts $accounts, private \App\Services\Vpn\VpnAccess $access)
    {
    }


    /** The signed-in VPN person, re-read from the database (access may have been removed since). */
    public function person(Request $request): ?VpnUser
    {
        $s = $request->session()->get(self::SESSION);
        if (!is_array($s) || ($s['exp'] ?? 0) < time()) {
            return null;
        }
        $user = VpnUser::find($s['id'] ?? 0);
        if (!$user || $user->isBlocked()) {
            return null;
        }
        if ($user->isLocal()) {
            // A password change (or reset) signs out older sessions.
            if (($s['auth'] ?? '') !== 'local' || !$user->password || !hash_equals($user->passwordVersion(), (string) ($s['pv'] ?? ''))) {
                return null;
            }
            // Two-factor made required since this session started: sign in again (and set it up).
            return VpnMfa::required($user) && !VpnMfa::enabled($user) ? null : $user;
        }
        return ($s['auth'] ?? 'microsoft') === 'microsoft' && $user->ms_oid === ($s['oid'] ?? null) ? $user : null;
    }

    private function startSession(Request $request, VpnUser $user, string $how)
    {
        return redirect($this->beginSession($request, $user, $how));
    }

    /** Signs the person in and returns where to go next. */
    private function beginSession(Request $request, VpnUser $user, string $how): string
    {
        $request->session()->forget([self::MFA_PENDING, self::MFA_SECRET]);
        $request->session()->regenerate();
        $request->session()->put(self::SESSION, [
            'id' => $user->id,
            'auth' => $user->isLocal() ? 'local' : 'microsoft',
            'oid' => $user->ms_oid,
            'pv' => $user->isLocal() ? $user->passwordVersion() : null,
            'exp' => time() + 30 * 60,
        ]);
        $user->update(['last_login_at' => now()]);
        VpnAuditLog::record($user->company_id, $user->upn, "signed_in ({$how})");
        // Back to where the sign-in started, e.g. the CFBTEL VPN app's "Connect this computer" page.
        $to = (string) $request->session()->pull('vpn_intended', '');
        return str_starts_with($to, '/vpn/app?') ? $to : '/vpn';
    }

    // ---- two-factor sign-in (email-and-password accounts) ----

    /** After a correct password: ask for the authenticator code, or set it up first when it's required. */
    private function afterPassword(Request $request, VpnUser $user, string $how)
    {
        if (!VpnMfa::enabled($user) && !VpnMfa::required($user)) {
            return $this->startSession($request, $user, $how);
        }
        $request->session()->regenerate();
        $request->session()->put(self::MFA_PENDING, ['id' => $user->id, 'pv' => $user->passwordVersion(), 'how' => $how, 'exp' => time() + 600]);
        return redirect(VpnMfa::enabled($user) ? '/vpn/mfa' : '/vpn/mfa/setup');
    }

    private function pendingMfaUser(Request $request): ?VpnUser
    {
        $p = $request->session()->get(self::MFA_PENDING);
        if (!is_array($p) || ($p['exp'] ?? 0) < time()) {
            return null;
        }
        $user = VpnUser::find($p['id'] ?? 0);
        return $user && $user->isLocal() && $user->password && hash_equals($user->passwordVersion(), (string) ($p['pv'] ?? '')) ? $user : null;
    }

    public function mfaForm(Request $request)
    {
        $user = $this->pendingMfaUser($request);
        if (!$user) {
            return redirect('/vpn');
        }
        if (!VpnMfa::enabled($user)) {
            return redirect('/vpn/mfa/setup');
        }
        return response()->view('vpn.portal.mfa', ['account' => $user, 'error' => null]);
    }

    public function mfaVerify(Request $request)
    {
        $user = $this->pendingMfaUser($request);
        if (!$user || !VpnMfa::enabled($user)) {
            return redirect('/vpn')->with('vpn_error', 'Please sign in again.');
        }
        if ($user->locked_until && $user->locked_until->isFuture()) {
            $request->session()->forget(self::MFA_PENDING);
            return response()->view('vpn.portal.password', ['email' => $user->upn, 'error' => 'Too many wrong codes. Try again in ' . \App\Services\Vpn\VpnAccounts::LOCK_MINUTES . ' minutes.'], 429);
        }
        $how = VpnMfa::verify($user, (string) $request->input('code', ''));
        if (!$how) {
            $fails = $user->failed_logins + 1;
            $lock = $fails >= \App\Services\Vpn\VpnAccounts::MAX_FAILURES;
            $user->update(['failed_logins' => $lock ? 0 : $fails, 'locked_until' => $lock ? now()->addMinutes(\App\Services\Vpn\VpnAccounts::LOCK_MINUTES) : null]);
            if ($lock) {
                VpnAuditLog::record($user->company_id, 'system', "account_locked {$user->upn} (too many wrong two-factor codes)");
                $request->session()->forget(self::MFA_PENDING);
                return response()->view('vpn.portal.password', ['email' => $user->upn, 'error' => 'Too many wrong codes. Try again in ' . \App\Services\Vpn\VpnAccounts::LOCK_MINUTES . ' minutes.'], 429);
            }
            return response()->view('vpn.portal.mfa', ['account' => $user, 'error' => 'That code didn\'t work. Use the current 6-digit code from your authenticator app (each code works once), or a recovery code.'], 422);
        }
        $p = $request->session()->get(self::MFA_PENDING);
        $user->update(['failed_logins' => 0]);
        return $this->startSession($request, $user, ($p['how'] ?? 'password') . ($how === 'app' ? ' + authenticator' : ' + recovery code'));
    }

    /** @return array{0: ?VpnUser, 1: bool} the person and whether this is during sign-in (true) or from the staff page */
    private function mfaSetupUser(Request $request): array
    {
        if ($u = $this->pendingMfaUser($request)) {
            return [$u, true];
        }
        $u = $this->person($request);
        return $u && $u->isLocal() ? [$u, false] : [null, false];
    }

    public function mfaSetupForm(Request $request)
    {
        [$user, $inLogin] = $this->mfaSetupUser($request);
        if (!$user) {
            return redirect('/vpn');
        }
        if (VpnMfa::enabled($user)) {
            return redirect($inLogin ? '/vpn/mfa' : '/vpn');
        }
        return $this->mfaSetupPage($request, $user, $inLogin, null);
    }

    private function mfaSetupPage(Request $request, VpnUser $user, bool $inLogin, ?string $error)
    {
        $s = $request->session()->get(self::MFA_SECRET);
        if (!is_array($s) || ($s['id'] ?? 0) !== $user->id) {
            $s = ['id' => $user->id, 'secret' => VpnMfa::newSecret()];
            $request->session()->put(self::MFA_SECRET, $s);
        }
        return response()->view('vpn.portal.mfa-setup', [
            'user' => $inLogin ? null : $user,
            'account' => $user,
            'qr' => VpnMfa::qrSvg($user, $s['secret']),
            'secret' => trim(chunk_split($s['secret'], 4, ' ')),
            'required' => VpnMfa::required($user),
            'error' => $error,
        ], $error ? 422 : 200);
    }

    public function mfaSetup(Request $request)
    {
        [$user, $inLogin] = $this->mfaSetupUser($request);
        if (!$user) {
            return redirect('/vpn')->with('vpn_error', 'Please sign in again.');
        }
        if (VpnMfa::enabled($user)) {
            return redirect($inLogin ? '/vpn/mfa' : '/vpn');
        }
        $s = $request->session()->get(self::MFA_SECRET);
        $step = is_array($s) && ($s['id'] ?? 0) === $user->id ? VpnMfa::check($s['secret'], (string) $request->input('code', ''), null) : null;
        if (!$step) {
            return $this->mfaSetupPage($request, $user, $inLogin, 'That code didn\'t match. Check that the app shows "CFBTEL VPN" and type the 6-digit code it shows now.');
        }
        $codes = VpnMfa::enable($user, $s['secret'], $step);
        $request->session()->forget(self::MFA_SECRET);
        $next = '/vpn';
        if ($inLogin) {
            $p = $request->session()->get(self::MFA_PENDING);
            $next = $this->beginSession($request, $user->fresh(), ($p['how'] ?? 'password') . ' + new authenticator');
        }
        return response()->view('vpn.portal.mfa-codes', ['user' => $user->fresh(), 'codes' => $codes, 'next' => $next]);
    }

    /** Staff page: turn two-factor off (only when the company doesn't require it), with a current code. */
    public function mfaDisable(Request $request)
    {
        $user = $this->person($request);
        if (!$user || !$user->isLocal()) {
            return redirect('/vpn')->with('vpn_error', 'Please sign in again.');
        }
        if (VpnMfa::required($user)) {
            return $this->devicesPage($user, ['error', 'Your company requires two-factor sign-in, so it can\'t be turned off.'], 403);
        }
        if (!VpnMfa::verify($user, (string) $request->input('code', ''))) {
            return $this->devicesPage($user, ['error', 'That code didn\'t work. Type the current code from your authenticator app to turn two-factor off.'], 422);
        }
        VpnMfa::disable($user, $user->upn);
        return $this->devicesPage($user->fresh(), ['ok', 'Two-factor sign-in is off. You can turn it on again here any time.']);
    }

    private function onPortalHost(Request $request): ?\Illuminate\Http\RedirectResponse
    {
        $host = VpnSettings::portalHost();
        if ($host && strcasecmp($request->getHost(), $host) !== 0 && $request->isMethod('GET')) {
            return redirect()->away(VpnSettings::portalUrl('/' . ltrim($request->path(), '/')));
        }
        return null;
    }

    private function blockedMessage(VpnUser $user)
    {
        VpnAuditLog::record($user->company_id, $user->upn, 'sign_in_refused (blocked)');
        return $this->message('VPN access blocked', 'Your VPN access is blocked. Contact your company\'s IT admin or CFBTEL support.', 403);
    }

    private function message(string $title, string $text, int $status = 200, ?VpnUser $user = null)
    {
        return response()->view('vpn.portal.message', compact('title', 'text', 'user'), $status);
    }

    public function index(Request $request)
    {
        if ($r = $this->onPortalHost($request)) {
            return $r;
        }
        $user = $this->person($request);
        if (!$user) {
            return response()->view('vpn.portal.login', ['error' => session('vpn_error')]);
        }
        return $this->devicesPage($user);
    }

    private function devicesPage(VpnUser $user, ?array $notice = null, int $status = 200)
    {
        $sites = $this->access->sitesFor($user);
        $devices = $user->activeDevices()->with('site.firewall')->orderBy('created_at')->get();
        return response()->view('vpn.portal.devices', [
            'user' => $user,
            'company' => Company::find($user->company_id),
            'sites' => $sites,
            'devices' => $devices,
            'max' => VpnSettings::maxDevices(),
            'platforms' => self::PLATFORMS,
            'notice' => $notice,
            'noGroup' => $sites->isEmpty() && $this->vpn->readySites($user->company_id)->isNotEmpty(),
            'mfaOn' => VpnMfa::enabled($user),
            'mfaRequired' => VpnMfa::required($user),
            'recoveryLeft' => count((array) ($user->mfa_recovery ?? [])),
        ], $status);
    }

    public function help()
    {
        return response()->view('vpn.portal.help', ['platforms' => self::PLATFORMS]);
    }

    /** Step 1: the work email picks the company (by its email domains) and so its Microsoft app. */
    public function login(Request $request)
    {
        if ($request->isMethod('GET')) {
            return redirect('/vpn');
        }
        $email = strtolower(trim((string) $request->input('email')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return redirect('/vpn')->with('vpn_error', 'Enter your work email address.');
        }
        // People with an email-and-password VPN account get the password step instead of Microsoft.
        if (VpnUser::findLocalByEmail($email)) {
            return response()->view('vpn.portal.password', ['email' => $email, 'error' => null]);
        }
        $vc = VpnCompany::forEmail($email);
        $own = $vc && $vc->hasOwnApp() ? $vc : null;
        try {
            $login = Microsoft::for($own)->loginUrl(VpnSettings::portalUrl('/vpn/callback'), $email);
        } catch (VpnException $e) {
            return $this->message('Not available yet', 'Your organization isn\'t set up for CFBTEL VPN yet. Contact your IT admin or CFBTEL support.', 503);
        }
        $request->session()->put(self::PENDING, $login['pending'] + ['exp' => time() + 600, 'company' => $own?->id]);
        return redirect()->away($login['url']);
    }

    public function callback(Request $request)
    {
        $pending = $request->session()->pull(self::PENDING);
        if ($request->query('error')) {
            return redirect('/vpn')->with('vpn_error', strtok((string) $request->query('error_description', 'Microsoft sign-in was cancelled.'), "\r\n"));
        }
        if (!is_array($pending) || ($pending['exp'] ?? 0) < time() || !hash_equals((string) $pending['state'], (string) $request->query('state', ''))) {
            return redirect('/vpn')->with('vpn_error', 'Your sign-in expired. Please try again.');
        }
        // Redeem with the same app the sign-in started with (the company's own app, or CFBTEL's shared app).
        $own = !empty($pending['company']) ? VpnCompany::find($pending['company']) : null;
        try {
            $id = Microsoft::for($own)->redeem((string) $request->query('code', ''), VpnSettings::portalUrl('/vpn/callback'), $pending);
        } catch (\Throwable $e) {
            Log::warning('VPN portal: Microsoft sign-in failed', ['error' => $e->getMessage()]);
            return redirect('/vpn')->with('vpn_error', 'Microsoft sign-in failed. Please try again.');
        }
        $vc = $own ?? VpnCompany::where('ms_tenant_id', $id['tid'])->first();
        if ($vc && $vc->ms_tenant_id !== $id['tid']) {
            $vc = null; // signed in to another tenant than the company's own app
        }
        $user = $vc ? VpnUser::where('company_id', $vc->company_id)->where('auth_type', 'microsoft')->where('ms_oid', $id['oid'])->first() : null;

        // Microsoft 365 groups: members get access without being added by hand; people who came in
        // through a group lose it when they leave every group the company uses.
        if ($vc && \App\Services\Vpn\VpnAccess::usesGroups($vc) && (!$user || $user->viaGroup())) {
            $in = $this->access->membership($vc, $id['oid'], true);
            if ($in === null && !$user) {
                return $this->message('Try again shortly', 'Your company\'s Microsoft 365 groups couldn\'t be checked right now. Please try again in a few minutes.', 503);
            }
            if ($in !== null && !\App\Services\Vpn\VpnAccess::grantsAccess($vc, $in)) {
                if ($user) {
                    $this->vpn->removeUser($user, 'system (left the Microsoft 365 group)');
                    $user = null;
                }
            } elseif (!$user && $in !== null) {
                $user = VpnUser::create([
                    'company_id' => $vc->company_id, 'auth_type' => 'microsoft', 'source' => 'group', 'ms_oid' => $id['oid'],
                    'upn' => $id['upn'], 'name' => $id['name'], 'added_by' => 'Microsoft 365 group',
                ]);
                VpnAuditLog::record($vc->company_id, $id['upn'], 'vpn_access_given (Microsoft 365 group)');
            }
        }
        if (!$user) {
            Log::notice('VPN portal: sign-in refused', ['upn' => $id['upn'], 'tid' => $id['tid']]);
            return $this->message(
                'No VPN access',
                $vc ? 'You don\'t have VPN access yet. Ask your company\'s IT admin to add you.' : 'Your organization isn\'t set up for CFBTEL VPN. Contact CFBTEL support.',
                403,
            );
        }
        if ($user->isBlocked()) {
            return $this->blockedMessage($user);
        }
        // Devices are labelled with the sign-in name; keep it current.
        $user->update(['upn' => $id['upn'], 'name' => $id['name']]);
        return $this->startSession($request, $user, 'Microsoft');
    }

    /** Email-and-password sign-in (accounts invited by email, for companies without Microsoft Entra). */
    public function passwordLogin(Request $request)
    {
        $email = \App\Services\Vpn\VpnAccounts::cleanEmail($request->input('email'));
        $password = (string) $request->input('password', '');
        if (!$email || $password === '') {
            return response()->view('vpn.portal.password', ['email' => (string) $email, 'error' => 'Enter your email and password.'], 422);
        }
        $r = $this->accounts->attempt($email, $password);
        if (!$r['user']) {
            return response()->view('vpn.portal.password', ['email' => $email, 'error' => $r['error']], 422);
        }
        if ($r['user']->isBlocked()) {
            return $this->blockedMessage($r['user']);
        }
        return $this->afterPassword($request, $r['user'], 'password');
    }

    /** "Forgot password?": always the same answer, so it can't be used to find accounts. */
    public function forgot(Request $request)
    {
        $email = \App\Services\Vpn\VpnAccounts::cleanEmail($request->input('email'));
        $user = $email ? VpnUser::findLocalByEmail($email) : null;
        if ($user) {
            try {
                $this->accounts->sendLink($user, true);
                VpnAuditLog::record($user->company_id, $user->upn, 'password_reset_requested');
            } catch (\Throwable $e) {
                Log::error('VPN portal: sending a password link failed', ['error' => $e->getMessage()]);
            }
        }
        return $this->message('Check your email', 'If that address has a VPN account, we\'ve sent it a link to set a new password. The link works for one hour.');
    }

    /** The emailed invitation / new-password link. */
    public function passwordForm(Request $request, string $token)
    {
        $user = \App\Services\Vpn\VpnAccounts::userForToken($token);
        if (!$user) {
            return $this->message('Link expired', 'This link has expired or was already used. Use "Forgot password?" on the sign-in page, or ask your IT admin to resend your invitation.', 410);
        }
        return response()->view('vpn.portal.set-password', ['account' => $user, 'token' => $token, 'error' => null, 'min' => \App\Services\Vpn\VpnAccounts::MIN_PASSWORD]);
    }

    public function setPassword(Request $request, string $token)
    {
        $user = \App\Services\Vpn\VpnAccounts::userForToken($token);
        if (!$user) {
            return $this->message('Link expired', 'This link has expired or was already used. Use "Forgot password?" on the sign-in page.', 410);
        }
        $password = (string) $request->input('password', '');
        $min = \App\Services\Vpn\VpnAccounts::MIN_PASSWORD;
        $error = match (true) {
            mb_strlen($password) < $min => "Use at least {$min} characters.",
            mb_strlen($password) > 200 => 'That password is too long.',
            !hash_equals($password, (string) $request->input('password_confirmation', '')) => 'The two passwords don\'t match.',
            strcasecmp($password, $user->upn) === 0 => 'Don\'t use your email address as the password.',
            default => null,
        };
        if ($error) {
            return response()->view('vpn.portal.set-password', ['account' => $user, 'token' => $token, 'error' => $error, 'min' => $min], 422);
        }
        $this->accounts->setPassword($user, $password);
        return $this->afterPassword($request, $user->fresh(), 'new password');
    }

    public function logout(Request $request)
    {
        $request->session()->forget(self::SESSION);
        $request->session()->regenerate();
        return redirect('/vpn');
    }

    public function addDevice(Request $request)
    {
        $user = $this->person($request);
        if (!$user) {
            return redirect('/vpn')->with('vpn_error', 'Please sign in again.');
        }
        $name = WireGuard::cleanDeviceName($request->input('name'));
        $platform = array_key_exists($request->input('platform'), self::PLATFORMS) ? $request->input('platform') : 'windows';
        $site = VpnSite::with('firewall')->where('company_id', $user->company_id)->find((int) $request->input('site'));
        if (!$name) {
            return $this->devicesPage($user, ['error', 'Use 1–32 letters, numbers, spaces, dots, dashes or underscores.'], 422);
        }
        if (!$site || !$site->isReady()) {
            return $this->devicesPage($user, ['error', 'Pick a site.'], 422);
        }
        if (!$this->access->sitesFor($user, true)->contains('id', $site->id)) {
            return $this->devicesPage($user, ['error', "You don't have access to {$site->firewall->name}. Ask your IT admin to add you to its Microsoft 365 group."], 403);
        }
        try {
            $made = $this->vpn->addDevice($user, $site, $name);
        } catch (VpnException $e) {
            return $this->devicesPage($user, ['error', $e->getMessage()], 422);
        }
        $svg = (new Writer(new ImageRenderer(new RendererStyle(280, 1), new SvgImageBackEnd())))->writeString($made['config']);
        return response()->view('vpn.portal.new-device', [
            'user' => $user,
            'device' => $made['device'],
            'site' => $site,
            'platform' => $platform,
            'platforms' => self::PLATFORMS,
            'qrSvg' => $svg,
            // The configuration holds the private key: it goes to the browser once and is never stored.
            'downloadHref' => 'data:application/octet-stream;base64,' . base64_encode($made['config']),
            'fileName' => preg_replace('/[^A-Za-z0-9._-]+/', '-', "CFBTEL-{$site->firewall->name}-{$name}") . '.conf',
        ]);
    }

    public function removeDevice(Request $request, int $device)
    {
        $user = $this->person($request);
        if (!$user) {
            return redirect('/vpn')->with('vpn_error', 'Please sign in again.');
        }
        $d = VpnDevice::where('vpn_user_id', $user->id)->whereNull('revoked_at')->find($device);
        if (!$d) {
            return $this->devicesPage($user, ['error', 'Unknown device.'], 404);
        }
        try {
            $this->vpn->removeDevice($d, $user->upn);
            return $this->devicesPage($user, ['ok', "“{$d->name}” was removed. It can no longer connect."]);
        } catch (\Throwable $e) {
            Log::warning('VPN portal: removing a device failed', ['device' => $d->id, 'error' => $e->getMessage()]);
            return $this->devicesPage($user, ['ok', "“{$d->name}” was switched off. The firewall will drop it within 15 minutes."]);
        }
    }

    /** Microsoft sends a company's Global Administrator back here after approving the app. */
    public function consentCallback(Request $request)
    {
        try {
            $state = json_decode(Crypt::decryptString((string) $request->query('state', '')), true);
        } catch (\Throwable) {
            $state = null;
        }
        if (!is_array($state) || ($state['exp'] ?? 0) < time() || !($company = Company::find($state['c'] ?? 0))) {
            return $this->message('Link expired', 'This approval link has expired or is invalid. Ask CFBTEL for a new one.', 400);
        }
        $tenant = strtolower((string) $request->query('tenant', ''));
        if ($request->query('error') || $request->query('admin_consent') !== 'True' || !Microsoft::isGuid($tenant)) {
            return $this->message('Not approved', strtok((string) $request->query('error_description', 'The app wasn\'t approved.'), "\r\n"), 400);
        }
        $other = VpnCompany::where('ms_tenant_id', $tenant)->where('company_id', '!=', $company->id)->first();
        if ($other) {
            return $this->message('Already connected', 'This Microsoft 365 tenant is already linked to another company. Contact CFBTEL.', 409);
        }
        $vc = VpnCompany::forCompany($company);
        if ($vc->ms_tenant_id && $vc->ms_tenant_id !== $tenant) {
            return $this->message('Different tenant', 'This company is already linked to another Microsoft 365 tenant. Contact CFBTEL.', 409);
        }
        $vc->update(['ms_tenant_id' => $tenant, 'ms_tenant_name' => Microsoft::for(null)->tenantName($tenant)]);
        VpnAuditLog::record($company->id, 'microsoft-consent', "m365_connected {$tenant}");
        return $this->message('Microsoft 365 connected', "{$company->name} is connected to CFBTEL VPN. You can close this page.");
    }
}
