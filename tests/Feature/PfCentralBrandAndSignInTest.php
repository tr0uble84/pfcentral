<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Auth\MspSignIn;
use App\Services\Vpn\Microsoft;
use App\Services\Vpn\VpnException;
use App\Support\ReleaseNotes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/** Microsoft 365 without the network, for the MSP sign-in. */
class MspFakeMicrosoft extends Microsoft
{
    public array $people = [];
    public array $groups = [];
    public array $calls = [];

    public function __construct()
    {
        parent::__construct('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'secret', PfCentralBrandAndSignInTest::TENANT);
    }

    public function loginUrl(string $redirectUri, ?string $loginHint = null): array
    {
        $this->calls[] = "login {$redirectUri}";

        return ['url' => 'https://login.microsoftonline.com/' . PfCentralBrandAndSignInTest::TENANT . '/authorize', 'pending' => ['state' => 'state-123', 'nonce' => 'n', 'verifier' => 'v']];
    }

    public function redeem(string $code, string $redirectUri, array $pending): array
    {
        return $this->people[$code] ?? throw new VpnException('bad code');
    }

    public function memberOf(string $tenantId, string $oid, array $groupIds): array
    {
        $this->calls[] = "memberOf {$oid}";

        return array_values(array_filter($groupIds, fn ($id) => in_array($oid, $this->groups[$id]['members'] ?? [], true)));
    }

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
}

class PfCentralBrandAndSignInTest extends TestCase
{
    use RefreshDatabase;

    public const TENANT = '0a1b2c3d-1234-4e5f-8a9b-0123456789ab';
    private const ADMINS = '11111111-1111-4111-8111-111111111111';
    private const SALES = '22222222-2222-4222-8222-222222222222';
    private const ALICE = '33333333-3333-4333-8333-333333333333';
    private const BOB = '44444444-4444-4444-8444-444444444444';

