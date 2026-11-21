<?php

namespace App\Http\Controllers\Vpn;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Vpn\VpnSite;
use App\Models\Vpn\VpnUser;
use App\Services\Vpn\VpnAccess;
use App\Services\Vpn\VpnException;
use App\Services\Vpn\VpnManager;
use App\Services\Vpn\WireGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * "Sign in" in the CFBTEL VPN Windows app:
 *
 *  1. The app makes its own key pair, listens on 127.0.0.1:<port> and opens
 *     /vpn/app?state=…&port=…&pub=<its public key>&name=<computer name> in the browser.
 *  2. The person signs in as usual (Microsoft 365 or email and password), picks the office and clicks Connect.
 *     The device is created with the app's public key: the private key never leaves the computer.
 *  3. The browser is sent to http://127.0.0.1:<port>/callback?code=…&state=…
 *  4. The app redeems the one-time code at POST /vpn/app/token for the tunnel settings and adds the tunnel.
 *
 * The code works once, for 5 minutes, and only with the state the app chose.
 */
class AppPairController extends Controller
{
    public const CODE_TTL = 300;

    public function __construct(private VpnManager $vpn, private VpnAccess $access)
    {
    }

    /** @return array{state: string, port: int, pub: string, name: string}|null */
    private function params(Request $request): ?array
    {
        $state = (string) $request->input('state', '');
        $port = (int) $request->input('port', 0);
        $pub = (string) $request->input('pub', '');
        $name = WireGuard::cleanDeviceName(substr((string) $request->input('name', ''), 0, 32)) ?? 'Windows PC';
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $state) || $port < 1024 || $port > 65535 || !WireGuard::isKey($pub)) {
            return null;
        }
        return compact('state', 'port', 'pub', 'name');
    }

    private function person(Request $request): ?VpnUser
    {
        // Same rules as the staff page (session, password version, Microsoft object id).
        return app(PortalController::class)->person($request);
    }

    private function message(string $title, string $text, int $status)
    {
        return response()->view('vpn.portal.message', compact('title', 'text') + ['user' => null], $status);
    }

    public function show(Request $request)
    {
        $p = $this->params($request);
        if (!$p) {
            return $this->message('Start from the app', 'Open CFBTEL VPN on your computer and click Sign in.', 400);
        }
        $user = $this->person($request);
        if (!$user) {
            $request->session()->put('vpn_intended', '/vpn/app?' . http_build_query($p));
            return redirect('/vpn');
        }
        $sites = $this->access->sitesFor($user);
        return response()->view('vpn.portal.app-connect', ['user' => $user, 'company' => Company::find($user->company_id), 'sites' => $sites, 'p' => $p]);
    }

    public function connect(Request $request)
    {
        $p = $this->params($request);
        $user = $this->person($request);
        if (!$p || !$user) {
            return $this->message('Start again', 'Your sign-in expired. Click Sign in in the CFBTEL VPN app again.', 400);
        }
        $name = WireGuard::cleanDeviceName($request->input('device_name')) ?? $p['name'];
        $site = $this->access->sitesFor($user, true)->firstWhere('id', (int) $request->input('site'));
        if (!$site instanceof VpnSite) {
            return $this->message('Pick an office', 'You don\'t have access to that office.', 403);
        }
        try {
            $made = $this->vpn->addDevice($user, $site, $name, $p['pub']);
        } catch (VpnException $e) {
            return response()->view('vpn.portal.app-connect', ['user' => $user, 'company' => Company::find($user->company_id), 'sites' => $this->access->sitesFor($user), 'p' => $p, 'error' => $e->getMessage()], 422);
        }
        $code = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $dns = array_values(array_filter(array_map('trim', explode(',', (string) $site->dns))));
        Cache::put('vpn_app_code:' . hash('sha256', $code), [
            'state' => $p['state'],
            'tunnel' => [
                'name' => self::tunnelName($site->firewall->name),
                'office' => $site->firewall->name,
                'device' => $name,
                'address' => $made['peer']['address'] . '/32',
                'dns' => $dns,
                'mtu' => 1420,
                'peer_public_key' => $site->server_public_key,
                'preshared_key' => $made['peer']['preshared_key'],
                'endpoint' => $site->endpoint,
                'allowed_ips' => array_values(array_filter(array_map('trim', explode(',', (string) $site->allowed_ips)))),
                'persistent_keepalive' => 25,
            ],
        ], self::CODE_TTL);
        return redirect()->away("http://127.0.0.1:{$p['port']}/callback?" . http_build_query(['code' => $code, 'state' => $p['state']]));
    }

    /** Windows tunnel names: letters, numbers, = + . _ - ; at most 32 characters. */
    public static function tunnelName(string $office): string
    {
        $n = trim(preg_replace('/[^A-Za-z0-9=+._-]+/', '-', 'CFBTEL-' . $office), '-');
        return substr($n, 0, 32);
    }

    public function token(Request $request)
    {
        $code = (string) $request->input('code', '');
        $state = (string) $request->input('state', '');
        $data = $code !== '' ? Cache::pull('vpn_app_code:' . hash('sha256', $code)) : null;
        if (!is_array($data) || !hash_equals((string) $data['state'], $state)) {
            return response()->json(['error' => 'This sign-in code is invalid, used or expired. Sign in again from the app.'], 400);
        }
        return response()->json($data['tunnel'])->header('Cache-Control', 'no-store');
    }
}
