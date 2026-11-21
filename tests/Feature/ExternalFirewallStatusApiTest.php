<?php

namespace Tests\Feature;

use App\Models\{Company, Firewall, User};
use App\Services\Integration\{FirewallStatusCollector, FirewallStatusNormalizer, FirewallStatusSnapshot};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, Http, Queue};
use Tests\TestCase;

class ExternalFirewallStatusApiTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Company $other;
    private Firewall $firewall;
    private Firewall $otherFirewall;
    private string $scoped;
    private string $msp;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('x', 32)), 'app.debug' => false]);
        $this->withoutVite();
        $this->company = Company::create(['name' => 'A']);
        $this->other = Company::create(['name' => 'B']);
        $user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'readonly']);
        $admin = User::factory()->create(['company_id' => null, 'role' => 'admin']);
        $this->firewall = Firewall::create(['name' => 'A', 'company_id' => $this->company->id,
            'url' => 'https://192.0.2.10', 'os_type' => 'pfsense', 'api_token' => 'private-token-probe', 'auth_method' => 'token']);
        $this->otherFirewall = Firewall::create(['name' => 'B', 'company_id' => $this->other->id, 'url' => 'https://192.0.2.20']);
        $issued = $user->createToken('scoped', ['inventory:read'], now()->addDay());
        $issued->accessToken->forceFill(['company_id' => $this->company->id])->save();
        $this->scoped = $issued->plainTextToken;
        $this->msp = $admin->createToken('msp', ['inventory:read', 'inventory:read:all'], now()->addDay())->plainTextToken;
    }

    private function getStatus(string $path, ?string $token = null)
    {
        $this->app['auth']->forgetGuards();
        return $this->get('https://localhost/api/v1/'.$path, ['Authorization' => 'Bearer '.($token ?? $this->msp)]);
    }

    private function seedCache(array $data = [], bool $online = true, ?string $at = null): void
    {
        Cache::put('firewall_status_'.$this->firewall->id, [
            'online' => $online, 'updated_at' => $at ?? now()->toIso8601String(), 'data' => $data,
            'error' => 'Raw exception private-token-probe https://user:password@firewall.example',
        ], 86400);
    }

    public function test_status_requires_authentication_and_inventory_ability(): void
    {
        $this->get('https://localhost/api/v1/firewalls/status')->assertUnauthorized();
        $this->get('https://localhost/api/v1/firewalls/'.$this->firewall->id.'/status')->assertUnauthorized();
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $token = $user->createToken('no access', [], now()->addDay());
        $token->accessToken->forceFill(['company_id' => $this->company->id])->save();
        $this->getStatus('firewalls/'.$this->firewall->id.'/status', $token->plainTextToken)->assertForbidden();
    }

    public function test_unknown_status_returns_nulls_without_contacting_firewall(): void
    {
        $this->getStatus('firewalls/'.$this->firewall->id.'/status')->assertOk()
            ->assertJsonPath('data.firewall_id', $this->firewall->id)->assertJsonPath('data.online', null)
            ->assertJsonPath('data.checked_at', null)->assertJsonPath('data.system.version', null)
            ->assertJsonPath('data.usage.cpu_pct', null)->assertJsonPath('data.stale', true)
            ->assertJsonPath('data.services.total', null)->assertJsonPath('data.vpn', []);
        Http::assertNothingSent();
    }

    public function test_cached_metrics_are_normalized_and_secrets_are_excluded(): void
    {
        $this->seedCache(['version' => '26.07-RELEASE', 'api_version' => '2.10.2', 'platform' => 'Netgate 1100',
            'cpu_model' => 'ARM Cortex', 'cpu_count' => '2', 'cpu_load_avg' => '0.45,0.31,0.22',
            'cpu_usage' => '24.5%', 'mem_usage' => 33, 'swap_usage' => 'N/A', 'disk_usage' => '31%',
            'uptime' => '6 days 03:10:09', 'netgate_id' => 'not-a-serial', 'dns_servers' => ['192.0.2.53'],
            'api_key' => 'secret-key', 'ssh_password' => 'secret-ssh', 'rules' => [['secret-rule']],
            'interfaces' => [['id' => 0, 'name' => 'WAN', 'hwif' => 'mvneta0', 'status' => 'up',
                'ipaddr' => '203.0.113.2', 'subnet' => 29, 'in_rate_bps' => 21210, 'out_rate_bps' => 60130,
                'private_key' => 'secret-private-key']],
            'gateways' => [['name' => 'WANGW', 'srcip' => '203.0.113.1', 'monitorip' => '203.0.113.1', 'loss' => '0%', 'delay' => '4.2ms', 'status' => 'online']]]);
        $response = $this->getStatus('firewalls/'.$this->firewall->id.'/status')->assertOk()
            ->assertJsonPath('data.system.version', '26.07-RELEASE')->assertJsonPath('data.system.serial', null)
            ->assertJsonPath('data.system.cpu.count', 2)->assertJsonPath('data.system.cpu.load_avg.0', 0.45)
            ->assertJsonPath('data.system.uptime_seconds', 529809)->assertJsonPath('data.usage.cpu_pct', 24.5)
            ->assertJsonPath('data.usage.swap_pct', null)->assertJsonPath('data.interfaces.0.device', 'mvneta0')
            ->assertJsonPath('data.interfaces.0.in_bps', 21210)->assertJsonPath('data.gateways.0.delay_ms', 4.2)
            ->assertJsonPath('data.stale', false)->assertHeader('Cache-Control', 'no-store, private');
        foreach (['secret-key', 'secret-ssh', 'secret-private-key', 'secret-rule', 'private-token-probe', 'user:password'] as $secret) $response->assertDontSee($secret);
        Http::assertNothingSent();
    }

    public function test_company_scoping_applies_to_single_and_bulk_status(): void
    {
        $this->getStatus('firewalls/'.$this->otherFirewall->id.'/status', $this->scoped)->assertNotFound();
        $this->getStatus('firewalls/999999/status', $this->scoped)->assertNotFound();
        $this->getStatus('firewalls/status', $this->scoped)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.firewall_id', $this->firewall->id);
        $this->getStatus('firewalls/status?company_id='.$this->other->id, $this->scoped)->assertForbidden();
        $this->getStatus('firewalls/status', $this->msp)->assertOk()->assertJsonCount(2, 'data');
        $this->getStatus('firewalls/status?company_id='.$this->other->id, $this->msp)->assertOk()->assertJsonCount(1, 'data');
        Http::assertNothingSent();
    }

    public function test_bulk_status_pagination_includes_never_checked_devices(): void
    {
        $this->getStatus('firewalls/status?per_page=1&page=2')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.firewall_id', $this->otherFirewall->id)->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 2)->assertJsonPath('data.0.online', null);
        $this->getStatus('firewalls/status?per_page=101')->assertUnprocessable();
        $this->getStatus('firewalls/status?page=0')->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_stale_and_offline_have_safe_errors_and_real_timestamps(): void
    {
        $this->seedCache([], false, now()->subMinutes(10)->toIso8601String());
        $this->getStatus('firewalls/'.$this->firewall->id.'/status')->assertOk()->assertJsonPath('data.online', false)
            ->assertJsonPath('data.stale', true)->assertJsonPath('data.age_seconds', 600)
            ->assertDontSee('private-token-probe')->assertDontSee('user:password');
        Http::assertNothingSent();
    }

    public function test_http_and_write_methods_are_rejected_and_head_is_supported(): void
    {
        $this->get('http://localhost/api/v1/firewalls/status', ['Authorization' => 'Bearer '.$this->msp])->assertForbidden();
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->call($method, 'https://localhost/api/v1/firewalls/'.$this->firewall->id.'/status', [], [], [],
                ['HTTP_AUTHORIZATION' => 'Bearer '.$this->msp])->assertStatus(405);
        }
        $this->app['auth']->forgetGuards();
        $this->head('https://localhost/api/v1/firewalls/'.$this->firewall->id.'/status',
            ['Authorization' => 'Bearer '.$this->msp])->assertOk();
        Http::assertNothingSent();
    }

    public function test_dedicated_snapshot_wins_over_old_dashboard_cache(): void
    {
        $this->seedCache(['version' => 'old']);
        $snapshot = app(FirewallStatusNormalizer::class)->normalize($this->firewall->id,
            ['online' => true, 'checked_at' => now()->toIso8601String(), 'data' => ['version' => 'new']]);
        app(FirewallStatusSnapshot::class)->store($this->firewall->id, $snapshot);
        $this->getStatus('firewalls/'.$this->firewall->id.'/status')->assertOk()->assertJsonPath('data.system.version', 'new');
        Http::assertNothingSent();
    }

    public function test_collector_uses_read_only_calls_and_caches_only_allowlisted_fields(): void
    {
        $requests = [];
        Http::fake(function ($request) use (&$requests) {
            $requests[] = $request->method();
            $path = parse_url($request->url(), PHP_URL_PATH);
            $data = match (true) {
                str_ends_with($path, '/system/version') => ['version' => '26.07-RELEASE'],
                str_ends_with($path, '/system/hostname') => ['hostname' => 'example.local'],
                str_ends_with($path, '/status/system') => ['cpu_usage' => 24.5, 'mem_usage' => 33],
                str_ends_with($path, '/status/interfaces') => [['id' => 'wan', 'name' => 'WAN', 'status' => 'up', 'inbytes' => 100, 'outbytes' => 200]],
                str_ends_with($path, '/firewall/rules') => [['id' => 1, 'secret' => 'never-export-rule']],
                str_ends_with($path, '/status/services') => [['name' => 'ntpd', 'status' => false], ['name' => 'dns', 'status' => true]],
                str_ends_with($path, '/status/ipsec/sas') => [['name' => 'Office tunnel', 'state' => 'ESTABLISHED', 'remote_host' => '198.51.100.7', 'pre_shared_key' => 'never-export-psk']],
                default => [],
            };
            return Http::response(['data' => $data, 'status' => 200]);
        });
        $result = app(FirewallStatusCollector::class)->collect($this->firewall);
        $this->assertTrue($result['online']);
        $this->assertEquals(24.5, $result['usage']['cpu_pct']);
        $this->assertSame(1, $result['firewall_rules']);
        $this->assertSame(['running' => 1, 'total' => 2, 'stopped' => ['ntpd']], $result['services']);
        $this->assertSame('up', $result['vpn'][0]['status']);
        $this->assertStringNotContainsString('never-export', json_encode($result));
        $this->assertNotEmpty($requests);
        $this->assertSame(['GET'], array_values(array_unique($requests)));
        $this->assertEquals($result, Cache::get(FirewallStatusSnapshot::key($this->firewall->id)));
    }

    public function test_collector_failure_never_caches_raw_error_or_credentials(): void
    {
        Http::fake(fn () => throw new \RuntimeException('Secret URL https://user:password@host private-token-probe'));
        $result = app(FirewallStatusCollector::class)->collect($this->firewall);
        $this->assertFalse($result['online']);
        $this->assertNull($result['usage']['cpu_pct']);
        $this->assertStringNotContainsString('private-token-probe', json_encode($result));
        $this->assertStringNotContainsString('user:password', json_encode($result));
    }

    public function test_opnsense_uses_runtime_values_and_does_not_invent_missing_cpu(): void
    {
        $this->firewall->update(['os_type' => 'opnsense', 'api_key' => 'test-key', 'api_secret' => 'test-secret']);
        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            return Http::response(match (true) {
                str_ends_with($path, '/systemTime') => ['uptime' => '2 days, 01:02:03', 'loadavg' => '0.1,0.2,0.3'],
                str_ends_with($path, '/systemInformation') => ['name' => 'example.local', 'versions' => ['OPNsense 26.7']],
                str_ends_with($path, '/systemResources') => ['memory' => ['used' => 30, 'total' => 100]],
                str_ends_with($path, '/systemDisk') => [['mountpoint' => '/', 'used_pct' => '20%']],
                default => [],
            });
        });
        $result = app(FirewallStatusCollector::class)->collect($this->firewall);
        $this->assertTrue($result['online']);
        $this->assertSame('26.7', $result['system']['version']);
        $this->assertNull($result['usage']['cpu_pct']);
        $this->assertNull($result['system']['cpu']['count']);
        $this->assertEquals(30, $result['usage']['memory_pct']);
        $this->assertContains('vpn.openvpn', $result['collection']['unavailable_sections']);
    }

    public function test_background_dispatch_uses_dedicated_queue_and_requires_async_driver(): void
    {
        Queue::fake();
        config(['queue.default' => 'sync']);
        $this->artisan('integration:status:collect')->assertFailed();
        config(['queue.default' => 'redis']);
        $this->artisan('integration:status:collect', ['--firewall' => $this->firewall->id])->assertSuccessful();
        Queue::assertPushedOn('integration-status', \App\Jobs\CollectIntegrationFirewallStatus::class);
    }
}
