<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Firewall;
use App\Models\User;
use App\Services\FirewallApiCache;
use App\Services\PfSenseApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FirewallApiCacheTest extends TestCase
{
    use RefreshDatabase;

    private function firewall(): Firewall
    {
        $c = Company::create(['name' => 'Acme']);
        return Firewall::create(['name' => 'Acme HQ', 'company_id' => $c->id, 'url' => 'https://hq.test', 'os_type' => 'pfsense', 'auth_method' => 'basic', 'api_key' => 'u', 'api_secret' => 'p', 'netgate_id' => 'acme-hq']);
    }

    public function test_configuration_reads_are_kept_briefly_and_forgotten_after_a_change(): void
    {
        config(['pfcentral.api_cache_seconds' => 15]);
        $fw = $this->firewall();
        $aliases = 0;
        $status = 0;
        Http::fake(function ($r) use (&$aliases, &$status) {
            $url = $r->url();
            if (str_contains($url, '/firewall/aliases')) {
                $aliases++;
                return Http::response(['data' => [['name' => 'A' . $aliases, 'secret' => 'k3y']]]);
            }
            if (str_contains($url, '/status/gateways')) {
                $status++;
                return Http::response(['data' => [['status' => $status]]]);
            }
            if (str_contains($url, '/firewall/alias')) {
                return Http::response(['data' => ['ok' => true]]);
            }
            return Http::response([], 404);
        });

        $api = new PfSenseApiService($fw);
        $this->assertSame('A1', $api->get('firewall/aliases')['data'][0]['name']);
        $this->assertSame('A1', (new PfSenseApiService($fw->fresh()))->get('firewall/aliases')['data'][0]['name']);
        $this->assertSame(1, $aliases);

        // Different query: separate entry.
        $api->get('firewall/aliases', ['limit' => 5]);
        $this->assertSame(2, $aliases);

        // Live endpoints always go to the firewall.
        $api->get('status/gateways');
        $api->get('status/gateways');
        $this->assertSame(2, $status);

        // A change forgets what was kept.
        $api->post('firewall/alias', ['name' => 'B']);
        $this->assertSame('A3', $api->get('firewall/aliases')['data'][0]['name']);

        // Kept values are encrypted.
        foreach (['fwapi:'] as $prefix) {
            $store = Cache::getStore();
            $raw = (new \ReflectionProperty($store, 'storage'))->getValue($store);
            foreach ($raw as $key => $entry) {
                if (str_starts_with($key, $prefix)) {
                    $this->assertStringNotContainsString('k3y', serialize($entry));
                }
            }
        }
    }

    public function test_it_can_be_turned_off(): void
    {
        config(['pfcentral.api_cache_seconds' => 0]);
        $fw = $this->firewall();
        $n = 0;
        Http::fake(function () use (&$n) {
            $n++;
            return Http::response(['data' => []]);
        });
        $api = new PfSenseApiService($fw);
        $api->get('firewall/aliases');
        $api->get('firewall/aliases');
        $this->assertSame(2, $n);
        $this->assertSame(0, FirewallApiCache::ttlFor('firewall/aliases'));
    }

    public function test_pages_load_ahead_and_a_page_loaded_ahead_does_not_mark_release_notes_seen(): void
    {
        $u = User::create(['name' => 'Jay', 'email' => 'admin@example.com', 'password' => 'password', 'role' => 'admin', 'company_id' => null]);
        $u->forceFill(['email_verified_at' => now(), 'whats_new_seen' => 'v0.0.1'])->save();

        $ahead = $this->actingAs($u)->withHeader('Sec-Purpose', 'prefetch;prerender')->get('/dashboard');
        $ahead->assertOk()->assertSee('speculationrules', false)->assertSee('pfc-nav-bar', false);
        $this->assertSame('v0.0.1', $u->fresh()->whats_new_seen);

        $this->withHeaders(['Sec-Purpose' => ''])->get('/dashboard')->assertOk();
        $this->assertNotSame('v0.0.1', $u->fresh()->whats_new_seen);
    }
}
