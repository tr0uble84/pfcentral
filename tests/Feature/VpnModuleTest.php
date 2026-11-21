<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Firewall;
use App\Models\User;
use App\Models\Vpn\VpnCompany;
use App\Models\Vpn\VpnDevice;
use App\Models\Vpn\VpnSite;
use App\Models\Vpn\VpnUser;
use App\Services\PfSenseApiService;
use App\Services\Vpn\Microsoft;
use App\Services\Vpn\MicrosoftSetup;
use App\Services\Vpn\PfSenseWireGuardDriver;
use App\Services\Vpn\VpnException;
use App\Services\Vpn\VpnManager;
use App\Services\Vpn\VpnSettings;
use App\Services\Vpn\WireGuard;
use App\Services\Vpn\WireGuardDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use phpseclib3\Crypt\RSA;
use Tests\TestCase;

/** In-memory firewall. */
class FakeWireGuard implements WireGuardDriver
{
    public array $tunnels = [];
    public array $peers = [];
    public array $setups = [];
    public bool $down = false;

    private function up(): void
    {
        if ($this->down) {
            throw new \RuntimeException('firewall unreachable');
        }
    }

    public function interfaces(): array
    {
        $this->up();
        return [
            ['id' => 'wan', 'descr' => 'WAN', 'ipaddr' => '198.51.100.130', 'subnet' => 28],
            ['id' => 'lan', 'descr' => 'LAN', 'ipaddr' => '192.168.1.1', 'subnet' => 24],
        ];
    }

    public function tunnels(): array
    {
        $this->up();
        return $this->tunnels;
    }

    public function setup(array $input): array
    {
        $this->up();
        $this->setups[] = $input;
        $t = ['ref' => 'tun_wg' . count($this->tunnels), 'name' => 'CFBTEL VPN', 'listen_port' => $input['listen_port'], 'public_key' => WireGuard::keyPair()['public']];
        $this->tunnels[] = $t;
        return ['ref' => $t['ref'], 'name' => $t['name'], 'public_key' => $t['public_key'], 'gateway' => WireGuard::firstHost($input['subnet']), 'log' => ['done']];
    }

    public function peers(string $tunnelRef): array
    {
        $this->up();
        return array_values(array_map(fn ($p) => ['public_key' => $p['public_key'], 'name' => $p['name'], 'addresses' => [$p['address']]], array_filter($this->peers, fn ($p) => $p['tunnel'] === $tunnelRef)));
    }

    public function addPeer(string $tunnelRef, array $peer): void
    {
        $this->up();
        $this->peers[] = $peer + ['tunnel' => $tunnelRef];
    }

    /** @var array<string, array{handshake: int, rx: int, tx: int, endpoint: ?string}> live WireGuard state by public key */
    public array $live = [];

    public function status(string $tunnelRef): array
    {
        $this->up();
        $out = [];
        foreach ($this->peers as $p) {
            if ($p['tunnel'] === $tunnelRef && isset($this->live[$p['public_key']])) {
                $out[] = ['public_key' => $p['public_key']] + $this->live[$p['public_key']];
            }
        }
        return $out;
    }

    public function removePeers(string $tunnelRef, array $publicKeys): int
    {
        $this->up();
        $before = count($this->peers);
        $this->peers = array_values(array_filter($this->peers, fn ($p) => !($p['tunnel'] === $tunnelRef && in_array($p['public_key'], $publicKeys, true) && str_starts_with($p['name'], 'cfbvpn-'))));
        return $before - count($this->peers);
    }
}

/** Microsoft without the network. */
class FakeMicrosoft extends Microsoft
{
    public array $people = [];
    public bool $down = false;
    public array $calls = [];

    public function __construct(public string $label = 'shared')
    {
        parent::__construct('11111111-2222-3333-4444-555555555555', 'secret');
    }

    public function loginUrl(string $redirectUri, ?string $loginHint = null): array
    {
        $this->calls[] = "login {$loginHint}";
        return ['url' => "https://login.microsoftonline.com/{$this->label}?login_hint=" . urlencode((string) $loginHint), 'pending' => ['state' => 'st-' . bin2hex(random_bytes(4)), 'nonce' => 'n', 'verifier' => 'v']];
    }

    public function redeem(string $code, string $redirectUri, array $pending): array
    {
        $this->calls[] = "redeem {$code}";
        $p = $this->people[$code] ?? throw new VpnException('bad code');
        return ['tid' => $p['tid'], 'oid' => $p['oid'], 'upn' => $p['upn'], 'name' => $p['name']];
    }

    public function searchUsers(string $tenantId, string $query): array
    {
        if ($this->down) {
            throw new VpnException('graph down');
        }
        return array_values(array_map(fn ($p) => $this->asUser($p), array_filter($this->people, fn ($p) => $p['tid'] === $tenantId && empty($p['deleted']) && str_contains(strtolower($p['name']), strtolower($query)))));
    }

    public function userStatus(string $tenantId, string $oid): ?array
    {
        if ($this->down) {
            throw new VpnException('graph down');
        }
        foreach ($this->people as $p) {
            if ($p['tid'] === $tenantId && $p['oid'] === $oid && empty($p['deleted'])) {
                return $this->asUser($p);
            }
        }
        return null;
    }

    private function asUser(array $p): array
    {
        return ['oid' => $p['oid'], 'upn' => $p['upn'], 'name' => $p['name'], 'mail' => $p['upn'], 'enabled' => empty($p['disabled']), 'guest' => false];
    }

    public function tenantName(string $tenantId): ?string
    {
        return 'Acme Inc';
    }

    /** @var array<string, array{name: string, members: string[]}> group id => name and member oids */
    public array $groups = [];

    public function searchGroups(string $tenantId, string $query): array
    {
        return collect($this->groups)->filter(fn ($g) => str_contains(strtolower($g['name']), strtolower($query)))
            ->map(fn ($g, $id) => ['id' => $id, 'name' => $g['name'], 'description' => null, 'security' => true, 'm365' => false])->values()->all();
    }

    public function group(string $tenantId, string $groupId): ?array
    {
        $g = $this->groups[$groupId] ?? null;
        return $g ? ['id' => $groupId, 'name' => $g['name'], 'description' => null, 'security' => true, 'm365' => false] : null;
    }

    public function memberOf(string $tenantId, string $oid, array $groupIds): array
    {
        if ($this->down) {
            throw new VpnException('graph down');
        }
        $this->calls[] = "memberOf {$oid}";
        return array_values(array_filter($groupIds, fn ($id) => in_array($oid, $this->groups[$id]['members'] ?? [], true)));
    }
}

/** One-click setup without the network. */
class FakeSetup extends MicrosoftSetup
{
    public array $calls = [];
    public string $tid = '22222222-2222-2222-2222-222222222222';
    public int $n = 0;

    public function __construct()
    {
        parent::__construct('99999999-0000-0000-0000-000000000009', 'setup-secret');
    }

    public function authorizeUrl(?string $loginHint = null): array
    {
        return ['url' => 'https://login.microsoftonline.com/setup', 'pending' => ['state' => 'ss-' . bin2hex(random_bytes(4)), 'nonce' => 'n', 'verifier' => 'v']];
    }

    public function redeem(string $code, array $pending): array
    {
        $this->calls[] = "redeem {$code}";
        return ['tid' => $this->tid, 'upn' => 'admin@acme.com', 'name' => 'Acme Admin'];
    }

    public function organization(): array
    {
        return ['name' => 'Acme Inc', 'domains' => ['acme.com', 'acme.onmicrosoft.com']];
    }

    public function provision(string $redirectUri, ?string $existingAppId = null, string $appName = self::APP_NAME, ?string $notes = null): array
    {
        $this->n++;
        $this->calls[] = 'provision ' . ($existingAppId ?? 'new') . " {$redirectUri}" . ($appName !== self::APP_NAME ? " [{$appName}]" : '');
        return ['client_id' => 'dddddddd-0000-0000-0000-000000000001', 'client_secret' => "auto-secret-{$this->n}", 'object_id' => 'eeeeeeee-0000-0000-0000-000000000001',
            'key_id' => "ffffffff-0000-0000-0000-00000000000{$this->n}", 'expires_at' => \Carbon\CarbonImmutable::now()->addYears(2), 'reused' => $existingAppId !== null];
    }

    public function removeSecret(string $appObjectId, string $keyId): void
    {
        $this->calls[] = "remove-secret {$keyId}";
    }

    public function removeSetupAccess(): bool
    {
        $this->calls[] = 'cleanup';
        return true;
    }
}

class VpnModuleTest extends TestCase
{
    use RefreshDatabase;

    private const ACME_TID = '22222222-2222-2222-2222-222222222222';
    private const PORTAL = 'http://vpn.test';

    private const BETA_TID = '55555555-5555-5555-5555-555555555555';