    private MspFakeMicrosoft $ms;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('x', 32))]);
        $this->withoutVite();
        $this->ms = new MspFakeMicrosoft();
        $this->ms->groups = [
            self::ADMINS => ['name' => 'PFCentral Admins', 'members' => [self::ALICE]],
            self::SALES => ['name' => 'Sales', 'members' => [self::BOB]],
        ];
        $this->ms->people = [
            'code-alice' => ['tid' => self::TENANT, 'oid' => self::ALICE, 'upn' => 'alice@cfbtel.com', 'name' => 'Alice Tech'],
            'code-bob' => ['tid' => self::TENANT, 'oid' => self::BOB, 'upn' => 'bob@cfbtel.com', 'name' => 'Bob Sales'],
            'code-stranger' => ['tid' => '99999999-9999-4999-8999-999999999999', 'oid' => self::ALICE, 'upn' => 'alice@other.com', 'name' => 'Other'],
        ];
        MspSignIn::$factory = fn () => $this->ms;
    }

    protected function tearDown(): void
    {
        MspSignIn::$factory = null;
        parent::tearDown();
    }

    private function globalAdmin(): User
    {
        return User::create(['name' => 'Jay', 'email' => 'admin@example.com', 'password' => 'password', 'role' => 'admin', 'company_id' => null]);
    }

    private function turnOn(): void
    {
        MspSignIn::set('enabled', '1');
        MspSignIn::set('tenant_id', self::TENANT);
        MspSignIn::set('client_id', 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        MspSignIn::setClientSecret('secret');
        MspSignIn::setGroups([['id' => self::ADMINS, 'name' => 'PFCentral Admins']]);
    }

    private function signInAs(string $code)
    {
        $this->get('/login/microsoft')->assertRedirect('https://login.microsoftonline.com/' . self::TENANT . '/authorize');

        return $this->get('/login/microsoft/callback?code=' . $code . '&state=state-123');
    }

    public function test_login_page_has_brand_settings_gear_and_copyright(): void
    {
        $this->globalAdmin();

        $this->get('/login')->assertOk()
            ->assertSee('PFCentral')
            ->assertSee('Sign-in settings')
            ->assertSee(route('system.signin'), false)
            ->assertSee('CFBTEL. All rights reserved.')
            ->assertSee('vendor/three/three.module.min.js', false)
            ->assertDontSee('Sign in with Microsoft 365');

        $this->turnOn();
        $this->get('/login')->assertSee('Sign in with Microsoft 365');
    }

    public function test_microsoft_sign_in_creates_an_administrator_for_people_in_the_groups(): void
    {
        $this->globalAdmin();
        $this->turnOn();

        $this->signInAs('code-alice')->assertRedirect('/dashboard');
        $alice = User::where('email', 'alice@cfbtel.com')->firstOrFail();
        $this->assertTrue($alice->isGlobalAdmin());
        $this->assertSame(self::ALICE, $alice->ms_oid);
        $this->assertSame($alice->id, Auth::id());
        $this->assertContains('memberOf ' . self::ALICE, $this->ms->calls);

        // Next time the same person is found by their Microsoft id, even with a new email.
        Auth::logout();
        $this->ms->people['code-alice']['upn'] = 'alice.tech@cfbtel.com';
        $this->signInAs('code-alice')->assertRedirect('/dashboard');
        $this->assertSame(2, User::count());
    }

    public function test_people_outside_the_groups_or_tenant_are_refused(): void
    {
        $this->globalAdmin();
        $this->turnOn();

        $this->signInAs('code-bob')->assertRedirect('/login')->assertSessionHasErrors('microsoft');
        $this->assertGuest();
        $this->assertNull(User::where('email', 'bob@cfbtel.com')->first());

        $this->signInAs('code-stranger')->assertRedirect('/login')->assertSessionHasErrors('microsoft');
        $this->assertGuest();

        // A forged or replayed callback without the pending sign-in.
        $this->get('/login/microsoft/callback?code=code-alice&state=state-123')->assertSessionHasErrors('microsoft');
        $this->assertGuest();
    }

    public function test_a_customer_account_with_the_same_email_is_never_promoted(): void
    {
        $this->globalAdmin();
        $this->turnOn();
        $acme = Company::create(['name' => 'Acme']);
        User::create(['name' => 'Alice at Acme', 'email' => 'alice@cfbtel.com', 'password' => 'password', 'role' => 'readonly', 'company_id' => $acme->id]);

        $this->signInAs('code-alice')->assertRedirect('/login')->assertSessionHasErrors('microsoft');
        $this->assertGuest();
        $this->assertFalse(User::where('email', 'alice@cfbtel.com')->first()->isGlobalAdmin());
    }

    public function test_an_existing_administrator_is_linked_by_email(): void
    {
        $jay = $this->globalAdmin();
        $this->turnOn();
        $this->ms->people['code-jay'] = ['tid' => self::TENANT, 'oid' => self::BOB, 'upn' => 'admin@example.com', 'name' => 'Jay'];
        $this->ms->groups[self::ADMINS]['members'][] = self::BOB;

        $this->signInAs('code-jay')->assertRedirect('/dashboard');
        $this->assertSame($jay->id, Auth::id());
        $this->assertSame(self::BOB, $jay->fresh()->ms_oid);
    }

    public function test_microsoft_sign_in_is_off_until_fully_set_up(): void
    {
        $this->globalAdmin();
        MspSignIn::set('enabled', '1');
        MspSignIn::set('tenant_id', self::TENANT); // no groups, no app

        $this->get('/login/microsoft')->assertRedirect('/login')->assertSessionHasErrors('microsoft');
    }

    public function test_sign_in_settings_are_for_global_administrators(): void
    {
        $jay = $this->globalAdmin();
        $acme = Company::create(['name' => 'Acme']);
        $acmeAdmin = User::create(['name' => 'Acme IT', 'email' => 'it@acme.test', 'password' => 'password', 'role' => 'admin', 'company_id' => $acme->id]);

        $this->get('/system/sign-in')->assertRedirect('/login');
        $this->actingAs($acmeAdmin)->get('/system/sign-in')->assertForbidden();
        $this->actingAs($acmeAdmin)->post('/system/sign-in', ['enabled' => '1'])->assertForbidden();

        $this->actingAs($jay)->get('/system/sign-in')->assertOk()->assertSee('Sign in with Microsoft 365')->assertSee(route('login.microsoft.callback'));

        // Tenant first; then groups can be searched and saved.
        $this->actingAs($jay)->post('/system/sign-in', ['tenant_id' => 'not-a-guid'])->assertSessionHasErrors('tenant_id');
        $this->actingAs($jay)->post('/system/sign-in', ['tenant_id' => strtoupper(self::TENANT), 'client_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'client_secret' => 'shh'])
            ->assertRedirect(route('system.signin'));
        $this->assertSame(self::TENANT, MspSignIn::tenantId());
        $this->assertNotSame('shh', SystemSetting::where('key', 'msp_ms_client_secret')->value('value'), 'the secret is stored encrypted');
        $this->assertSame('shh', MspSignIn::clientSecret());

        $this->actingAs($jay)->getJson('/system/sign-in/groups?q=admin')->assertOk()->assertJson([['id' => self::ADMINS, 'name' => 'PFCentral Admins']]);

        $this->actingAs($jay)->post('/system/sign-in', [
            'enabled' => '1', 'tenant_id' => self::TENANT, 'client_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'ms_groups' => [self::ADMINS],
        ])->assertSessionHas('success');
        $this->assertSame([['id' => self::ADMINS, 'name' => 'PFCentral Admins']], MspSignIn::groups());
        $this->assertSame('shh', MspSignIn::clientSecret(), 'a blank secret keeps the saved one');
        $this->assertTrue(MspSignIn::enabled());

        $this->actingAs($jay)->post('/system/sign-in', ['enabled' => '1', 'tenant_id' => self::TENANT, 'ms_groups' => ['55555555-5555-4555-8555-555555555555']])
            ->assertSessionHasErrors('ms_groups');
    }

    public function test_whats_new_opens_once_after_an_update(): void
    {
        $jay = $this->globalAdmin();
        $version = ReleaseNotes::currentVersion();
        $this->assertNotEmpty(ReleaseNotes::for($version), "CHANGELOG.md needs notes for {$version}");

        $this->actingAs($jay)->get('/dashboard')->assertOk()
            ->assertSee('has been updated')
            ->assertSee('open: true', false)
            ->assertSee('CFBTEL. All rights reserved.');

        // Shown once: opening the next page without closing it doesn't show it again.
        $this->assertSame($version, $jay->fresh()->whats_new_seen);
        $this->actingAs($jay->fresh())->get('/dashboard')->assertSee('open: false', false);
        $this->actingAs($jay)->postJson('/whats-new/seen')->assertOk();

        $this->actingAs($jay)->get('/whats-new')->assertOk()->assertSee($version);
    }

    public function test_an_expired_login_form_returns_to_the_login_page(): void
    {
        $this->globalAdmin();
        // Real CSRF checking (tests normally skip it).
        $this->app->instance('env', 'production');
        $response = $this->withHeader('Accept', 'text/html')->post('/login', ['email' => 'admin@example.com', 'password' => 'x', '_token' => 'stale']);
        $response->assertRedirect(route('login'))->assertSessionHas('status', 'Your session expired. Please sign in again.');
    }

    public function test_magic_link_signs_in_after_confirming_and_works_behind_another_host_or_port(): void
    {
        $jay = $this->globalAdmin();
        \Illuminate\Support\Facades\Mail::fake();
        $this->post('/login/magic', ['email' => 'admin@example.com'])->assertRedirect();
        $url = null;
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\MagicLoginLink::class, function ($mail) use (&$url) {
            $url = $mail->url;

            return true;
        });

        // The same path and query reached through a proxy, another scheme or port still checks out.
        $parts = parse_url($url);
        $behindProxy = 'https://console.example.test:8443' . $parts['path'] . '?' . $parts['query'];
        $this->get($behindProxy)->assertOk()->assertSee('Continue as Jay');
        $this->assertGuest();
        $this->post($behindProxy)->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($jay);
    }

    public function test_expired_magic_link_explains_itself(): void
    {
        $jay = $this->globalAdmin();
        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute('login.magic.verify', now()->subMinute(), ['id' => $jay->id], absolute: false);
        $this->get($url)->assertRedirect(route('login'))->assertSessionHasErrors(['magic' => 'This sign-in link has expired (links work for 15 minutes). Ask for a new one below.']);
    }

    public function test_whats_new_shows_every_version_since_the_last_one_seen_and_opens_by_hand(): void
    {
        $jay = $this->globalAdmin();
        $versions = array_keys(ReleaseNotes::all());
        $this->assertGreaterThanOrEqual(3, count($versions));
        [$current, $previous, $older] = [ReleaseNotes::currentVersion(), $versions[1], $versions[2]];

        $jay->forceFill(['whats_new_seen' => $older])->save();
        $this->actingAs($jay)->get('/dashboard')->assertSee('open: true', false)->assertSee($current)->assertSee($previous);

        // After closing it, the window is still there for the "What's new" link, just closed.
        $jay->forceFill(['whats_new_seen' => $current])->save();
        $this->actingAs($jay)->get('/dashboard')->assertSee('open: false', false)->assertSee('whats-new-title', false);
    }

    public function test_changelog_ships_with_releases(): void
    {
        $this->assertStringNotContainsString('CHANGELOG.md export-ignore', (string) @file_get_contents(base_path('.gitattributes')),
            'GitHub release archives must include CHANGELOG.md: the What\'s new window reads it');
    }
}
