<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Firewall;
use App\Models\FirewallGuiLock;
use App\Models\FirewallGuiSession;
use App\Models\User;
use App\Services\Security\FirewallGui;
use App\Services\Security\GuiLock;
use App\Services\Security\SecuritySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SecurityFrontDoorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        GuiLock::$fake = null;
        parent::tearDown();
    }

    private function world(): array
    {
        $acme = Company::create(['name' => 'Acme']);
        $mk = function (string $email, string $role, ?int $company) {
            $u = User::create(['name' => ucfirst(strtok($email, '@')), 'email' => $email, 'password' => 'password', 'role' => $role, 'company_id' => $company]);
            $u->forceFill(['email_verified_at' => now()])->save();
            return $u;
        };
        return [
            'global' => $mk('admin@example.com', 'admin', null),
            'acmeAdmin' => $mk('it@acme.test', 'admin', $acme->id),
            'acmeRo' => $mk('ro@acme.test', 'readonly', $acme->id),
            'fw' => Firewall::create(['name' => 'Acme HQ', 'company_id' => $acme->id, 'url' => 'https://fw.acme.test', 'os_type' => 'pfsense', 'auth_method' => 'basic', 'api_key' => 'u', 'api_secret' => 'p', 'netgate_id' => 'acme-hq']),
        ];
    }

    public function test_settings_are_for_global_admins_and_validated(): void
    {
        $w = $this->world();
        $this->actingAs($w['acmeAdmin'])->get('/system/security')->assertForbidden();
        $this->actingAs($w['global'])->get('/system/security')->assertOk()->assertSee('Open firewalls through')->assertSee('Acme HQ');

        $this->actingAs($w['global'])->post('/system/security', ['gui_proxy' => '1', 'gui_host' => '', 'gui_idle' => 30])->assertSessionHasErrors('gui_host');
        $this->actingAs($w['global'])->post('/system/security', ['gui_proxy' => '1', 'gui_host' => parse_url(config('app.url'), PHP_URL_HOST), 'gui_idle' => 30])->assertSessionHasErrors('gui_host');
        $this->actingAs($w['global'])->post('/system/security', ['gui_idle' => 30, 'lock_allowed' => "203.0.113.10\nnot-an-ip"])->assertSessionHasErrors('lock_allowed');
        $this->actingAs($w['global'])->post('/system/security', ['require_2fa' => '1', 'gui_proxy' => '1', 'gui_host' => 'fwgui.test', 'gui_idle' => 20, 'lock_allowed' => "203.0.113.10, 198.51.100.0/24"])->assertRedirect('/system/security');
        $this->assertTrue(SecuritySettings::requireTwoFactor());
        $this->assertTrue(SecuritySettings::guiProxyEnabled());
        $this->assertSame(['203.0.113.10', '198.51.100.0/24'], SecuritySettings::lockAllowed());
    }

    public function test_two_factor_can_be_required(): void
    {
        $w = $this->world();
        $this->actingAs($w['acmeAdmin'])->get('/upgrades')->assertOk();
        SecuritySettings::set('require_2fa', '1');
        $this->actingAs($w['acmeAdmin'])->get('/upgrades')->assertRedirect(route('profile.edit'));
        $this->actingAs($w['acmeAdmin'])->getJson('/upgrades/data')->assertForbidden();
        $this->actingAs($w['acmeAdmin'])->get('/profile')->assertOk();

        // Microsoft 365 sign-ins count as two-factor.
        $this->actingAs($w['acmeAdmin'])->withSession(['pfc_auth_via' => 'microsoft'])->get('/upgrades')->assertOk();

        $w['acmeAdmin']->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
        $this->actingAs($w['acmeAdmin']->fresh())->withSession([])->get('/upgrades')->assertOk();
    }

    public function test_firewall_web_interface_opens_through_pfcentral_with_a_temporary_account(): void
    {
        $w = $this->world();
        SecuritySettings::set('gui_proxy', '1');
        SecuritySettings::set('gui_host', 'fwgui.test');
        $users = [];
        Http::fake(function (HttpRequest $r) use (&$users) {
            $url = $r->url();
            if ($r->method() === 'POST' && str_ends_with($url, '/api/v2/user')) {
                $users[] = $r->data()['name'];
                return Http::response(['data' => ['name' => $r->data()['name']]]);
            }
            if (str_contains($url, '/api/v2/users')) {
                return Http::response(['data' => array_map(fn ($n, $i) => ['id' => $i, 'name' => $n], $users, array_keys($users))]);
            }
            if ($r->method() === 'DELETE' && str_contains($url, '/api/v2/user')) {
                $users = [];
                return Http::response(['data' => []]);
            }
            if ($url === 'https://fw.acme.test/') {
                return Http::response('<form><input type="hidden" name="__csrf_magic" value="sid:abc,123" /><input name="usernamefld"></form>', 200, ['Set-Cookie' => 'PHPSESSID=s1; path=/; secure; HttpOnly']);
            }
            if ($url === 'https://fw.acme.test/index.php' && $r->method() === 'POST') {
                return str_contains($r->body(), 'usernamefld=pfc-it-') && str_contains($r->body(), '__csrf_magic=sid')
                    ? Http::response('', 302, ['Location' => 'https://fw.acme.test/index.php', 'Set-Cookie' => 'PHPSESSID=s2; path=/; secure; HttpOnly'])
                    : Http::response('bad', 200);
            }
            if (str_starts_with($url, 'https://fw.acme.test/firewall_rules.php')) {
                if ($r->method() === 'POST') {
                    return Http::response('', 302, ['Location' => 'https://fw.acme.test/firewall_rules.php?if=wan']);
                }
                return Http::response('<html><body>Rules for ' . ($r->header('Cookie')[0] ?? '') . '</body></html>', 200, ['Content-Type' => 'text/html']);
            }
            return Http::response('not found', 404);
        });

        $this->actingAs($w['acmeRo'])->post("/firewall/{$w['fw']->id}/web-interface")->assertForbidden();
        $open = $this->actingAs($w['acmeAdmin'])->post("/firewall/{$w['fw']->id}/web-interface");
        $location = $open->headers->get('Location');
        $this->assertStringStartsWith('https://fwgui.test/__pfcentral/enter/', $location);
        $this->assertCount(1, $users);
        $this->assertStringStartsWith('pfc-it-', $users[0]);
        $session = FirewallGuiSession::firstOrFail();
        $this->assertSame('it@acme.test', $session->user_email);

        // The link works once, sets PFCentral's own cookie on the web interface host.
        auth()->logout();
        $enter = $this->get($location);
        $enter->assertRedirect('/');
        $cookie = collect($enter->headers->getCookies())->first(fn ($c) => $c->getName() === FirewallGui::COOKIE);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly() && $cookie->isSecure());
        $this->get($location)->assertForbidden();

        // Pages come from the firewall with the firewall's session cookie, which the browser never sees.
        $page = $this->withUnencryptedCookie(FirewallGui::COOKIE, $cookie->getValue())->get('https://fwgui.test/firewall_rules.php?if=wan');
        $page->assertOk()->assertSee('PHPSESSID=s2')->assertSee('End session');
        $this->assertEmpty(array_filter($page->headers->getCookies(), fn ($c) => $c->getName() === 'PHPSESSID'));

        // Changes are logged; redirects stay on PFCentral's host.
        $post = $this->withUnencryptedCookie(FirewallGui::COOKIE, $cookie->getValue())->post('https://fwgui.test/firewall_rules.php', ['apply' => 'Apply Changes']);
        $post->assertStatus(302)->assertHeader('Location', '/firewall_rules.php?if=wan');
        $this->assertSame('/firewall_rules.php', $session->fresh()->actions[0]['page']);

        // No cookie: nothing from the firewall.
        $this->unencryptedCookies = [];
        $this->defaultCookies = [];
        $this->get('https://fwgui.test/firewall_rules.php')->assertStatus(401)->assertDontSee('Rules for');

        // Ending removes the temporary account.
        $this->withUnencryptedCookie(FirewallGui::COOKIE, $cookie->getValue())->get('https://fwgui.test/__pfcentral/end')->assertOk()->assertSee('Session ended');
        $session->refresh();
        $this->assertNotNull($session->ended_at);
        $this->assertTrue($session->temp_user_removed);
        $this->assertSame([], $users);
        $this->withUnencryptedCookie(FirewallGui::COOKIE, $cookie->getValue())->get('https://fwgui.test/firewall_rules.php')->assertStatus(401);
    }

    public function test_idle_sessions_end_and_the_console_is_not_served_on_the_web_interface_host(): void
    {
        $w = $this->world();
        SecuritySettings::set('gui_proxy', '1');
        SecuritySettings::set('gui_host', 'fwgui.test');
        Http::fake(['https://fw.acme.test/api/v2/users*' => Http::response(['data' => [['id' => 0, 'name' => 'pfc-it-abcd']]]), 'https://fw.acme.test/api/v2/user*' => Http::response(['data' => []])]);
        $s = FirewallGuiSession::create(['user_id' => $w['acmeAdmin']->id, 'user_email' => 'it@acme.test', 'firewall_id' => $w['fw']->id, 'token_hash' => hash('sha256', 'tok'),
            'temp_user' => 'pfc-it-abcd', 'started_at' => now()->subHour(), 'last_seen_at' => now()->subHour(), 'actions' => []]);
        $this->assertSame(1, FirewallGui::cleanUp());
        $s->refresh();
        $this->assertSame('idle', $s->end_reason);
        $this->assertTrue($s->temp_user_removed);

        $this->get('https://fwgui.test/login')->assertStatus(401)->assertDontSee('password');
        $this->get('https://fwgui.test/dashboard')->assertStatus(401);
    }

    public function test_web_interface_lock_checks_pfcentrals_own_address_first(): void
    {
        $w = $this->world();
        $calls = [];
        $state = ['locked' => false, 'confirmFails' => false];
        GuiLock::$fake = function (Firewall $fw, string $action, array $args) use (&$calls, &$state) {
            $calls[] = $action;
            return match ($action) {
                'peers' => ['ok' => true, 'peers' => ['203.0.113.10', '192.168.1.50'], 'port' => '443'],
                'lock' => ($state['locked'] = true) ? ['ok' => true, 'locked' => true, 'allowed' => $args['allowed'], 'port' => '443'] : [],
                'confirm' => $state['confirmFails'] ? throw new \RuntimeException('timeout') : ['ok' => true, 'locked' => true, 'allowed' => ['203.0.113.10'], 'port' => '443', 'pending' => false],
                'unlock' => ($state['locked'] = false) || true ? ['ok' => true, 'locked' => false] : [],
                'status' => ['ok' => true, 'locked' => $state['locked'], 'allowed' => [], 'port' => '443', 'pending' => false],
            };
        };
        $this->actingAs($w['acmeAdmin'])->postJson("/system/security/locks/{$w['fw']->id}/lock")->assertForbidden();

        // Nothing allowed yet / PFCentral's own address not in the list: refused, nothing changed.
        $this->actingAs($w['global'])->postJson("/system/security/locks/{$w['fw']->id}/lock")->assertStatus(422)->assertJsonPath('message', 'Add PFCentral\'s address under System → Security first.');
        SecuritySettings::set('lock_allowed', '198.51.100.7');
        $this->actingAs($w['global'])->postJson("/system/security/locks/{$w['fw']->id}/lock")->assertStatus(422);
        $this->assertNotContains('lock', $calls);

        SecuritySettings::set('lock_allowed', '203.0.113.0/28');
        $this->actingAs($w['global'])->postJson("/system/security/locks/{$w['fw']->id}/lock")->assertOk()->assertJsonPath('locked', true);
        $this->assertSame(['peers', 'peers', 'lock', 'confirm'], $calls);
        $this->assertTrue(FirewallGuiLock::where('firewall_id', $w['fw']->id)->value('locked'));

        $this->actingAs($w['global'])->postJson("/system/security/locks/{$w['fw']->id}/unlock")->assertOk()->assertJsonPath('locked', false);

        // Locked but PFCentral can't confirm: the firewall undoes it by itself.
        $state['confirmFails'] = true;
        $this->actingAs($w['global'])->postJson("/system/security/locks/{$w['fw']->id}/lock")->assertStatus(422)->assertJsonFragment(['ok' => false]);
        $this->assertStringContainsString('undoes it by itself', FirewallGuiLock::where('firewall_id', $w['fw']->id)->value('error'));

        $this->assertTrue(GuiLock::covered('203.0.113.9', ['203.0.113.0/28']));
        $this->assertFalse(GuiLock::covered('203.0.113.17', ['203.0.113.0/28']));
        $this->assertTrue(GuiLock::covered('2001:db8::5', ['2001:db8::/64']));
        $this->assertSame('/a.php?x=1', FirewallGui::localLocation('https://fw.acme.test/a.php?x=1', 'https://fw.acme.test'));
        $this->assertSame('/', FirewallGui::localLocation('https://evil.example/', 'https://fw.acme.test'));
    }
}