    private FakeWireGuard $fw;
    private FakeMicrosoft $ms;
    private FakeMicrosoft $msOwn; // Beta's own app in Beta's own Entra

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('x', 32))]);
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->fw = new FakeWireGuard();
        $this->ms = new FakeMicrosoft();
        $this->ms->people = [
            'ann' => ['tid' => self::ACME_TID, 'oid' => 'aaaaaaaa-0000-0000-0000-000000000001', 'upn' => 'ann@acme.com', 'name' => 'Ann'],
            'bob' => ['tid' => self::ACME_TID, 'oid' => 'aaaaaaaa-0000-0000-0000-000000000002', 'upn' => 'bob@acme.com', 'name' => 'Bob'],
            'carl' => ['tid' => self::ACME_TID, 'oid' => 'aaaaaaaa-0000-0000-0000-000000000003', 'upn' => 'carl@acme.com', 'name' => 'Carl'],
            'eve' => ['tid' => '44444444-4444-4444-4444-444444444444', 'oid' => 'eeeeeeee-0000-0000-0000-000000000001', 'upn' => 'eve@evil.com', 'name' => 'Eve'],
        ];
        $this->msOwn = new FakeMicrosoft('beta-own-app');
        $this->msOwn->people = [
            'bea' => ['tid' => self::BETA_TID, 'oid' => 'bbbbbbbb-0000-0000-0000-000000000001', 'upn' => 'bea@beta.com', 'name' => 'Bea'],
            'ann' => $this->ms->people['ann'], // someone from another tenant using Beta's app
        ];
        Microsoft::$factory = fn ($vc) => $vc && $vc->hasOwnApp() ? $this->msOwn : $this->ms;
        VpnManager::resolveDriversWith(fn () => $this->fw);
        VpnSettings::set('portal_host', 'vpn.test');
        VpnSettings::set('max_devices', '2');
    }

    protected function tearDown(): void
    {
        VpnManager::resolveDriversWith(null);
        Microsoft::$factory = null;
        MicrosoftSetup::$factory = null;
        MicrosoftSetup::$sleep = null;
        parent::tearDown();
    }

    /** Act in the PFCentral console (resets the root URL a staff-page request forced in this process). */
    private function console(User $user): static
    {
        \Illuminate\Support\Facades\URL::forceRootUrl(config('app.url'));
        return $this->actingAs($user);
    }

    /** CFBTEL global admin, Acme with one pfSense, its company admin and a read-only user. */
    private function world(): array
    {
        $acme = Company::create(['name' => 'Acme']);
        $beta = Company::create(['name' => 'Beta']);
        $global = User::create(['name' => 'Jay', 'email' => 'admin@example.com', 'password' => 'password', 'role' => 'admin', 'company_id' => null]);
        $acmeAdmin = User::create(['name' => 'Acme IT', 'email' => 'it@acme.test', 'password' => 'password', 'role' => 'admin', 'company_id' => $acme->id]);
        $acmeReader = User::create(['name' => 'Acme RO', 'email' => 'ro@acme.test', 'password' => 'password', 'role' => 'readonly', 'company_id' => $acme->id]);
        $betaAdmin = User::create(['name' => 'Beta IT', 'email' => 'it@beta.test', 'password' => 'password', 'role' => 'admin', 'company_id' => $beta->id]);
        foreach ([$global, $acmeAdmin, $acmeReader, $betaAdmin] as $u) {
            $u->forceFill(['email_verified_at' => now()])->save();
        }
        $firewall = Firewall::create(['name' => 'Acme HQ', 'company_id' => $acme->id, 'url' => 'https://fw.acme.test', 'os_type' => 'pfsense', 'auth_method' => 'basic', 'api_key' => 'u', 'api_secret' => 'p', 'netgate_id' => 'acme-hq']);
        return compact('acme', 'beta', 'global', 'acmeAdmin', 'acmeReader', 'betaAdmin', 'firewall');
    }

    /** Connect Acme's Microsoft 365 and build WireGuard on its firewall, as the global admin. */
    private function onboard(array $w): VpnSite
    {
        $page = $this->console($w['global'])->get("/companies/{$w['acme']->id}/vpn");
        $page->assertOk()->assertSee('Connect Microsoft 365');
        $state = Crypt::encryptString(json_encode(['c' => $w['acme']->id, 'exp' => time() + 600]));
        $this->get('/vpn/consent/callback?' . http_build_query(['tenant' => self::ACME_TID, 'admin_consent' => 'True', 'state' => $state]))->assertOk()->assertSee('Microsoft 365 connected');
        $this->console($w['global'])->post("/companies/{$w['acme']->id}/vpn/firewalls/{$w['firewall']->id}/enable")->assertRedirect();
        $site = VpnSite::firstOrFail();
        $this->console($w['global'])->get("/companies/{$w['acme']->id}/vpn/sites/{$site->id}")->assertOk()->assertSee('Create WireGuard')->assertSee('198.51.100.130', false);
        $this->console($w['global'])->post("/companies/{$w['acme']->id}/vpn/sites/{$site->id}/setup", ['lan' => 'lan', 'wan' => 'wan', 'port' => 51820, 'subnet' => '10.0.70.0/24', 'endpoint_host' => 'vpn.acme.com'])->assertRedirect();
        return $site->fresh();
    }

    private function grant(User $by, Company $company, string $who): void
    {
        $this->console($by)->post("/companies/{$company->id}/vpn/users", ['oid' => $this->ms->people[$who]['oid']])->assertRedirect();
    }

    /** Sign in on the staff page as a Microsoft 365 user (work email first, then Microsoft). */
    private function staffSignIn(string $who, ?string $email = null)
    {
        auth()->logout();
        $email ??= ($this->ms->people[$who] ?? $this->msOwn->people[$who])['upn'];
        $this->post(self::PORTAL . '/vpn/login', ['email' => $email])->assertRedirect();
        $state = session('vpn_portal_login')['state'];
        return $this->get(self::PORTAL . '/vpn/callback?' . http_build_query(['code' => $who, 'state' => $state]));
    }

    private function addDevice(string $name)
    {
        return $this->post(self::PORTAL . '/vpn/devices', ['site' => VpnSite::first()->id, 'name' => $name, 'platform' => 'iphone']);
    }

    public function test_portal_host_serves_only_the_vpn_pages(): void
    {
        $this->world();
        $this->get(self::PORTAL . '/')->assertRedirect('https://vpn.test/vpn');
        $this->get(self::PORTAL . '/vpn')->assertOk()->assertSee('Continue with Microsoft')->assertSee('Work email');
        $this->get(self::PORTAL . '/login')->assertNotFound();
        $this->get(self::PORTAL . '/dashboard')->assertNotFound();
        $this->get(self::PORTAL . '/vpn-admin/settings')->assertNotFound();
        $this->get(self::PORTAL . '/api/v1/firewalls')->assertNotFound();
        // On the console's own address the staff page sends people to the portal host.
        $this->get('http://localhost/vpn')->assertRedirect('https://vpn.test/vpn');
    }

    public function test_onboarding_builds_wireguard_and_links_the_tenant(): void
    {
        $w = $this->world();
        $site = $this->onboard($w);
        $this->assertSame(self::ACME_TID, VpnCompany::where('company_id', $w['acme']->id)->value('ms_tenant_id'));
        $this->assertTrue($site->isReady());
        $this->assertSame('vpn.acme.com:51820', $site->endpoint);
        $this->assertSame('10.0.70.1', $site->dns);
        $this->assertSame([['listen_port' => 51820, 'subnet' => '10.0.70.0/24', 'lan' => 'lan', 'wan' => 'wan']], $this->fw->setups);
    }

    public function test_company_admin_gives_access_and_the_person_adds_a_device(): void
    {
        $w = $this->world();
        $this->onboard($w);
        $this->console($w['acmeAdmin'])->get("/companies/{$w['acme']->id}/vpn?q=bo&search=1")->assertOk()->assertSee('bob@acme.com');
        $this->grant($w['acmeAdmin'], $w['acme'], 'bob');
        $this->assertSame('bob@acme.com', VpnUser::first()->upn);

        $this->staffSignIn('bob')->assertRedirect('https://vpn.test/vpn');
        $this->get(self::PORTAL . '/vpn')->assertOk()->assertSee('Your devices');
        $res = $this->addDevice('Phone');
        $res->assertOk()->assertSee('<svg', false);
        $peer = $this->fw->peers[0];
        $device = VpnDevice::first();
        $this->assertSame('cfbvpn-' . $device->id, $peer['name']);
        $this->assertSame('10.0.70.2', $peer['address']);
        $this->assertTrue(WireGuard::isKey($peer['preshared_key']));
        preg_match('#data:application/octet-stream;base64,([A-Za-z0-9+/=]+)#', $res->getContent(), $m);
        $conf = base64_decode($m[1]);
        $this->assertStringContainsString('Endpoint = vpn.acme.com:51820', $conf);
        $this->assertStringContainsString('DNS = 10.0.70.1', $conf);
        $this->assertStringContainsString('Address = 10.0.70.2/32', $conf);
        $this->assertStringContainsString("PresharedKey = {$peer['preshared_key']}", $conf);
        $this->assertStringContainsString('PublicKey = ' . $this->fw->tunnels[0]['public_key'], $conf);
        $this->assertStringNotContainsString('PrivateKey', VpnDevice::first()->toJson());
    }

    public function test_people_nobody_assigned_and_unknown_tenants_are_refused(): void
    {
        $w = $this->world();
        $this->onboard($w);
        $this->staffSignIn('carl')->assertForbidden()->assertSee('Ask your company');
        $this->staffSignIn('eve')->assertForbidden()->assertSee('isn&#039;t set up', false);
    }

    public function test_only_global_admins_and_the_companys_own_admins_manage_it(): void
    {
        $w = $this->world();
        $this->onboard($w);
        $this->console($w['betaAdmin'])->get("/companies/{$w['acme']->id}/vpn")->assertForbidden();
        $this->console($w['betaAdmin'])->post("/companies/{$w['acme']->id}/vpn/users", ['oid' => $this->ms->people['bob']['oid']])->assertForbidden();
        $this->console($w['acmeReader'])->get("/companies/{$w['acme']->id}/vpn")->assertOk()->assertDontSee('Give VPN access');
        $this->console($w['acmeReader'])->post("/companies/{$w['acme']->id}/vpn/users", ['oid' => $this->ms->people['bob']['oid']])->assertForbidden();
        $this->console($w['acmeAdmin'])->get('/vpn-admin/settings')->assertStatus(403);
        // A person from another tenant can't be added by forging their id.
        $this->console($w['acmeAdmin'])->post("/companies/{$w['acme']->id}/vpn/users", ['oid' => $this->ms->people['eve']['oid']]);
        $this->assertSame(0, VpnUser::count());
    }

    public function test_removing_access_removes_devices_at_once_and_the_device_limit_holds(): void
    {
        $w = $this->world();
        $this->onboard($w);
        $this->grant($w['acmeAdmin'], $w['acme'], 'bob');
        $this->staffSignIn('bob');
        $this->addDevice('One')->assertOk();
        $this->addDevice('Two')->assertOk();
        $this->addDevice('Three')->assertStatus(422)->assertSee('Remove one first');
        $this->assertCount(2, $this->fw->peers);

        $bob = VpnUser::first();
        $this->console($w['acmeAdmin'])->post("/companies/{$w['acme']->id}/vpn/users/{$bob->id}/remove")->assertRedirect();
        $this->assertCount(0, $this->fw->peers);
        $this->assertSame(0, VpnDevice::count());
        auth()->logout();
        $this->addDevice('Again')->assertRedirect(); // signed out: access is gone
        $this->assertCount(0, $this->fw->peers);
    }

    public function test_a_person_removes_their_own_device(): void
    {
        $w = $this->world();
        $this->onboard($w);
        $this->grant($w['global'], $w['acme'], 'bob');
        $this->staffSignIn('bob');
        $this->addDevice('Laptop');
        $d = VpnDevice::first();
        $this->post(self::PORTAL . "/vpn/devices/{$d->id}/remove")->assertOk()->assertSee('can no longer connect');
        $this->assertCount(0, $this->fw->peers);
    }

    public function test_sync_removes_devices_of_disabled_deleted_and_unknown_people_only(): void
    {
        $w = $this->world();
        $site = $this->onboard($w);
        foreach (['ann', 'bob', 'carl'] as $who) {
            $this->grant($w['global'], $w['acme'], $who);
            $this->staffSignIn($who);
            $this->addDevice('Phone')->assertOk();
        }
        $this->fw->peers[] = ['tunnel' => $site->tunnel, 'name' => 'Site-to-site', 'public_key' => WireGuard::keyPair()['public'], 'address' => '10.0.70.99'];
        $this->fw->peers[] = ['tunnel' => $site->tunnel, 'name' => 'cfbvpn-999', 'public_key' => WireGuard::keyPair()['public'], 'address' => '10.0.70.50'];
        $this->ms->people['bob']['disabled'] = true;
        $this->ms->people['carl']['deleted'] = true;

        $this->ms->down = true;
        $this->artisan('vpn:sync')->assertSuccessful();
        // Microsoft down: nobody loses access; only the peer PFCentral never created (cfbvpn-999) is cleaned up.
        $this->assertCount(3, VpnDevice::whereNull('revoked_at')->get());
        $this->assertEqualsCanonicalizing(['Site-to-site', ...VpnDevice::pluck('id')->map(fn ($i) => "cfbvpn-{$i}")->all()], collect($this->fw->peers)->pluck('name')->all());
        $this->ms->down = false;
        $this->artisan('vpn:sync')->assertSuccessful();

        $names = collect($this->fw->peers)->pluck('name')->all();
        $ann = VpnDevice::whereHas('user', fn ($q) => $q->where('upn', 'ann@acme.com'))->first();
        $this->assertEqualsCanonicalizing(['Site-to-site', 'cfbvpn-' . $ann->id], $names);
        $this->assertEqualsCanonicalizing(['ann@acme.com', 'bob@acme.com'], VpnUser::pluck('upn')->all());
    }

    public function test_when_the_firewall_is_down_removal_is_retried_by_the_sync(): void
    {
        $w = $this->world();
        $this->onboard($w);
        $this->grant($w['global'], $w['acme'], 'bob');
        $this->staffSignIn('bob');
        $this->addDevice('Phone');
        $this->fw->down = true;
        $bob = VpnUser::first();
        $this->console($w['global'])->post("/companies/{$w['acme']->id}/vpn/users/{$bob->id}/remove")->assertRedirect()->assertSessionHas('warning');
        $this->assertNotNull(VpnDevice::first()->revoked_at);
        $this->fw->down = false;
        $this->artisan('vpn:sync')->assertSuccessful();
        $this->assertCount(0, $this->fw->peers);
        $this->assertSame(0, VpnDevice::count());
    }

    public function test_settings_page_saves_the_microsoft_app_with_an_encrypted_secret(): void
    {
        $w = $this->world();
        $this->console($w['global'])->post('/vpn-admin/settings', ['ms_client_id' => '11111111-2222-3333-4444-555555555555', 'ms_client_secret' => 's3cr3t-value', 'portal_host' => 'vpn.example.com', 'max_devices' => 5, 'proxy_ips' => '203.0.113.81'])->assertRedirect('/vpn-admin/settings');
        $this->assertSame(['203.0.113.81'], VpnSettings::proxyIps());
        $this->console($w['global'])->post('/vpn-admin/settings', ['portal_host' => 'vpn.example.com', 'max_devices' => 5, 'proxy_ips' => 'not-an-ip'])->assertSessionHasErrors('proxy_ips');
        $this->assertSame('s3cr3t-value', VpnSettings::clientSecret());
        $this->assertStringNotContainsString('s3cr3t', (string) \App\Models\SystemSetting::where('key', 'vpn_ms_client_secret')->value('value'));
        $this->assertSame('vpn.example.com', VpnSettings::portalHost());
        $this->console($w['global'])->get('/vpn-admin/settings')->assertOk()->assertSee('https://vpn.example.com/vpn/callback')->assertDontSee('s3cr3t-value');
    }

    public function test_microsoft_id_tokens_are_verified(): void
    {
        $key = RSA::createKey(2048);
        $jwk = json_decode($key->getPublicKey()->toString('JWK'), true)['keys'][0];
        Http::fake(['https://login.microsoftonline.com/common/discovery/v2.0/keys' => Http::response(['keys' => [$jwk + ['kid' => 'k1', 'use' => 'sig']]])]);
        $b64 = fn ($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $sign = function (array $claims) use ($key, $b64) {
            $input = $b64(json_encode(['alg' => 'RS256', 'kid' => 'k1', 'typ' => 'JWT'])) . '.' . $b64(json_encode($claims));
            return $input . '.' . $b64($key->withPadding(RSA::SIGNATURE_PKCS1)->withHash('sha256')->sign($input));
        };
        $app = '11111111-2222-3333-4444-555555555555';
        $ms = new Microsoft($app, 'secret');
        $ok = ['aud' => $app, 'iss' => 'https://login.microsoftonline.com/' . self::ACME_TID . '/v2.0', 'tid' => self::ACME_TID, 'oid' => 'AAAAAAAA-0000-0000-0000-000000000001', 'preferred_username' => 'Ann@Acme.com', 'name' => 'Ann', 'nonce' => 'n1', 'exp' => time() + 600, 'nbf' => time() - 5];

        $id = $ms->verifyIdToken($sign($ok), 'n1');
        $this->assertSame(['tid' => self::ACME_TID, 'oid' => 'aaaaaaaa-0000-0000-0000-000000000001', 'upn' => 'ann@acme.com', 'name' => 'Ann'], $id);

        foreach ([
            'wrong nonce' => [$ok, 'other'],
            'other tenant issuer' => [['iss' => 'https://login.microsoftonline.com/44444444-4444-4444-4444-444444444444/v2.0'] + $ok, 'n1'],
            'other app' => [['aud' => 'ffffffff-ffff-ffff-ffff-ffffffffffff'] + $ok, 'n1'],
            'expired' => [['exp' => time() - 3600] + $ok, 'n1'],
        ] as $why => [$claims, $nonce]) {
            try {
                $ms->verifyIdToken($sign($claims), $nonce);
                $this->fail("accepted a token with {$why}");
            } catch (VpnException) {
                $this->addToAssertionCount(1);
            }
        }
        // Tampered payload: signature no longer matches.
        [$h, , $s] = explode('.', $sign($ok));
        $this->expectException(VpnException::class);
        $ms->verifyIdToken("{$h}." . $b64(json_encode(['oid' => 'x'] + $ok)) . ".{$s}", 'n1');
    }

    public function test_pfsense_driver_deletes_only_portal_peers_highest_id_first(): void
    {
        $fw = Firewall::create(['name' => 'Acme HQ', 'company_id' => Company::create(['name' => 'Acme'])->id, 'url' => 'https://fw.acme.test', 'os_type' => 'pfsense', 'auth_method' => 'basic', 'api_key' => 'u', 'api_secret' => 'p', 'netgate_id' => 'x1']);
        $k = [WireGuard::keyPair()['public'], WireGuard::keyPair()['public'], WireGuard::keyPair()['public']];
        Http::fake([
            'https://fw.acme.test/api/v2/vpn/wireguard/peers*' => Http::response(['data' => [
                ['id' => 0, 'tun' => 'tun_wg0', 'descr' => 'cfbvpn-1 ann@acme.com Phone', 'publickey' => $k[0], 'allowedips' => [['address' => '10.0.70.2', 'mask' => 32]]],
                ['id' => 1, 'tun' => 'tun_wg0', 'descr' => 'Site-to-site', 'publickey' => $k[1], 'allowedips' => [['address' => '10.0.70.99', 'mask' => 32]]],
                ['id' => 2, 'tun' => 'tun_wg0', 'descr' => 'cfbvpn-2 bob@acme.com Laptop', 'publickey' => $k[2], 'allowedips' => [['address' => '10.0.70.3', 'mask' => 32]]],
            ]]),
            'https://fw.acme.test/api/v2/vpn/wireguard/peer*' => Http::response(['data' => []]),
            'https://fw.acme.test/api/v2/vpn/wireguard/apply' => Http::response(['data' => []]),
        ]);
        $n = (new PfSenseWireGuardDriver(new PfSenseApiService($fw)))->removePeers('tun_wg0', $k);
        $this->assertSame(2, $n);
        $deletes = collect(Http::recorded())->map(fn ($r) => $r[0])->filter(fn ($r) => $r->method() === 'DELETE')->map(fn ($r) => $r->url())->values()->all();
        $this->assertSame(['https://fw.acme.test/api/v2/vpn/wireguard/peer?id=2', 'https://fw.acme.test/api/v2/vpn/wireguard/peer?id=0'], $deletes);
    }

    public function test_visitor_address_is_taken_from_the_trusted_proxy_only(): void
    {
        VpnSettings::set('proxy_ips', '203.0.113.81');
        $mk = fn (string $host, string $remote, string $xff) => \Illuminate\Http\Request::create("https://{$host}/vpn", 'GET', [], [], [], ['REMOTE_ADDR' => $remote, 'HTTP_X_FORWARDED_FOR' => $xff]);
        $this->assertSame('203.0.113.9', \App\Services\Vpn\ClientIp::of($mk('vpn.test', '203.0.113.81', '10.9.9.9, 203.0.113.9')));
        $this->assertSame('198.51.100.7', \App\Services\Vpn\ClientIp::of($mk('vpn.test', '198.51.100.7', '203.0.113.9')), 'not from the proxy: header ignored');
        $this->assertSame('203.0.113.81', \App\Services\Vpn\ClientIp::of($mk('localhost', '203.0.113.81', '203.0.113.9')), 'console host: header ignored');
        $this->assertSame('203.0.113.81', \App\Services\Vpn\ClientIp::of($mk('vpn.test', '203.0.113.81', 'garbage')));
    }

    public function test_opcache_reset_after_update_never_blocks_requests(): void
    {
        $this->world();
        @unlink(storage_path('framework/opcache-version'));
        $this->get(self::PORTAL . '/vpn')->assertOk();
        $this->get(self::PORTAL . '/vpn')->assertOk();
    }

    public function test_a_company_can_use_its_own_microsoft_app(): void
    {
        $w = $this->world();
        $page = $this->console($w['betaAdmin'])->get("/companies/{$w['beta']->id}/vpn")->assertOk()->assertSee('Enter the company');
        // Beta's admin enters Beta's own app registration and email domain.
        $this->console($w['betaAdmin'])->post("/companies/{$w['beta']->id}/vpn/microsoft", [
            'email_domains' => '@Beta.com, beta.us', 'ms_tenant_id' => self::BETA_TID,
            'ms_client_id' => 'cccccccc-0000-0000-0000-000000000001', 'ms_client_secret' => 'beta-secret',
        ])->assertRedirect()->assertSessionHas('success');
        $vc = VpnCompany::where('company_id', $w['beta']->id)->first();
        $this->assertTrue($vc->hasOwnApp());
        $this->assertSame(['beta.com', 'beta.us'], $vc->domains());
        $this->assertSame('beta-secret', $vc->ms_client_secret);
        $this->assertStringNotContainsString('beta-secret', (string) $vc->getRawOriginal('ms_client_secret'));

        // People are picked from Beta's tenant through Beta's app.
        $this->console($w['betaAdmin'])->post("/companies/{$w['beta']->id}/vpn/users", ['oid' => $this->msOwn->people['bea']['oid']])->assertRedirect();
        $this->assertSame('bea@beta.com', VpnUser::where('company_id', $w['beta']->id)->value('upn'));

        // Staff sign-in: the @beta.com email sends Bea through Beta's own app.
        $this->staffSignIn('bea', 'bea@beta.com')->assertRedirect('https://vpn.test/vpn');
        $this->assertSame(['login bea@beta.com', 'redeem bea'], $this->msOwn->calls);
        $this->assertSame([], array_values(array_filter($this->ms->calls, fn ($c) => str_contains($c, 'bea'))));

        // A token from another tenant through Beta's app is refused.
        $this->staffSignIn('ann', 'ann@beta.com')->assertForbidden();
    }

    public function test_email_domains_are_unique_and_unknown_domains_use_the_shared_app(): void
    {
        $w = $this->world();
        $this->console($w['global'])->post("/companies/{$w['acme']->id}/vpn/microsoft", ['email_domains' => 'acme.com'])->assertSessionHas('success');
        $this->console($w['global'])->post("/companies/{$w['beta']->id}/vpn/microsoft", ['email_domains' => 'ACME.com'])->assertSessionHasErrors('email_domains');
        $this->console($w['global'])->post("/companies/{$w['beta']->id}/vpn/microsoft", ['email_domains' => 'not a domain'])->assertSessionHasErrors('email_domains');
        $this->console($w['global'])->post("/companies/{$w['beta']->id}/vpn/microsoft", ['ms_client_id' => 'cccccccc-0000-0000-0000-000000000001'])->assertSessionHasErrors('ms_client_id');
        auth()->logout();
        $this->post(self::PORTAL . '/vpn/login', ['email' => 'someone@unknown.example'])->assertRedirect();
        $this->assertSame(['login someone@unknown.example'], $this->ms->calls);
        $this->post(self::PORTAL . '/vpn/login', ['email' => 'not-an-email'])->assertRedirect('https://vpn.test/vpn');
    }

    /** Messages captured by the "array" mailer (phpunit.xml sets MAIL_MAILER=array). */
    private function sentMail(): array
    {
        return collect(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages())->map(fn ($m) => $m->getOriginalMessage())->all();
    }

    private function linkIn($message): string
    {
        preg_match('#https://vpn\.test(/vpn/password/[A-Za-z0-9_-]+)#', $message->getTextBody(), $m);
        return $m[1] ?? '';
    }

    public function test_invited_person_sets_a_password_signs_in_and_adds_a_device(): void
    {
        $w = $this->world();
        $this->onboard($w);
        $this->console($w['acmeAdmin'])->post("/companies/{$w['acme']->id}/vpn/users/invite", ['name' => 'Dan Doe', 'email' => 'Dan@NoMicrosoft.example'])->assertSessionHas('success');
        $dan = VpnUser::where('upn', 'dan@nomicrosoft.example')->firstOrFail();
        $this->assertTrue($dan->isLocal());
        $this->assertSame('invited', $dan->status());
        $mail = $this->sentMail();
        $this->assertCount(1, $mail);
        $this->assertSame('Your Acme VPN access', $mail[0]->getSubject());
        $link = $this->linkIn($mail[0]);
        $this->assertNotSame('', $link);
        $this->assertStringContainsString('Dan Doe', $mail[0]->getTextBody());

        auth()->logout();
        $this->get(self::PORTAL . $link)->assertOk()->assertSee('Set your VPN password');
        $this->post(self::PORTAL . $link, ['password' => 'short', 'password_confirmation' => 'short'])->assertStatus(422);
        $this->post(self::PORTAL . $link, ['password' => 'correct horse battery', 'password_confirmation' => 'different horse battery'])->assertStatus(422);
        $this->post(self::PORTAL . $link, ['password' => 'correct horse battery', 'password_confirmation' => 'correct horse battery'])->assertRedirect('https://vpn.test/vpn');
        $this->assertNotSame('correct horse battery', VpnUser::find($dan->id)->password);
        $this->get(self::PORTAL . $link)->assertStatus(410); // single use
        $this->addDevice('Laptop')->assertOk()->assertSee('<svg', false);
        $this->assertCount(1, $this->fw->peers);

        // Sign in again: email → password step (no Microsoft), wrong passwords lock the account.
        $this->post(self::PORTAL . '/vpn/logout');
        $this->post(self::PORTAL . '/vpn/login', ['email' => 'dan@nomicrosoft.example'])->assertOk()->assertSee('Forgot password?');
        $this->assertSame([], $this->ms->calls);
        $this->post(self::PORTAL . '/vpn/login/password', ['email' => 'dan@nomicrosoft.example', 'password' => 'correct horse battery'])->assertRedirect('https://vpn.test/vpn');
        $this->post(self::PORTAL . '/vpn/logout');
        for ($i = 0; $i < 5; $i++) {
            $this->post(self::PORTAL . '/vpn/login/password', ['email' => 'dan@nomicrosoft.example', 'password' => 'wrong password ' . $i])->assertStatus(422);
        }
        $this->assertSame('locked', VpnUser::find($dan->id)->status());
        $this->post(self::PORTAL . '/vpn/login/password', ['email' => 'dan@nomicrosoft.example', 'password' => 'correct horse battery'])->assertStatus(422)->assertSee('Too many wrong passwords');
        $this->post(self::PORTAL . '/vpn/login/password', ['email' => 'nobody@nomicrosoft.example', 'password' => 'whatever whatever'])->assertStatus(422)->assertSee('Wrong email or password');
    }

    /** The current authenticator code for a secret (as the phone app would show it). */
    private function otp(string $secret): string
    {
        return (new \PragmaRX\Google2FA\Google2FA())->getCurrentOtp($secret);
    }

    /** Invites and sets a password; returns [person, password link response]. */
    private function inviteAndSetPassword(array $w, array $extra = []): array
    {
        $this->console($w['acmeAdmin'])->post("/companies/{$w['acme']->id}/vpn/users/invite", ['name' => 'Dan Doe', 'email' => 'dan@nomicrosoft.example'] + $extra)->assertSessionHas('success');
        $mail = collect($this->sentMail())->last();
        $link = $this->linkIn($mail);
        auth()->logout();
        $res = $this->post(self::PORTAL . $link, ['password' => 'correct horse battery', 'password_confirmation' => 'correct horse battery']);
        return [VpnUser::where('upn', 'dan@nomicrosoft.example')->firstOrFail(), $res, $mail];
    }

    public function test_vpn_emails_go_through_mandrill_from_its_address_when_it_is_set_up(): void
    {
        $w = $this->world();
        $this->onboard($w);
        // VPN settings have their own SMTP and From; Mandrill (PFCentral's mail) wins over both.
        VpnSettings::set('smtp_host', 'smtp.example.test');
        VpnSettings::set('mail_from_address', 'vpn@other.example');
        config(['mail.default' => 'mandrill', 'mail.mailers.mandrill' => ['transport' => 'array', 'key' => 'md-test'], 'mail.from.address' => 'noreply@cfbtel.com']);
        $this->assertTrue(\App\Services\Vpn\VpnMailer::usesMandrill());
        $this->console($w['global'])->get('/vpn-admin/settings')->assertOk()->assertSee('go through')->assertSee('noreply@cfbtel.com');

        $this->grant($w['global'], $w['acme'], 'ann');
        $this->staffSignIn('ann')->assertRedirect();
        $this->addDevice('Ann laptop')->assertOk();
        auth()->logout();
        $this->postJson(self::PORTAL . '/vpn/app/support', [
            'public_keys' => [VpnDevice::firstOrFail()->public_key], 'contact_email' => 'ann@acme.com', 'subject' => 'Slow', 'message' => 'Slow VPN', 'report' => 'report',
        ])->assertOk()->assertJson(['emailed' => true]);
        $sent = collect(app('mail.manager')->mailer('mandrill')->getSymfonyTransport()->messages())->map(fn ($m) => $m->getOriginalMessage());
        $this->assertCount(1, $sent);
        $this->assertSame('noreply@cfbtel.com', $sent[0]->getFrom()[0]->getAddress());
        $this->assertMatchesRegularExpression('/^\[VPN-\d{5}\] Slow/', $sent[0]->getSubject());
    }

    public function test_required_two_factor_is_set_up_at_first_sign_in_and_asked_every_time(): void
    {
        $w = $this->world();
        $this->onboard($w);
        $base = "/companies/{$w['acme']->id}/vpn";
        $this->console($w['acmeReader'])->post("$base/mfa", ['mfa_mode' => 'required'])->assertForbidden();
        $this->console($w['acmeAdmin'])->post("$base/mfa", ['mfa_mode' => 'required'])->assertSessionHas('success');
        $this->assertSame('required', VpnCompany::where('company_id', $w['acme']->id)->value('mfa_mode'));

        [$dan, $res, $mail] = $this->inviteAndSetPassword($w);
        $this->assertStringContainsString('Google Authenticator', $mail->getTextBody());
        // Password set, but no access until two-factor is set up.
        $res->assertRedirect('/vpn/mfa/setup');
        $this->get(self::PORTAL . '/vpn')->assertDontSee('Your devices');
        $this->addDevice('Laptop')->assertRedirect('/vpn');
        $this->get(self::PORTAL . '/vpn/mfa/setup')->assertOk()->assertSee('Set up two-factor sign-in')->assertSee('<svg', false)->assertSee('requires two-factor')->assertDontSee('Not now');
        $secret = session('vpn_portal_mfa_secret')['secret'];
        $this->post(self::PORTAL . '/vpn/mfa/setup', ['code' => '000000'])->assertStatus(422)->assertSee('didn&#039;t match', false);
        $page = $this->post(self::PORTAL . '/vpn/mfa/setup', ['code' => $this->otp($secret)])->assertOk()->assertSee('Save your recovery codes');
        preg_match_all('/\b[A-Z2-9]{5}-[A-Z2-9]{5}\b/', $page->getContent(), $m);
        $this->assertCount(8, array_unique($m[0]));
        $dan->refresh();
        $this->assertNotNull($dan->mfa_enabled_at);
        $this->assertNotSame($secret, \Illuminate\Support\Facades\DB::table('vpn_users')->where('id', $dan->id)->value('mfa_secret')); // stored encrypted
        $this->addDevice('Laptop')->assertOk();

        // Next sign-in: password, then the code. The code used at setup can't be used again.
        $this->post(self::PORTAL . '/vpn/logout');
        $this->post(self::PORTAL . '/vpn/login/password', ['email' => 'dan@nomicrosoft.example', 'password' => 'correct horse battery'])->assertRedirect('/vpn/mfa');
        $this->get(self::PORTAL . '/vpn')->assertDontSee('Your devices');
        $this->post(self::PORTAL . '/vpn/mfa', ['code' => $this->otp($secret)])->assertStatus(422);
        $dan->update(['mfa_last_step' => 0]); // as if 30 seconds passed
        $this->post(self::PORTAL . '/vpn/mfa', ['code' => $this->otp($secret)])->assertRedirect('https://vpn.test/vpn');
        $this->get(self::PORTAL . '/vpn')->assertOk()->assertSee('Two-factor sign-in')->assertDontSee('Turn two-factor off');

        // A recovery code works once.
        $this->post(self::PORTAL . '/vpn/logout');
        $this->post(self::PORTAL . '/vpn/login/password', ['email' => 'dan@nomicrosoft.example', 'password' => 'correct horse battery'])->assertRedirect('/vpn/mfa');
        $this->post(self::PORTAL . '/vpn/mfa', ['code' => strtolower($m[0][0])])->assertRedirect('https://vpn.test/vpn');
        $this->post(self::PORTAL . '/vpn/logout');
        $this->post(self::PORTAL . '/vpn/login/password', ['email' => 'dan@nomicrosoft.example', 'password' => 'correct horse battery']);
        $this->post(self::PORTAL . '/vpn/mfa', ['code' => $m[0][0]])->assertStatus(422);
        $this->assertCount(7, $dan->fresh()->mfa_recovery);

        // Five wrong codes lock the account.
        for ($i = 0; $i < 3; $i++) {
            $this->post(self::PORTAL . '/vpn/mfa', ['code' => '11111' . $i])->assertStatus(422);
        }
        $this->post(self::PORTAL . '/vpn/mfa', ['code' => '222222'])->assertStatus(429)->assertSee('Too many wrong codes');
        $this->assertSame('locked', $dan->fresh()->status());

        // Lost phone: the admin resets it; the next sign-in sets it up again.
        $dan->fresh()->update(['locked_until' => null, 'failed_logins' => 0]);
        $this->console($w['acmeAdmin'])->get($base)->assertOk()->assertSee('2FA on')->assertSee('Reset two-factor');
        $this->console($w['acmeAdmin'])->post("$base/users/{$dan->id}/mfa-reset")->assertSessionHas('success');
        $this->assertNull($dan->fresh()->mfa_secret);
        auth()->logout();
        $this->post(self::PORTAL . '/vpn/login/password', ['email' => 'dan@nomicrosoft.example', 'password' => 'correct horse battery'])->assertRedirect('/vpn/mfa/setup');
    }

    public function test_optional_two_factor_can_be_turned_on_and_off_and_set_per_person(): void
    {
        $w = $this->world();
        $this->onboard($w);
        $base = "/companies/{$w['acme']->id}/vpn";
        [$dan, $res] = $this->inviteAndSetPassword($w);
        $res->assertRedirect('https://vpn.test/vpn'); // optional (the default): straight in
        $this->get(self::PORTAL . '/vpn')->assertOk()->assertSee('Turn on two-factor');

        $this->get(self::PORTAL . '/vpn/mfa/setup')->assertOk()->assertSee('Not now');
        $secret = session('vpn_portal_mfa_secret')['secret'];
        $this->post(self::PORTAL . '/vpn/mfa/setup', ['code' => $this->otp($secret)])->assertOk()->assertSee('Two-factor sign-in is on');
        $this->get(self::PORTAL . '/vpn')->assertSee('Turn two-factor off');
        $dan->update(['mfa_last_step' => 0]);
        $this->post(self::PORTAL . '/vpn/mfa/disable', ['code' => '000000'])->assertStatus(422);
        $this->post(self::PORTAL . '/vpn/mfa/disable', ['code' => $this->otp($secret)])->assertOk()->assertSee('Two-factor sign-in is off');
        $this->assertNull($dan->fresh()->mfa_enabled_at);

        // The admin makes it required for Dan only: his session ends and the next sign-in sets it up.
        $this->console($w['acmeAdmin'])->post("$base/users/{$dan->id}/update", ['name' => 'Dan Doe', 'email' => 'dan@nomicrosoft.example', 'mfa_mode' => 'required'])->assertSessionHas('success');
        $this->assertSame('required', $dan->fresh()->mfa_mode);
        $this->console($w['acmeAdmin'])->get($base)->assertSee('2FA required, not set up yet');
        auth()->logout();
        $this->get(self::PORTAL . '/vpn')->assertDontSee('Your devices');
        $this->post(self::PORTAL . '/vpn/login/password', ['email' => 'dan@nomicrosoft.example', 'password' => 'correct horse battery'])->assertRedirect('/vpn/mfa/setup');

        // Back to the company setting (optional): straight in again.
        $this->console($w['acmeAdmin'])->post("$base/users/{$dan->id}/update", ['name' => 'Dan Doe', 'email' => 'dan@nomicrosoft.example', 'mfa_mode' => ''])->assertSessionHas('success');
        $this->assertNull($dan->fresh()->mfa_mode);
        auth()->logout();
        $this->post(self::PORTAL . '/vpn/login/password', ['email' => 'dan@nomicrosoft.example', 'password' => 'correct horse battery'])->assertRedirect('https://vpn.test/vpn');

        // Invited with two-factor required on a company where it's optional.
        $this->console($w['acmeAdmin'])->post("$base/users/invite", ['name' => 'Eve', 'email' => 'eve@nomicrosoft.example', 'mfa_mode' => 'required'])->assertSessionHas('success');
        $this->assertSame('required', VpnUser::where('upn', 'eve@nomicrosoft.example')->value('mfa_mode'));
    }

    public function test_two_factor_keeps_the_windows_app_sign_in_going(): void
    {
        $w = $this->world();
        $this->onboard($w);
        $this->console($w['acmeAdmin'])->post("/companies/{$w['acme']->id}/vpn/mfa", ['mfa_mode' => 'required']);
        [$dan] = $this->inviteAndSetPassword($w);
        $this->get(self::PORTAL . '/vpn/mfa/setup');
        $secret = session('vpn_portal_mfa_secret')['secret'];
        $this->post(self::PORTAL . '/vpn/mfa/setup', ['code' => $this->otp($secret)])->assertOk();
        $this->post(self::PORTAL . '/vpn/logout');
        // The app's sign-in page sends the person to sign in and back afterwards.
        $this->withSession(['vpn_intended' => '/vpn/app?state=abc'])->post(self::PORTAL . '/vpn/login/password', ['email' => 'dan@nomicrosoft.example', 'password' => 'correct horse battery'])->assertRedirect('/vpn/mfa');
        $dan->update(['mfa_last_step' => 0]);
        $this->post(self::PORTAL . '/vpn/mfa', ['code' => $this->otp($secret)])->assertRedirect('/vpn/app?state=abc');
    }

    public function test_admins_edit_resend_and_remove_email_accounts_and_password_reset_signs_out_old_sessions(): void
    {
        $w = $this->world();
        $this->onboard($w);
        $this->console($w['acmeAdmin'])->post("/companies/{$w['acme']->id}/vpn/users/invite", ['name' => 'Dan', 'email' => 'dan@nomicrosoft.example']);
        $first = $this->linkIn($this->sentMail()[0]);
        $dan = VpnUser::where('upn', 'dan@nomicrosoft.example')->first();
        $this->console($w['acmeAdmin'])->post("/companies/{$w['acme']->id}/vpn/users/{$dan->id}/resend")->assertSessionHas('success');
        $second = $this->linkIn($this->sentMail()[1]);
        auth()->logout();
        $this->get(self::PORTAL . $first)->assertStatus(410); // resend replaces the old link
        $this->post(self::PORTAL . $second, ['password' => 'first password 123', 'password_confirmation' => 'first password 123'])->assertRedirect();
        $this->get(self::PORTAL . '/vpn')->assertSee('Your devices');

        // Edit: new email; duplicates refused; other companies can't edit.
        $this->console($w['acmeAdmin'])->post("/companies/{$w['acme']->id}/vpn/users/{$dan->id}/update", ['name' => 'Dan D', 'email' => 'dan.d@nomicrosoft.example'])->assertSessionHas('success');
        $this->console($w['acmeAdmin'])->post("/companies/{$w['acme']->id}/vpn/users/invite", ['name' => 'Again', 'email' => 'dan.d@nomicrosoft.example'])->assertSessionHasErrors('invite');
        $this->console($w['betaAdmin'])->post("/companies/{$w['acme']->id}/vpn/users/{$dan->id}/update", ['name' => 'X', 'email' => 'x@y.example'])->assertForbidden();
        $this->assertSame('dan.d@nomicrosoft.example', VpnUser::find($dan->id)->upn);

        // Forgot password: same answer for unknown emails; a reset signs out the old session.
        auth()->logout();
        $this->post(self::PORTAL . '/vpn/forgot', ['email' => 'unknown@nomicrosoft.example'])->assertOk()->assertSee('If that address has a VPN account');
        $this->assertCount(2, $this->sentMail());
        $this->post(self::PORTAL . '/vpn/forgot', ['email' => 'dan.d@nomicrosoft.example'])->assertOk();
        $reset = $this->sentMail()[2];
        $this->assertStringContainsString('Reset your Acme VPN password', $reset->getSubject());
        $this->post(self::PORTAL . $this->linkIn($reset), ['password' => 'second password 456', 'password_confirmation' => 'second password 456'])->assertRedirect();
        $this->addDevice('Phone')->assertOk();

        // Remove: devices go at once; sync leaves email accounts alone otherwise.
        $this->artisan('vpn:sync')->assertSuccessful();
        $this->assertCount(1, $this->fw->peers);
        $this->console($w['acmeAdmin'])->post("/companies/{$w['acme']->id}/vpn/users/{$dan->id}/remove")->assertRedirect();
        $this->assertCount(0, $this->fw->peers);
        $this->assertNull(VpnUser::find($dan->id));
    }

    public function test_email_settings_require_the_link_and_send_a_test(): void
    {
        $w = $this->world();
        $base = ['smtp_encryption' => 'tls', 'invite_subject' => 'Hi', 'mail_from_address' => 'vpn@cfbtel.com'];
        $this->console($w['global'])->post('/vpn-admin/settings/email', $base + ['invite_body' => 'no link here'])->assertSessionHasErrors('invite_body');
        $this->console($w['global'])->post('/vpn-admin/settings/email', $base + ['invite_body' => 'Hello {name}: {link}', 'smtp_password' => 'mail-secret'])->assertSessionHas('success');
        $this->assertSame('mail-secret', VpnSettings::secret('smtp_password'));
        $this->assertStringNotContainsString('mail-secret', (string) \App\Models\SystemSetting::where('key', 'vpn_smtp_password')->value('value'));
        $this->console($w['global'])->post('/vpn-admin/settings/email/test')->assertSessionHas('success');
        $this->assertSame('CFBTEL VPN test email', $this->sentMail()[0]->getSubject());
        $this->console($w['acmeAdmin'])->post('/vpn-admin/settings/email', $base + ['invite_body' => '{link}'])->assertForbidden();
    }

    private function runSetup(string $link, string $code = 'c1')
    {
        auth()->logout();
        $this->get($link)->assertOk()->assertSee('Continue to Microsoft');
        $t = parse_url($link, PHP_URL_QUERY);
        parse_str($t, $q);
        $this->post(self::PORTAL . '/vpn/setup', ['t' => $q['t']])->assertRedirect('https://login.microsoftonline.com/setup');
        $state = session('vpn_ms_setup')['state'];
        return $this->get(self::PORTAL . '/vpn/setup/callback?' . http_build_query(['code' => $code, 'state' => $state]));
    }

    public function test_one_click_setup_for_pfcentral_sign_in_creates_the_msp_app_and_saves_it(): void
    {
        $w = $this->world();
        // Without CFBTEL's setup app the button isn't offered.
        $this->console($w['global'])->get('/system/sign-in')->assertOk()->assertDontSee(route('system.signin.auto'), false);

        $setup = new FakeSetup();
        MicrosoftSetup::$factory = fn () => $setup;
        $this->console($w['global'])->get('/system/sign-in')->assertOk()->assertSee(route('system.signin.auto'), false);
        $this->console($w['acmeAdmin'])->post('/system/sign-in/auto-setup')->assertForbidden();

        $link = $this->console($w['global'])->post('/system/sign-in/auto-setup')->headers->get('Location');
        $this->assertStringStartsWith('https://vpn.test/vpn/setup?t=', $link);
        $callback = route('login.microsoft.callback');

        $this->runSetup($link)->assertOk()->assertSee('Microsoft 365 sign-in is set up')->assertSee('PFCentral sign-in');
        $this->assertSame(['redeem c1', "provision new {$callback} [PFCentral sign-in]", 'cleanup'], $setup->calls);
        $this->assertSame(self::ACME_TID, \App\Services\Auth\MspSignIn::tenantId());
        $this->assertSame('dddddddd-0000-0000-0000-000000000001', \App\Services\Auth\MspSignIn::clientId());
        $this->assertSame('auto-secret-1', \App\Services\Auth\MspSignIn::clientSecret());
        $this->assertNull(\App\Models\Vpn\VpnCompany::where('ms_tenant_id', self::ACME_TID)->first(), 'no company was touched');

        // The link works once.
        $this->get($link)->assertStatus(400);

        // Running it again reuses the app and replaces its secret.
        $setup->calls = [];
        $link = $this->console($w['global'])->post('/system/sign-in/auto-setup')->headers->get('Location');
        $this->runSetup($link, 'c2')->assertOk();
        // (The scheme of the console address may differ in tests after a request on the VPN page's https host.)
        $calls = array_map(fn ($c) => preg_replace('#https?://localhost#', 'console', $c), $setup->calls);
        $this->assertSame(['redeem c2', 'provision dddddddd-0000-0000-0000-000000000001 console/login/microsoft/callback [PFCentral sign-in]', 'remove-secret ffffffff-0000-0000-0000-000000000001', 'cleanup'], $calls);
        $this->assertSame('auto-secret-2', \App\Services\Auth\MspSignIn::clientSecret());
        $this->console($w['global'])->get('/system/sign-in')->assertOk()->assertSee('Connected')->assertSee('Run the automatic setup again');
    }

    public function test_one_click_setup_creates_the_companys_own_app_and_saves_it(): void
    {
        $w = $this->world();
        // Without CFBTEL's setup app the button isn't offered.
        $this->console($w['acmeAdmin'])->get("/companies/{$w['acme']->id}/vpn")->assertOk()->assertDontSee('Connect Microsoft 365 automatically');

        $setup = new FakeSetup();
        MicrosoftSetup::$factory = fn () => $setup;
        $page = $this->console($w['acmeAdmin'])->get("/companies/{$w['acme']->id}/vpn")->assertOk()->assertSee('Connect Microsoft 365 automatically');
        $link = $page->viewData('setupLink');
        $this->assertStringStartsWith('https://vpn.test/vpn/setup?t=', $link);
        $this->console($w['acmeReader'])->get("/companies/{$w['acme']->id}/vpn")->assertOk()->assertDontSee('Connect Microsoft 365 automatically');

        $this->runSetup($link)->assertOk()->assertSee('Microsoft 365 connected')->assertSee('Acme Inc');
        $this->assertSame(['redeem c1', 'provision new https://vpn.test/vpn/callback', 'cleanup'], $setup->calls);
        $vc = VpnCompany::where('company_id', $w['acme']->id)->first();
        $this->assertSame(self::ACME_TID, $vc->ms_tenant_id);
        $this->assertSame('dddddddd-0000-0000-0000-000000000001', $vc->ms_client_id);
        $this->assertSame('auto-secret-1', $vc->ms_client_secret);
        $this->assertTrue($vc->hasOwnApp());
        $this->assertSame(['acme.com', 'acme.onmicrosoft.com'], $vc->domains());
        $this->assertTrue($vc->ms_secret_expires_at->isFuture());
        $this->assertNotNull($vc->ms_auto_setup_at);

        // The link works once.
        auth()->logout();
        $this->get($link)->assertStatus(400)->assertSee('Link expired');

        // Running it again reuses the app, gives it a new secret and removes the old one.
        $this->travel(2)->seconds();
        $again = $this->console($w['global'])->get("/companies/{$w['acme']->id}/vpn")->assertOk()->assertSee('Run automatic setup again')->viewData('setupLink');
        $setup->calls = [];
        $this->runSetup($again, 'c2')->assertOk()->assertSee('updated');
        $this->assertSame(['redeem c2', 'provision dddddddd-0000-0000-0000-000000000001 https://vpn.test/vpn/callback', 'remove-secret ffffffff-0000-0000-0000-000000000001', 'cleanup'], $setup->calls);
        $this->assertSame('auto-secret-2', $vc->fresh()->ms_client_secret);

        // The same tenant can't be linked to a second company, and domains stay unique.
        $this->travel(2)->seconds();
        $betaLink = $this->console($w['betaAdmin'])->get("/companies/{$w['beta']->id}/vpn")->assertOk()->viewData('setupLink');
        $this->runSetup($betaLink, 'c3')->assertStatus(409)->assertSee('already linked to another company');
        $this->assertNull(VpnCompany::where('company_id', $w['beta']->id)->value('ms_tenant_id'));

        // A forged or tampered link is refused.
        $this->get(self::PORTAL . '/vpn/setup?t=nope')->assertStatus(400);
    }

    public function test_setup_provisions_an_entra_app_through_microsoft_graph(): void
    {
        MicrosoftSetup::$sleep = fn (int $s) => null;
        $graph = '00000000-aaaa-0000-0000-000000000000';
        $addPasswordTries = 0;
        Http::fake(function ($req) use ($graph, &$addPasswordTries) {
            $m = $req->method();
            $u = urldecode($req->url());
            return match (true) {
                $m === 'GET' && str_contains($u, "servicePrincipals(appId='00000003-0000-0000-c000-000000000000')") => Http::response(['id' => $graph,
                    'oauth2PermissionScopes' => collect(['openid' => 's1', 'profile' => 's2', 'email' => 's3', 'User.Read' => 's4', 'Mail.Read' => 's9'])->map(fn ($id, $v) => ['id' => $id, 'value' => $v])->values()->all(),
                    'appRoles' => [['id' => 'r1', 'value' => 'User.Read.All', 'allowedMemberTypes' => ['Application']], ['id' => 'r2', 'value' => 'GroupMember.Read.All', 'allowedMemberTypes' => ['Application']], ['id' => 'r9', 'value' => 'Mail.Read', 'allowedMemberTypes' => ['Application']]]]),
                $m === 'POST' && str_ends_with($u, '/v1.0/applications') => Http::response(['id' => 'obj-1', 'appId' => 'app-1'], 201),
                $m === 'POST' && str_ends_with($u, '/applications/obj-1/addPassword') => ++$addPasswordTries < 2
                    ? Http::response(['error' => ['code' => 'Request_ResourceNotFound', 'message' => 'Resource does not exist']], 404)
                    : Http::response(['keyId' => 'KEY-1', 'secretText' => 'the-secret']),
                $m === 'GET' && str_contains($u, "servicePrincipals(appId='app-1')") => Http::response(['error' => ['code' => 'Request_ResourceNotFound']], 404),
                $m === 'POST' && str_ends_with($u, '/v1.0/servicePrincipals') => Http::response(['id' => 'sp-1'], 201),
                $m === 'GET' && str_contains($u, '/servicePrincipals/sp-1/appRoleAssignments') => Http::response(['value' => []]),
                $m === 'POST' && str_contains($u, "/servicePrincipals/{$graph}/appRoleAssignedTo") => Http::response(['id' => 'ra'], 201),
                $m === 'GET' && str_contains($u, '/oauth2PermissionGrants') => Http::response(['value' => []]),
                $m === 'POST' && str_ends_with($u, '/v1.0/oauth2PermissionGrants') => Http::response(['id' => 'g'], 201),
                default => Http::response(['error' => ['message' => "unexpected {$m} {$u}"]], 500),
            };
        });
        $app = (new MicrosoftSetup('setup-id', 'setup-secret'))->withToken('delegated')->provision('https://vpn.test/vpn/callback');
        $this->assertSame(['app-1', 'the-secret', 'obj-1', 'key-1', false], [$app['client_id'], $app['client_secret'], $app['object_id'], $app['key_id'], $app['reused']]);
        $this->assertEqualsWithDelta(now()->addYears(2)->getTimestamp(), $app['expires_at']->getTimestamp(), 5);
        $this->assertSame(2, $addPasswordTries);

        $sent = collect(Http::recorded())->map(fn ($r) => $r[0]);
        $create = $sent->first(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/v1.0/applications'));
        $this->assertSame('AzureADMyOrg', $create['signInAudience']);
        $this->assertSame(['https://vpn.test/vpn/callback'], $create['web']['redirectUris']);
        $this->assertEqualsCanonicalizing(
            [['id' => 's1', 'type' => 'Scope'], ['id' => 's2', 'type' => 'Scope'], ['id' => 's3', 'type' => 'Scope'], ['id' => 's4', 'type' => 'Scope'], ['id' => 'r1', 'type' => 'Role'], ['id' => 'r2', 'type' => 'Role']],
            $create['requiredResourceAccess'][0]['resourceAccess']
        );
        $roles = $sent->filter(fn ($r) => str_contains($r->url(), 'appRoleAssignedTo'))->map(fn ($r) => $r->data())->values()->all();
        $this->assertSame([['principalId' => 'sp-1', 'resourceId' => $graph, 'appRoleId' => 'r1'], ['principalId' => 'sp-1', 'resourceId' => $graph, 'appRoleId' => 'r2']], $roles);
        $grant = $sent->first(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/oauth2PermissionGrants'));
        $this->assertSame(['clientId' => 'sp-1', 'consentType' => 'AllPrincipals', 'resourceId' => $graph, 'scope' => 'openid profile email User.Read'], $grant->data());
        $this->assertTrue($sent->every(fn ($r) => $r->header('Authorization')[0] === 'Bearer delegated'));
    }

    public function test_settings_save_the_one_click_setup_app(): void
    {
        $w = $this->world();
        $this->console($w['global'])->get('/vpn-admin/settings')->assertOk()->assertSee('One-click Microsoft 365 setup')->assertSee('https://vpn.test/vpn/setup/callback');
        $this->console($w['global'])->post('/vpn-admin/settings/setup', ['setup_client_id' => 'bad'])->assertSessionHasErrors('setup_client_id');
        $this->console($w['global'])->post('/vpn-admin/settings/setup', ['setup_client_id' => '99999999-0000-0000-0000-000000000009', 'setup_client_secret' => 'shh'])->assertSessionHas('success');
        $this->assertTrue(MicrosoftSetup::make()->configured());
        $this->assertNotSame('shh', \App\Models\SystemSetting::where('key', 'vpn_setup_client_secret')->value('value'));
        $this->console($w['acmeAdmin'])->post('/vpn-admin/settings/setup', ['setup_client_id' => ''])->assertForbidden();
        $this->assertStringContainsString('https://login.microsoftonline.com/organizations/oauth2/v2.0/authorize', MicrosoftSetup::make()->authorizeUrl()['url']);
        $this->assertStringContainsString('Application.ReadWrite.All', urldecode(MicrosoftSetup::make()->authorizeUrl()['url']));
        $this->assertStringContainsString('graph.microsoft.com/User.Read', urldecode(MicrosoftSetup::make()->authorizeUrl()['url']));
    }

    public function test_setup_refuses_a_port_an_existing_tunnel_uses(): void
    {
        $w = $this->world();
        $this->fw->tunnels[] = ['ref' => 'tun_wg0', 'name' => 'Office', 'listen_port' => 51820, 'public_key' => WireGuard::keyPair()['public']];
        $this->console($w['global'])->post("/companies/{$w['acme']->id}/vpn/firewalls/{$w['firewall']->id}/enable")->assertRedirect();
        $site = VpnSite::firstOrFail();
        $this->console($w['global'])->post("/companies/{$w['acme']->id}/vpn/sites/{$site->id}/setup", ['lan' => 'lan', 'wan' => 'wan', 'port' => 51820, 'subnet' => '10.0.70.0/24', 'endpoint_host' => 'vpn.acme.com'])
            ->assertRedirect()->assertSessionHas('error', fn ($m) => str_contains($m, 'tun_wg0 (Office)') && str_contains($m, 'Adopt'));
        $this->assertSame([], $this->fw->setups);
        $this->assertNotSame('error', $site->fresh()->status);
    }

    private const G_VPN = '99999999-0000-0000-0000-00000000000a';
    private const G_SITE2 = '99999999-0000-0000-0000-00000000000b';
    private const G_OTHER = '99999999-0000-0000-0000-00000000000c';

    /** Acme onboarded, plus a second ready firewall ("Acme Branch"). */
    private function twoSites(array $w): array
    {
        $site1 = $this->onboard($w);
        $fw2 = Firewall::create(['name' => 'Acme Branch', 'company_id' => $w['acme']->id, 'url' => 'https://fw2.acme.test', 'os_type' => 'pfsense', 'auth_method' => 'basic', 'api_key' => 'u', 'api_secret' => 'p', 'netgate_id' => 'acme-br']);
        $site2 = VpnSite::create(['company_id' => $w['acme']->id, 'firewall_id' => $fw2->id, 'status' => 'ready', 'tunnel' => 'tun_wg1', 'tunnel_name' => 'CFBTEL VPN',
            'server_public_key' => WireGuard::keyPair()['public'], 'subnet' => '10.0.71.0/24', 'endpoint' => 'branch.acme.com:51820', 'dns' => '10.0.71.1']);
        return [$site1, $site2];
    }

    public function test_a_company_group_gives_its_members_vpn_access(): void
    {
        $w = $this->world();
        $site = $this->onboard($w);
        $this->ms->groups = [self::G_VPN => ['name' => 'VPN', 'members' => [$this->ms->people['carl']['oid']]]];

        $base = "/companies/{$w['acme']->id}/vpn";
        $this->ms->groups[self::G_OTHER] = ['name' => 'Sales', 'members' => []];

        // The picker searches as you type (JSON), only for the company's admins.
        $this->console($w['acmeReader'])->getJson("$base/groups/search?q=vp")->assertForbidden();
        $this->console($w['acmeAdmin'])->getJson("$base/groups/search?q=vp")->assertOk()
            ->assertJsonFragment(['id' => self::G_VPN, 'name' => 'VPN'])->assertJsonMissing(['name' => 'Sales']);
        $this->console($w['acmeAdmin'])->get($base)->assertOk()->assertSee('Save groups')->assertSee('msGroupPicker');

        // The admin picks two groups and saves once.
        $this->console($w['acmeReader'])->post("$base/groups/company", ['ms_groups' => [self::G_VPN]])->assertForbidden();
        $this->console($w['acmeAdmin'])->post("$base/groups/company", ['ms_groups' => [strtoupper(self::G_VPN), self::G_OTHER]])->assertSessionHas('success');
        $vc = VpnCompany::where('company_id', $w['acme']->id)->firstOrFail();
        $this->assertSame([['id' => self::G_VPN, 'name' => 'VPN'], ['id' => self::G_OTHER, 'name' => 'Sales']], $vc->companyGroups());
        // A group that isn't in this Microsoft 365 is refused and nothing changes.
        $this->console($w['acmeAdmin'])->post("$base/groups/company", ['ms_groups' => [self::G_VPN, '99999999-0000-0000-0000-0000000000ff']])->assertSessionHas('error');
        $this->assertCount(2, $vc->fresh()->companyGroups());
        // Removing a chip and saving drops that group.
        $this->console($w['acmeAdmin'])->post("$base/groups/company", ['ms_groups' => [self::G_VPN]])->assertSessionHas('success');
        $this->assertSame([['id' => self::G_VPN, 'name' => 'VPN']], $vc->fresh()->companyGroups());

        // Carl isn't on the list but is in the group: he gets in and is added automatically.
        $this->staffSignIn('carl')->assertRedirect('https://vpn.test/vpn');
        $carl = VpnUser::where('ms_oid', $this->ms->people['carl']['oid'])->firstOrFail();
        $this->assertTrue($carl->viaGroup());
        $this->addDevice('Carl laptop')->assertOk();
        // Bob is in no group and not on the list.
        $this->staffSignIn('bob')->assertForbidden();

        // Carl leaves the group: the sync removes his access and his device.
        $this->ms->groups[self::G_VPN]['members'] = [];
        $this->artisan('vpn:sync')->assertSuccessful();
        $this->assertNull(VpnUser::find($carl->id));
        $this->assertSame([], $this->fw->peers);
        // …and signing in again is refused.
        $this->staffSignIn('carl')->assertForbidden();
    }

    public function test_the_old_single_company_group_carries_over(): void
    {
        $w = $this->world();
        $this->onboard($w);
        $vc = VpnCompany::where('company_id', $w['acme']->id)->firstOrFail();
        \Illuminate\Support\Facades\DB::table('vpn_companies')->where('id', $vc->id)->update(['access_groups' => null, 'access_group_id' => strtoupper(self::G_VPN), 'access_group_name' => 'VPN']);
        (require database_path('migrations/2026_11_20_000002_add_access_groups_to_vpn_companies.php'))->up();
        $vc->refresh();
        $this->assertSame([['id' => self::G_VPN, 'name' => 'VPN']], $vc->companyGroups());
        $this->assertNull($vc->access_group_id);
        $this->assertSame([self::G_VPN], \App\Services\Vpn\VpnAccess::groupIds($vc));
    }

    public function test_vpn_can_be_turned_off_when_only_switched_off_devices_are_left(): void
    {
        $w = $this->world();
        $site = $this->onboard($w);
        $this->grant($w['global'], $w['acme'], 'ann');
        $this->staffSignIn('ann')->assertRedirect();
        $this->addDevice('Ann phone')->assertOk();
        $this->addDevice('Ann laptop')->assertOk();
        $url = "/companies/{$w['acme']->id}/vpn/sites/{$site->id}/delete";

        // A device that can still connect blocks it, and is named.
        $this->console($w['acmeAdmin'])->post($url)->assertSessionHas('error', fn ($m) => str_contains($m, 'Ann phone (ann@acme.com)'));

        // The firewall is down when the devices are removed: they stay "being removed".
        $this->fw->down = true;
        foreach (VpnDevice::all() as $d) {
            $this->console($w['acmeAdmin'])->post("/companies/{$w['acme']->id}/vpn/devices/{$d->id}/remove");
        }
        $this->assertSame(2, VpnDevice::whereNotNull('revoked_at')->count());

        // Firewall back: turning off finishes removing them (peers gone from the firewall) and works.
        $this->fw->down = false;
        $this->console($w['acmeAdmin'])->post($url)->assertSessionHas('success');
        $this->assertNull(VpnSite::find($site->id));
        $this->assertSame([], $this->fw->peers);
        $this->assertSame(0, VpnDevice::count());
    }

    public function test_turning_off_vpn_with_the_firewall_down_names_the_leftover_peers(): void
    {
        $w = $this->world();
        $site = $this->onboard($w);
        $this->grant($w['global'], $w['acme'], 'ann');
        $this->staffSignIn('ann')->assertRedirect();
        $this->addDevice('Ann phone')->assertOk();
        $id = VpnDevice::firstOrFail()->id;
        $this->fw->down = true;
        $this->console($w['acmeAdmin'])->post("/companies/{$w['acme']->id}/vpn/devices/{$id}/remove");
        $this->console($w['acmeAdmin'])->post("/companies/{$w['acme']->id}/vpn/sites/{$site->id}/delete")
            ->assertSessionHas('warning', fn ($m) => str_contains($m, "cfbvpn-{$id}"));
        $this->assertNull(VpnSite::find($site->id));
    }

    public function test_the_windows_app_checks_the_office_firewall_for_its_device(): void
    {
        $w = $this->world();
        $this->onboard($w);
        $this->grant($w['global'], $w['acme'], 'ann');
        $this->staffSignIn('ann')->assertRedirect();
        $this->addDevice('Ann laptop')->assertOk();
        $device = VpnDevice::firstOrFail();
        auth()->logout();
        $url = self::PORTAL . '/vpn/app/status';

        $this->postJson($url, ['public_key' => 'nope'])->assertStatus(422);
        $this->postJson($url, ['public_key' => WireGuard::keyPair()['public']])->assertNotFound();
        $this->postJson($url, ['public_key' => $device->public_key])->assertOk()
            ->assertJson(['office' => 'Acme HQ', 'device_active' => true, 'firewall_reachable' => true, 'tunnel_found' => true, 'listen_port' => 51820, 'peer_present' => true]);

        // The answer is cached for a minute; once it expires a down firewall shows as unreachable.
        \Illuminate\Support\Facades\Cache::flush();
        $this->fw->down = true;
        $this->postJson($url, ['public_key' => $device->public_key])->assertOk()->assertJson(['firewall_reachable' => false, 'peer_present' => false]);

        // A removed device is told so.
        $device->update(['revoked_at' => now()]);
        $this->postJson($url, ['public_key' => $device->public_key])->assertOk()->assertJson(['device_active' => false])
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'removed'));
    }

    public function test_the_windows_app_sends_a_support_ticket_with_its_report(): void
    {
        $w = $this->world();
        $this->onboard($w);
        $this->grant($w['global'], $w['acme'], 'ann');
        $this->staffSignIn('ann')->assertRedirect();
        $this->addDevice('Ann laptop')->assertOk();
        $device = VpnDevice::firstOrFail();
        auth()->logout();
        $url = self::PORTAL . '/vpn/app/support';
        $ticket = [
            'public_keys' => [$device->public_key], 'contact_name' => 'Ann', 'contact_email' => 'ann@acme.com',
            'subject' => "VPN down\r\non PC1", 'message' => 'It says connected but nothing opens.', 'computer' => 'PC1',
            'device_id' => 'CFB-ABC123', 'app' => 'CFBTEL VPN 1.0.6', 'problems' => 1, 'warnings' => 2,
            'report' => "CFBTEL VPN diagnostic report\n== Log (latest) ==\nhandshake did not complete",
        ];

        // Unknown computers can't file tickets (the app falls back to email).
        $this->postJson($url, ['public_keys' => [WireGuard::keyPair()['public']]] + $ticket)->assertForbidden();
        $this->postJson($url, ['contact_email' => 'not an email'] + $ticket)->assertStatus(422);

        $res = $this->postJson($url, $ticket)->assertOk()->assertJson(['emailed' => true]);
        $ref = $res->json('reference');
        $r = \App\Models\Vpn\VpnSupportReport::firstOrFail();
        $this->assertMatchesRegularExpression('/^VPN-\d{5}$/', $ref);
        $this->assertSame($ref, $r->reference());
        $this->assertSame('VPN down on PC1', $r->subject);
        $this->assertSame('ann@acme.com', $r->person);

        $mail = collect($this->sentMail())->last();
        $this->assertSame('help@cfbtel.com', $mail->getTo()[0]->getAddress());
        $this->assertSame('ann@acme.com', $mail->getReplyTo()[0]->getAddress());
        $this->assertStringContainsString("[{$ref}] VPN down on PC1", $mail->getSubject());
        $this->assertStringContainsString('It says connected but nothing opens.', $mail->getTextBody());
        $att = collect($mail->getAttachments())->first();
        $this->assertSame("{$ref}-diagnostics.txt", $att->getFilename());
        $this->assertStringContainsString('handshake did not complete', $att->getBody());

        // Company admins see it; another company's admin can't.
        $base = "/companies/{$w['acme']->id}/vpn";
        $this->console($w['acmeAdmin'])->get($base)->assertOk()->assertSee($ref)->assertSee('VPN down on PC1');
        $this->console($w['acmeAdmin'])->get("$base/support/{$r->id}")->assertOk()->assertSee('handshake did not complete')->assertSee('CFB-ABC123');
        $this->console($w['acmeAdmin'])->get("$base/support/{$r->id}?download=1")->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="' . $ref . '-diagnostics.txt"');
        $other = \App\Models\Company::create(['name' => 'Other Co']);
        $this->console($w['global'])->get("/companies/{$other->id}/vpn/support/{$r->id}")->assertNotFound();

        // At most 5 tickets an hour from one computer.
        for ($i = 0; $i < 4; $i++) {
            $this->postJson($url, $ticket)->assertOk();
        }
        $this->postJson($url, $ticket)->assertStatus(429);
    }

    public function test_access_groups_need_two_firewalls(): void
    {
        $w = $this->world();
        $site = $this->onboard($w);
        $this->console($w['acmeAdmin'])->get("/companies/{$w['acme']->id}/vpn")->assertOk()->assertSee('for companies with 2 or more firewalls')->assertDontSee('+ New access group');
        $this->console($w['acmeAdmin'])->post("/companies/{$w['acme']->id}/vpn/access-groups", ['name' => 'Staff', 'sites' => [$site->id], 'everyone' => 1])->assertSessionHas('error');
        $this->assertSame(0, \App\Models\Vpn\VpnAccessGroup::count());
    }

    public function test_access_groups_decide_who_uses_which_firewall(): void
    {
        $w = $this->world();
        [$site1, $site2] = $this->twoSites($w);
        $this->grant($w['global'], $w['acme'], 'ann');
        $this->grant($w['global'], $w['acme'], 'bob');
        $ann = VpnUser::where('upn', 'ann@acme.com')->firstOrFail();
        $base = "/companies/{$w['acme']->id}/vpn";

        // No groups yet: both firewalls open to everyone with access.
        $this->console($w['acmeAdmin'])->get($base)->assertOk()->assertSee('No groups yet')->assertSee('+ New access group');
        $this->staffSignIn('bob')->assertRedirect();
        $this->post(self::PORTAL . '/vpn/devices', ['site' => $site1->id, 'name' => 'Bob old phone', 'platform' => 'iphone'])->assertOk();

        // First group: the branch for Ann only. The main office is now blocked.
        $this->console($w['acmeReader'])->post("$base/access-groups", ['name' => 'Branch', 'sites' => [$site2->id], 'users' => [$ann->id]])->assertForbidden();
        $this->console($w['acmeAdmin'])->post("$base/access-groups", ['name' => 'Branch', 'sites' => [$site2->id], 'users' => [$ann->id]])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Firewalls in no group are blocked: Acme HQ'));
        $this->console($w['acmeAdmin'])->get($base)->assertOk()->assertSee('blocked — not in any group')->assertSee('group Branch');
        $branch = \App\Models\Vpn\VpnAccessGroup::where('name', 'Branch')->firstOrFail();
        $this->console($w['acmeAdmin'])->post("$base/access-groups", ['name' => 'Branch', 'sites' => [$site1->id]])->assertSessionHasErrors('name');

        // Bob is in no group: no firewall for him, and the sync takes his old device off the main office.
        $this->staffSignIn('bob')->assertRedirect();
        $this->get(self::PORTAL . '/vpn')->assertOk()->assertSee('No VPN location for you yet');
        $this->post(self::PORTAL . '/vpn/devices', ['site' => $site1->id, 'name' => 'Bob phone', 'platform' => 'iphone'])->assertForbidden();
        $this->artisan('vpn:sync')->assertSuccessful();
        $this->assertSame(0, VpnDevice::where('name', 'Bob old phone')->whereNull('revoked_at')->count());

        // Ann sees only the branch.
        $this->staffSignIn('ann')->assertRedirect();
        $this->get(self::PORTAL . '/vpn')->assertOk()->assertSee('Acme Branch')->assertDontSee('Acme HQ');
        $this->post(self::PORTAL . '/vpn/devices', ['site' => $site1->id, 'name' => 'Ann phone', 'platform' => 'iphone'])->assertForbidden();
        $this->post(self::PORTAL . '/vpn/devices', ['site' => $site2->id, 'name' => 'Ann laptop', 'platform' => 'windows'])->assertOk();

        // A second group opens the main office to everyone with VPN access.
        $this->console($w['acmeAdmin'])->post("$base/access-groups", ['name' => 'Main office', 'sites' => [$site1->id], 'everyone' => 1])->assertSessionHas('success');
        $this->staffSignIn('bob')->assertRedirect();
        $this->post(self::PORTAL . '/vpn/devices', ['site' => $site1->id, 'name' => 'Bob phone', 'platform' => 'iphone'])->assertOk();

        // A Microsoft 365 group linked to Branch: Carl isn't on the list but gets VPN access and both firewalls.
        $this->ms->groups = [self::G_SITE2 => ['name' => 'Branch VPN', 'members' => [$this->ms->people['carl']['oid']]]];
        $this->console($w['acmeAdmin'])->getJson("$base/groups/search?q=branch")->assertOk()->assertJsonFragment(['name' => 'Branch VPN']);
        $this->console($w['acmeAdmin'])->post("$base/access-groups/{$branch->id}", ['name' => 'Branch', 'sites' => [$site2->id], 'users' => [$ann->id], 'ms_groups_present' => 1, 'ms_groups' => [self::G_SITE2]])->assertSessionHas('success');
        $this->assertSame(['microsoft', self::G_SITE2, 'Branch VPN'], [$branch->links()->value('provider'), $branch->links()->value('external_id'), $branch->links()->value('external_name')]);
        $this->console($w['acmeAdmin'])->get($base)->assertOk()->assertSee('Branch VPN');
        $this->staffSignIn('carl')->assertRedirect();
        $this->get(self::PORTAL . '/vpn')->assertOk()->assertSee('Acme Branch')->assertSee('Acme HQ');
        $this->post(self::PORTAL . '/vpn/devices', ['site' => $site2->id, 'name' => 'Carl laptop', 'platform' => 'windows'])->assertOk();

        // Ann is taken out of Branch: the sync removes her branch device; she keeps VPN access (added by hand).
        $this->console($w['acmeAdmin'])->post("$base/access-groups/{$branch->id}", ['name' => 'Branch', 'sites' => [$site2->id], 'users' => []])->assertSessionHas('success');
        // Microsoft down: nobody's branch device goes (they might still be in the linked Microsoft group).
        $this->ms->down = true;
        $this->ms->groups[self::G_SITE2]['members'] = [];
        $this->artisan('vpn:sync')->assertSuccessful();
        $this->assertSame(1, VpnDevice::where('name', 'Ann laptop')->whereNull('revoked_at')->count());
        $this->assertSame(1, VpnDevice::where('name', 'Carl laptop')->whereNull('revoked_at')->count());

        // Microsoft back: Ann isn't in Branch any more (devices go, she keeps VPN access, added by hand);
        // Carl left the Microsoft group and loses access.
        $this->ms->down = false;
        $this->artisan('vpn:sync')->assertSuccessful();
        $this->assertSame(0, VpnDevice::where('name', 'Ann laptop')->whereNull('revoked_at')->count());
        $this->assertNotNull(VpnUser::where('upn', 'ann@acme.com')->first());
        $this->assertNull(VpnUser::where('upn', 'carl@acme.com')->first());
        $this->assertSame(0, VpnDevice::where('name', 'Carl laptop')->whereNull('revoked_at')->count());

        // Deleting every group opens both firewalls to everyone again.
        foreach (\App\Models\Vpn\VpnAccessGroup::all() as $g) {
            $this->console($w['acmeAdmin'])->post("$base/access-groups/{$g->id}/delete")->assertSessionHas('success');
        }
        $this->staffSignIn('ann')->assertRedirect();
        $this->get(self::PORTAL . '/vpn')->assertOk()->assertSee('Acme Branch')->assertSee('Acme HQ');
    }

    public function test_old_firewall_groups_become_access_groups_without_closing_anything(): void
    {
        $w = $this->world();
        [$site1, $site2] = $this->twoSites($w);
        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_11_20_000001_create_vpn_access_groups.php', '--force' => true])->assertSuccessful();
        \Illuminate\Support\Facades\DB::table('vpn_sites')->where('id', $site2->id)->update(['access_group_id' => strtoupper(self::G_SITE2), 'access_group_name' => 'Branch VPN']);
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_11_20_000001_create_vpn_access_groups.php', '--force' => true])->assertSuccessful();

        $groups = \App\Services\Vpn\VpnAccess::groups($w['acme']->id);
        $this->assertSame(['Acme Branch', 'Everyone'], $groups->pluck('name')->all());
        $this->assertSame([$site2->id], $groups[0]->sites->pluck('id')->all());
        $this->assertSame(self::G_SITE2, $groups[0]->links->first()->external_id);
        $this->assertTrue($groups[1]->everyone);
        $this->assertSame([$site1->id], $groups[1]->sites->pluck('id')->all());
        $this->assertNull($site2->fresh()->access_group_id);
    }

    public function test_email_accounts_can_be_put_in_access_groups(): void
    {
        $w = $this->world();
        [$site1, $site2] = $this->twoSites($w);
        $this->console($w['acmeAdmin'])->post("/companies/{$w['acme']->id}/vpn/users/invite", ['name' => 'Dana', 'email' => 'dana@acme.com'])->assertRedirect();
        $dana = VpnUser::where('upn', 'dana@acme.com')->firstOrFail();
        $this->console($w['acmeAdmin'])->post("/companies/{$w['acme']->id}/vpn/access-groups", ['name' => 'Branch', 'sites' => [$site2->id], 'users' => [$dana->id]])->assertSessionHas('success');
        $sites = app(\App\Services\Vpn\VpnAccess::class)->sitesFor($dana->fresh());
        $this->assertSame([$site2->id], $sites->pluck('id')->all());
    }

    public function test_pfsense_driver_installs_the_wireguard_package_when_missing(): void
    {
        $fw = Firewall::create(['name' => 'Acme HQ', 'company_id' => Company::create(['name' => 'Acme'])->id, 'url' => 'https://fw.acme.test', 'os_type' => 'pfsense', 'auth_method' => 'basic', 'api_key' => 'u', 'api_secret' => 'p', 'netgate_id' => 'x1']);
        $missing = ['code' => 424, 'status' => 'failed dependency', 'message' => "The requested action requires the 'pfSense-pkg-WireGuard' package but it is not installed."];
        Http::fake([
            'https://fw.acme.test/api/v2/vpn/wireguard/settings*' => Http::sequence()->push($missing, 424)->push(['data' => ['enable' => false]]),
            'https://fw.acme.test/api/v2/vpn/wireguard/tunnels*' => Http::response($missing, 424),
            'https://fw.acme.test/api/v2/system/packages*' => Http::response(['data' => [['name' => 'pfSense-pkg-API']]]),
            'https://fw.acme.test/api/v2/system/package' => Http::response(['data' => ['name' => 'pfSense-pkg-WireGuard']]),
        ]);
        PfSenseWireGuardDriver::$readyWait = 0;
        $driver = new PfSenseWireGuardDriver(new PfSenseApiService($fw));
        $this->assertSame([], $driver->tunnels());
        $this->assertTrue($driver->ensurePackage());
        $install = collect(Http::recorded())->map(fn ($r) => $r[0])->first(fn ($r) => $r->method() === 'POST');
        $this->assertSame('https://fw.acme.test/api/v2/system/package', $install->url());
        $this->assertSame(['name' => 'pfSense-pkg-WireGuard'], $install->data());
    }

    public function test_pfsense_setup_finishes_an_earlier_attempt_without_assigning_the_interface(): void
    {
        $fw = Firewall::create(['name' => 'Office', 'company_id' => Company::create(['name' => 'Gamma'])->id, 'url' => 'https://fw.gamma.test', 'os_type' => 'pfsense', 'auth_method' => 'basic', 'api_key' => 'u', 'api_secret' => 'p', 'netgate_id' => 'x3']);
        $base = 'https://fw.gamma.test/api/v2';
        $tunnel = ['id' => 0, 'name' => 'tun_wg0', 'descr' => 'CFBTEL VPN', 'listenport' => '51820', 'publickey' => 'SrvPub='];
        Http::fake([
            "$base/system/packages*" => Http::response(['data' => [['name' => 'pfSense-pkg-WireGuard']]]),
            "$base/vpn/wireguard/settings*" => Http::response(['data' => []]),
            "$base/vpn/wireguard/tunnels*" => Http::response(['data' => [$tunnel]]),
            "$base/vpn/wireguard/tunnel*" => Http::response(['data' => $tunnel]),
            "$base/vpn/wireguard/peers*" => Http::response(['data' => []]),
            "$base/vpn/wireguard/apply*" => Http::response(['data' => []]),
            "$base/interfaces*" => Http::response(['data' => [['id' => 'wan', 'if' => 'vmx0'], ['id' => 'lan', 'if' => 'vmx1']]]),
            "$base/firewall/aliases*" => Http::response(['data' => []]),
            "$base/firewall/alias*" => Http::response(['data' => []]),
            "$base/firewall/rules*" => Http::response(['data' => [['descr' => 'CFBTEL VPN: DNS to firewall']]]),
            "$base/firewall/rule*" => Http::response(['data' => []]),
            "$base/firewall/nat/outbound/mode*" => Http::response(['data' => ['mode' => 'automatic']]),
            "$base/firewall/nat/outbound/mappings*" => Http::response(['data' => []]),
            "$base/firewall/nat/outbound/mapping*" => Http::response(['data' => []]),
            "$base/services/dns_resolver/access_lists*" => Http::response(['data' => []]),
            "$base/services/dns_resolver/*" => Http::response(['data' => []]),
            "$base/firewall/apply*" => Http::response(['data' => []]),
        ]);
        $r = (new PfSenseWireGuardDriver(new PfSenseApiService($fw)))
            ->setup(['listen_port' => 51820, 'subnet' => '10.0.80.0/24', 'lan' => 'lan', 'wan' => 'wan']);

        $sent = collect(Http::recorded())->map(fn ($x) => $x[0])->filter(fn ($q) => $q->method() !== 'GET');
        $to = fn (string $path, string $method = 'POST') => $sent->filter(fn ($q) => $q->method() === $method && parse_url($q->url(), PHP_URL_PATH) === "/api/v2$path")->values();

        $this->assertSame('tun_wg0', $r['ref']);
        $this->assertSame('SrvPub=', $r['public_key']);
        $this->assertCount(0, $to('/vpn/wireguard/tunnel'), 'the tunnel from the earlier attempt is reused');
        $this->assertSame([['address' => '10.0.80.1', 'mask' => 24, 'descr' => 'CFBTEL VPN']], $to('/vpn/wireguard/tunnel', 'PATCH')[0]->data()['addresses']);
        $this->assertCount(0, $to('/interface'), 'no interface assignment (the REST API cannot assign WireGuard tunnels)');
        $rules = $to('/firewall/rule');
        $this->assertCount(5, $rules, 'the rule already there is skipped');
        $this->assertSame(['WireGuard'], $rules[0]->data()['interface']);
        $this->assertSame(['wan'], $rules[4]->data()['interface']);
        $this->assertSame('hybrid', $to('/firewall/nat/outbound/mode', 'PATCH')[0]->data()['mode']);
        $this->assertSame('10.0.80.0/24', $to('/firewall/nat/outbound/mapping')[0]->data()['source']);
        $this->assertSame('10.0.80.0', $to('/services/dns_resolver/access_list')[0]->data()['networks'][0]['network']);
    }

    public function test_pfsense_driver_trusts_the_installed_package_list_over_the_settings_endpoint(): void
    {
        $fw = Firewall::create(['name' => 'Beta HQ', 'company_id' => Company::create(['name' => 'Beta'])->id, 'url' => 'https://fw.beta.test', 'os_type' => 'pfsense', 'auth_method' => 'basic', 'api_key' => 'u', 'api_secret' => 'p', 'netgate_id' => 'x2']);
        $missing = ['code' => 424, 'status' => 'failed dependency', 'message' => "The requested action requires the 'pfSense-pkg-WireGuard' package but it is not installed."];
        Http::fake([
            // The settings endpoint answers even though the package isn't installed yet.
            'https://fw.beta.test/api/v2/vpn/wireguard/settings*' => Http::response(['data' => ['enable' => false]]),
            'https://fw.beta.test/api/v2/system/packages*' => Http::sequence()
                ->push(['data' => [['name' => 'pfSense-pkg-API']]])
                ->push(['data' => [['name' => 'pfSense-pkg-API'], ['name' => 'pfSense-pkg-WireGuard']]]),
            'https://fw.beta.test/api/v2/system/package' => Http::response(['data' => ['name' => 'pfSense-pkg-WireGuard']]),
        ]);
        PfSenseWireGuardDriver::$readyWait = 0;
        $driver = new PfSenseWireGuardDriver(new PfSenseApiService($fw));
        $this->assertFalse($driver->packageInstalled());
        $this->assertTrue($driver->packageInstalled());
    }

    public function test_the_windows_app_signs_in_and_gets_its_tunnel_without_sending_a_private_key(): void
    {
        $w = $this->world();
        $site = $this->onboard($w);
        $this->grant($w['global'], $w['acme'], 'ann');
        $keys = WireGuard::keyPair();
        $q = ['state' => 'st4t3-abcdefghijklmnop', 'port' => 50123, 'pub' => $keys['public'], 'name' => 'ANN-LAPTOP'];
        auth()->logout();

        // Not signed in: the staff page sign-in comes first, then back to the app page.
        $this->get(self::PORTAL . '/vpn/app?' . http_build_query($q))->assertRedirect('/vpn');
        $this->staffSignIn('ann')->assertRedirect('/vpn/app?' . http_build_query($q));
        $this->get(self::PORTAL . '/vpn/app?' . http_build_query($q))->assertOk()->assertSee('Connect this computer')->assertSee('ANN-LAPTOP');
        $this->get(self::PORTAL . '/vpn/app?state=x&port=1&pub=nope')->assertStatus(400);

        // Connect: the device is made from the app's public key, the browser goes back to the app.
        $r = $this->post(self::PORTAL . '/vpn/app', $q + ['site' => $site->id, 'device_name' => 'Ann laptop']);
        $r->assertRedirect();
        $to = $r->headers->get('Location');
        $this->assertStringStartsWith('http://127.0.0.1:50123/callback?', $to);
        parse_str(parse_url($to, PHP_URL_QUERY), $cb);
        $this->assertSame($q['state'], $cb['state']);
        $device = VpnDevice::where('name', 'Ann laptop')->firstOrFail();
        $this->assertSame($keys['public'], $device->public_key);
        $this->assertSame($keys['public'], end($this->fw->peers)['public_key']);

        // The app redeems the code once, with its state (no session, no CSRF token).
        auth()->logout();
        $this->flushSession();
        $this->post(self::PORTAL . '/vpn/app/token', ['code' => $cb['code'], 'state' => 'wrong-state-0000000'])->assertStatus(400);
        $this->assertNull(\Illuminate\Support\Facades\Cache::get('vpn_app_code:' . hash('sha256', $cb['code'])));
        // (a wrong state burns the code; start again)
        $this->post(self::PORTAL . '/vpn/app', $q)->assertStatus(400); // signed out now
        $this->staffSignIn('ann');
        $keys2 = WireGuard::keyPair();
        $r = $this->post(self::PORTAL . '/vpn/app', ['pub' => $keys2['public']] + $q + ['site' => $site->id, 'device_name' => 'Ann laptop 2']);
        parse_str(parse_url($r->headers->get('Location'), PHP_URL_QUERY), $cb);
        $t = $this->post(self::PORTAL . '/vpn/app/token', ['code' => $cb['code'], 'state' => $q['state']])->assertOk()->json();
        $this->assertSame('CFBTEL-Acme-HQ', $t['name']);
        $this->assertSame($site->server_public_key, $t['peer_public_key']);
        $this->assertSame('vpn.acme.com:51820', $t['endpoint']);
        $this->assertMatchesRegularExpression('#^10\.0\.70\.\d+/32$#', $t['address']);
        $this->assertTrue(WireGuard::isKey($t['preshared_key']));
        $this->assertArrayNotHasKey('private_key', $t);
        $this->post(self::PORTAL . '/vpn/app/token', ['code' => $cb['code'], 'state' => $q['state']])->assertStatus(400); // once only

        // The same public key can't be used twice.
        $this->post(self::PORTAL . '/vpn/app', ['pub' => $keys2['public']] + $q + ['site' => $site->id, 'device_name' => 'Again'])->assertStatus(422);
    }

    public function test_wireguard_helpers(): void
    {
        $kp = WireGuard::keyPair();
        $this->assertTrue(WireGuard::isKey($kp['public']) && WireGuard::isKey($kp['private']));
        $this->assertSame(base64_encode(sodium_crypto_scalarmult_base(base64_decode($kp['private']))), $kp['public']);
        $this->assertSame('10.0.70.1', WireGuard::firstHost('10.0.70.0/24'));
        $this->assertSame('10.0.70.3', WireGuard::allocate('10.0.70.0/24', ['10.0.70.2/32', '10.0.70.1']));
        $this->assertNull(WireGuard::allocate('10.0.70.0/29', ['10.0.70.2', '10.0.70.3', '10.0.70.4', '10.0.70.5', '10.0.70.6']));
        $this->assertNull(WireGuard::cleanDeviceName('a:b'));
        $this->assertSame('Work laptop', WireGuard::cleanDeviceName(' Work laptop '));
        $this->expectException(\InvalidArgumentException::class);
        WireGuard::parseSubnet('10.0.70.0/8');
    }

    public function test_windows_download_goes_to_the_newest_cfbtel_vpn_installer(): void
    {
        Http::fake(['api.github.com/repos/cfbtel/cfbtel-vpn-windows/releases*' => Http::response([
            ['tag_name' => 'v1.0.2', 'draft' => true, 'assets' => [['name' => 'cfbtelvpn-amd64-1.0.2.msi', 'browser_download_url' => 'https://github.com/cfbtel/cfbtel-vpn-windows/releases/download/v1.0.2/cfbtelvpn-amd64-1.0.2.msi']]],
            ['tag_name' => 'v1.0.1', 'draft' => false, 'prerelease' => true, 'assets' => [
                ['name' => 'cfbtelvpn-amd64-1.0.1.msi', 'browser_download_url' => 'https://github.com/cfbtel/cfbtel-vpn-windows/releases/download/v1.0.1/cfbtelvpn-amd64-1.0.1.msi'],
                ['name' => 'cfbtelvpn-arm64-1.0.1.msi', 'browser_download_url' => 'https://github.com/cfbtel/cfbtel-vpn-windows/releases/download/v1.0.1/cfbtelvpn-arm64-1.0.1.msi'],
                ['name' => 'latest.sig', 'browser_download_url' => 'https://github.com/cfbtel/cfbtel-vpn-windows/releases/download/v1.0.1/latest.sig'],
            ]],
        ])]);

        \App\Models\User::factory()->create(); // past first-run setup
        $this->get('/vpn/download/windows')->assertRedirect('https://github.com/cfbtel/cfbtel-vpn-windows/releases/download/v1.0.1/cfbtelvpn-amd64-1.0.1.msi');
        $this->get('/vpn/download/windows?arch=arm64')->assertRedirect('https://github.com/cfbtel/cfbtel-vpn-windows/releases/download/v1.0.1/cfbtelvpn-arm64-1.0.1.msi');
        $this->get('/vpn/help')->assertSee('CFBTEL VPN for Windows')->assertDontSee('wireguard.com/install');
    }

    public function test_windows_download_falls_back_to_the_releases_page(): void
    {
        Http::fake(['api.github.com/*' => Http::response([], 500)]);
        \App\Models\User::factory()->create();
        $this->get('/vpn/download/windows')->assertRedirect('https://github.com/cfbtel/cfbtel-vpn-windows/releases');
    }

    // ---- live VPN page ----

    public function test_live_page_shows_who_is_connected_without_sending_keys(): void
    {
        $w = $this->world();
        $site = $this->onboard($w);
        $this->grant($w['acmeAdmin'], $w['acme'], 'bob');
        $this->staffSignIn('bob');
        $this->addDevice('Laptop')->assertOk();
        $this->addDevice('Phone')->assertOk();
        [$laptop, $phone] = VpnDevice::orderBy('id')->get()->all();
        $this->fw->live[$laptop->public_key] = ['handshake' => time() - 30, 'rx' => 1200, 'tx' => 56000, 'endpoint' => '203.0.113.9:51820'];
        $this->fw->live[$phone->public_key] = ['handshake' => 0, 'rx' => 0, 'tx' => 0, 'endpoint' => null];

        $this->console($w['acmeAdmin'])->get('/vpn-admin')->assertForbidden();
        $this->console($w['acmeAdmin'])->get("/vpn-admin/live/sites/{$site->id}")->assertForbidden();

        $page = $this->console($w['global'])->get('/vpn-admin');
        $page->assertOk()->assertSee('Live connections')->assertSee('Companies &amp; settings', false)->assertSee('bob@acme.com');
        $this->assertStringNotContainsString($laptop->public_key, $page->getContent());

        $live = $this->console($w['global'])->getJson("/vpn-admin/live/sites/{$site->id}")->assertOk();
        $live->assertJsonPath('ok', true)
            ->assertJsonPath("peers.{$laptop->id}.ep", '203.0.113.9')
            ->assertJsonPath("peers.{$laptop->id}.tx", 56000)
            ->assertJsonPath("peers.{$phone->id}.hs", 0);
        $this->assertStringNotContainsString($laptop->public_key, $live->getContent());

        $this->console($w['global'])->getJson("/vpn-admin/live/devices/{$laptop->id}")->assertOk()
            ->assertJsonPath('person.email', 'bob@acme.com')
            ->assertJsonPath('company.name', 'Acme')
            ->assertJsonPath('others.0.name', 'Phone');

        // A firewall that doesn't answer is shown as such and left alone for a minute.
        \Illuminate\Support\Facades\Cache::flush();
        $this->fw->down = true;
        $this->console($w['global'])->getJson("/vpn-admin/live/sites/{$site->id}")->assertOk()->assertJsonPath('ok', false);
        $this->fw->down = false;
        $this->console($w['global'])->getJson("/vpn-admin/live/sites/{$site->id}")->assertJsonPath('ok', false);
    }

    public function test_disconnect_and_block_from_the_live_page(): void
    {
        $w = $this->world();
        $this->onboard($w);
        $this->grant($w['acmeAdmin'], $w['acme'], 'bob');
        $this->staffSignIn('bob');
        $this->addDevice('Laptop')->assertOk();
        $this->addDevice('Phone')->assertOk();
        $laptop = VpnDevice::orderBy('id')->first();

        $this->console($w['acmeAdmin'])->postJson("/vpn-admin/live/devices/{$laptop->id}/disconnect")->assertForbidden();
        $this->console($w['global'])->postJson("/vpn-admin/live/devices/{$laptop->id}/disconnect")->assertOk()->assertJsonPath('ok', true);
        $this->assertCount(1, $this->fw->peers);
        $this->assertNull(VpnDevice::find($laptop->id));

        $bob = VpnUser::first();
        $this->console($w['global'])->postJson("/vpn-admin/live/people/{$bob->id}/block")->assertOk();
        $this->assertCount(0, $this->fw->peers);
        $this->assertTrue($bob->fresh()->isBlocked());
        $this->console($w['global'])->getJson('/vpn-admin/live/blocked')->assertOk()->assertJsonPath('0.email', 'bob@acme.com');
        $this->console($w['acmeAdmin'])->get("/companies/{$w['acme']->id}/vpn")->assertOk()->assertSee('Blocked');

        // Blocked: the open session ends, and signing in again is refused.
        $this->addDevice('Again')->assertRedirect();
        $this->staffSignIn('bob')->assertStatus(403)->assertSee('blocked');
        $this->assertCount(0, $this->fw->peers);

        $this->console($w['global'])->postJson("/vpn-admin/live/people/{$bob->id}/unblock")->assertOk();
        $this->staffSignIn('bob')->assertRedirect('https://vpn.test/vpn');
        $this->addDevice('Laptop')->assertOk();
        $this->assertCount(1, $this->fw->peers);
    }

    public function test_pfsense_live_status_reads_wg_without_secrets(): void
    {
        $fw = Firewall::create(['name' => 'Acme HQ', 'company_id' => Company::create(['name' => 'Acme'])->id, 'url' => 'https://fw.acme.test', 'os_type' => 'pfsense', 'auth_method' => 'basic', 'api_key' => 'u', 'api_secret' => 'p', 'netgate_id' => 'x1']);
        $k = WireGuard::keyPair()['public'];
        Http::fake([
            'https://fw.acme.test/api/v2/diagnostics/command_prompt' => Http::response(['data' => ['output' => "{$k}|198.51.100.4:61234|1795200000|1234|98765\nnot a line\n"]]),
        ]);
        $driver = new PfSenseWireGuardDriver(new PfSenseApiService($fw));
        $rows = $driver->status('tun_wg0');
        $this->assertSame([['public_key' => $k, 'endpoint' => '198.51.100.4:61234', 'handshake' => 1795200000, 'rx' => 1234, 'tx' => 98765]], $rows);
        $cmd = collect(Http::recorded())->map(fn ($r) => $r[0]->data()['command'] ?? '')->first();
        $this->assertStringContainsString('wg show tun_wg0 dump', $cmd);
        $this->assertStringContainsString('print $1"|"$3"|"$5"|"$6"|"$7', $cmd); // never the preshared key ($2)
        $this->assertSame([], $driver->status('tun_wg0; reboot'));
        $this->assertSame('2001:db8::1', \App\Services\Vpn\VpnLive::endpointIp('[2001:db8::1]:51820'));
    }
}
