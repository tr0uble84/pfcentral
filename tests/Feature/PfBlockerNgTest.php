<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Firewall;
use App\Models\User;
use App\Services\PfBlockerNgService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PfBlockerNgTest extends TestCase
{
    use RefreshDatabase;

    /** Commands the fake firewall received. */
    protected array $commands = [];

    /** Bridge calls: [action, args]. */
    protected array $calls = [];

    /** What the fake bridge answers, per action. */
    protected array $answers = [];

    protected bool $bridgeOnFirewall = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('x', 32))]);
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->answers = ['status' => $this->statusFixture()];
        $this->fakeFirewall();
    }

    private function fixture(string $os = 'pfsense'): array
    {
        $company = Company::create(['name' => 'Acme Corp']);
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@acme.test', 'password' => 'password', 'role' => 'admin', 'company_id' => $company->id]);
        $reader = User::create(['name' => 'Reader', 'email' => 'reader@acme.test', 'password' => 'password', 'role' => 'readonly', 'company_id' => $company->id]);
        $firewall = Firewall::create([
            'name' => 'Main Office', 'company_id' => $company->id, 'url' => 'https://fw.acme.test', 'os_type' => $os,
            'auth_method' => 'basic', 'api_key' => 'k', 'api_secret' => 's', 'netgate_id' => 'fw-1',
        ]);

        return [$admin, $reader, $firewall];
    }

    private function statusFixture(array $overrides = []): array
    {
        return array_replace_recursive([
            'ok' => true,
            'installed' => true,
            'package' => ['name' => 'pfSense-pkg-pfBlockerNG-devel', 'version' => '3.2.16'],
            'general' => ['configured' => true, 'enable_cb' => 'on', 'pfb_keep' => 'on', 'pfb_interval' => '1', 'pfb_min' => '0', 'pfb_hour' => '0', 'pfb_dailystart' => '0', 'skipfeed' => '0'],
            'ip' => ['inbound_interface' => ['wan'], 'outbound_interface' => ['lan'], 'inbound_deny_action' => 'block', 'outbound_deny_action' => 'reject',
                'ip_placeholder' => '127.1.7.7', 'maxmind_locale' => 'en', 'asn_reporting' => 'disabled', 'pass_order' => 'order_0', 'autorule_suffix' => 'autorule',
                'maxmind_key_set' => true, 'asn_token_set' => false, 'v4suppression' => '', 'suppression' => 'on'],
            'dnsbl' => ['pfb_dnsbl' => 'on', 'dnsbl_mode' => 'dnsbl_python', 'pfb_dnsvip4' => '_vipabc', 'pfb_dnsport' => '8081', 'pfb_dnsport_ssl' => '8443',
                'dnsbl_allow_int' => [], 'suppression' => '.github.com', 'tldblacklist' => '', 'tldwhitelist' => ''],
            'groups' => [
                'ipv4' => [['index' => 0, 'aliasname' => 'PRI1', 'description' => 'Primary tier', 'action' => 'Deny_Both', 'cron' => '01hour', 'dow' => '1',
                    'aliaslog' => 'enabled', 'logging' => 'enabled', 'rows' => [['format' => 'auto', 'state' => 'Enabled', 'url' => 'https://feodo.test/list.txt', 'header' => 'Feodo']], 'custom' => '', 'entries' => 1234]],
                'ipv6' => [],
                'dnsbl' => [['index' => 0, 'aliasname' => 'ADs_Basic', 'description' => 'Ads', 'action' => 'unbound', 'cron' => 'EveryDay', 'dow' => '1',
                    'aliaslog' => 'enabled', 'logging' => 'enabled', 'rows' => [['format' => 'auto', 'state' => 'Enabled', 'url' => 'https://hosts.test/hosts', 'header' => 'StevenBlack_ADs']], 'custom' => '', 'entries' => 98765]],
            ],
            'interfaces' => ['wan' => 'WAN', 'lan' => 'LAN'],
            'vips' => ['_vipabc' => '10.10.10.1 (Localhost)'],
            'options' => [
                'interval' => ['1' => 'Every hour', '24' => 'Once a day'], 'min' => ['0' => '00'], 'hour' => [0, 1], 'skipfeed' => ['0' => 'No limit'],
                'deny_action' => ['block' => 'Block', 'reject' => 'Reject'], 'pass_order' => ['order_0' => 'Default'], 'autorule_suffix' => ['autorule' => 'auto rule'],
                'asn_reporting' => ['disabled' => 'Disabled'], 'maxmind_locale' => ['en' => 'English'], 'dnsbl_mode' => ['dnsbl_unbound' => 'Unbound mode', 'dnsbl_python' => 'Unbound Python mode'],
                'ip_action' => ['Disabled' => 'Disabled', 'Deny_Both' => 'Deny Both', 'Deny_Outbound' => 'Deny Outbound'], 'dnsbl_action' => ['Disabled' => 'Disabled', 'unbound' => 'Unbound (block)'],
                'cron' => ['Never' => 'Never', '01hour' => 'Every hour', 'EveryDay' => 'Once a day', 'Weekly' => 'Weekly'], 'dow' => ['1' => 'Monday'],
                'state' => ['Enabled' => 'On', 'Disabled' => 'Off'], 'ip_format' => ['auto' => 'Auto'], 'dnsbl_format' => ['auto' => 'Auto'],
                'aliaslog' => ['enabled' => 'Enabled', 'disabled' => 'Disabled'], 'dnsbl_logging' => ['enabled' => 'DNSBL web server / VIP'],
            ],
            'counts' => ['ip_entries' => 1234, 'ip_aliases' => 1, 'dnsbl_domains' => 98765, 'events' => ['ip_block' => 5, 'ip_permit' => 0, 'ip_match' => 0, 'dnsbl' => 7]],
            'running' => false,
            'last_update' => time() - 600,
            'cron' => true,
        ], $overrides);
    }

    /**
     * A fake pfSense REST API command prompt that behaves like the firewall: it keeps
     * uploaded pieces, installs the helper, and answers helper calls.
     */
    private function fakeFirewall(): void
    {
        $files = [];
        Http::fake(['https://fw.acme.test/api/v2/diagnostics/command_prompt' => function (HttpRequest $request) use (&$files) {
            $cmd = $request['command'];
            $this->commands[] = $cmd;
            $this->assertLessThanOrEqual(1024, strlen($cmd), 'pfSense REST API commands are limited to 1024 characters');
            $out = '';

            if (str_starts_with($cmd, 'test -f ')) {
                $out = $this->bridgeOnFirewall ? 'ACXOK' : 'ACXMISSING';
            } elseif (preg_match("/^printf '%s' '([A-Za-z0-9+\\/=]+)' >> (\\/tmp\\/acx_[a-z0-9_]+)$/", $cmd, $m)) {
                $files[$m[2]] = ($files[$m[2]] ?? '') . $m[1];
            } elseif (str_starts_with($cmd, "/usr/local/bin/php -r '")) {
                preg_match('#file_get_contents\("(/tmp/acx_up_[a-z0-9]+)"\)#', $cmd, $m);
                $script = gzuncompress(base64_decode($files[$m[1]] ?? ''));
                $this->assertSame(PfBlockerNgService::script(), $script, 'the helper arrives on the firewall intact');
                $this->bridgeOnFirewall = true;
                $out = 'ACXOK';
            } elseif (preg_match("#^/usr/local/bin/php /root/\\.pfcentral/pfbng-[0-9a-f]{12}\\.php '([a-z_]+)'(?: (\\S+))?$#", $cmd, $m)) {
                $raw = $m[2] ?? '';
                if (str_starts_with($raw, '@')) {
                    $raw = $files[substr($raw, 1)] ?? '';
                }
                $args = $raw === '' ? [] : json_decode(base64_decode($raw), true);
                $this->calls[] = [$m[1], $args];
                $answer = $this->answers[$m[1]] ?? ['ok' => true];
                $out = "PHP Warning: noise\nACXPFB:" . base64_encode(json_encode($answer)) . "\n";
            } else {
                $this->fail("Unexpected command: {$cmd}");
            }

            return Http::response(['code' => 200, 'status' => 'ok', 'data' => ['output' => $out, 'result_code' => 0]]);
        }]);
    }

    private function base(Firewall $firewall): string
    {
        return "/firewall/{$firewall->id}/firewall/pfblockerng";
    }

    private function lastCall(): array
    {
        return end($this->calls) ?: [null, null];
    }

    public function test_overview_installs_helper_in_short_pieces_and_shows_status(): void
    {
        [$admin, , $firewall] = $this->fixture();

        $this->actingAs($admin)->get($this->base($firewall))
            ->assertOk()
            ->assertSee('pfBlockerNG')
            ->assertSee('Enabled')
            ->assertSee('3.2.16')
            ->assertSee('98,765')
            ->assertSee('PRI1')
            ->assertSee('ADs_Basic');

        $this->assertTrue($this->bridgeOnFirewall);
        $this->assertGreaterThan(3, count(array_filter($this->commands, fn ($c) => str_starts_with($c, 'printf'))));

        // The helper is only copied once: the next page load goes straight to the call.
        $this->commands = [];
        $this->actingAs($admin)->get($this->base($firewall) . '?tab=general')->assertOk()->assertSee('Save general settings');
        $this->assertCount(1, $this->commands);
    }

    public function test_every_tab_renders(): void
    {
        [$admin, , $firewall] = $this->fixture();
        $this->answers['reports'] = ['ok' => true, 'ip' => [[
            'kind' => 'block', 'time' => 'Nov 20 10:00:01', 'interface' => 'WAN', 'action' => 'block', 'proto' => 'TCP-S', 'src' => '185.1.2.3', 'dst' => '203.0.113.5',
            'sport' => '51234', 'dport' => '22', 'direction' => 'in', 'geoip' => 'RU', 'alias' => 'pfB_PRI1_v4', 'evaluated' => '185.1.2.0/24', 'feed' => 'Feodo', 'host' => 'Unknown', 'client' => 'Unknown', 'asn' => 'AS1234',
        ]], 'dnsbl' => [[
            'type' => 'DNSBL-python', 'time' => 'Nov 20 10:01:02', 'domain' => 'ads.example.com', 'client' => '192.168.1.20', 'p_type' => 'HTTPS', 'b_type' => 'DNSBL',
            'group' => 'ADs_Basic', 'evaluated' => 'example.com', 'feed' => 'StevenBlack_ADs',
        ]], 'top' => ['ip_hosts' => ['185.1.2.3' => 1], 'dns_domains' => ['example.com' => 1]]];
        $this->answers['log'] = ['ok' => true, 'name' => 'pfblockerng', 'exists' => true, 'size' => 2048, 'mtime' => time(), 'lines' => ['UPDATE PROCESS START [ v3.2.16 ]']];
        $this->answers['catalog'] = ['ok' => true, 'catalog' => ['ipv4' => [['key' => 'PRI2', 'description' => 'Secondary tier', 'info' => '', 'cron' => '01hour', 'added' => false,
            'feeds' => [['feed' => 'Some feed', 'website' => '', 'header' => 'Some', 'url' => 'https://x.test', 'register' => false, 'discontinued' => false]]]]]];

        $expect = [
            'overview' => 'Feed groups', 'general' => 'Update frequency', 'ip' => 'Inbound interfaces', 'dnsbl' => 'DNSBL whitelist',
            'feeds' => 'Secondary tier', 'update' => 'Force reload', 'reports' => 'ads.example.com', 'logs' => 'UPDATE PROCESS START', 'setup' => 'Run Quick setup',
        ];
        foreach ($expect as $tab => $text) {
            $this->actingAs($admin)->get($this->base($firewall) . "?tab={$tab}")->assertOk()->assertSee($text);
        }

        // Secrets are never sent back to the browser.
        $this->actingAs($admin)->get($this->base($firewall) . '?tab=ip')->assertSee('Saved. Type a new key to replace it.');
    }

    public function test_not_installed_offers_install_and_installs_package(): void
    {
        [$admin, , $firewall] = $this->fixture();
        $this->answers['status'] = ['ok' => true, 'installed' => false, 'package' => []];

        $this->actingAs($admin)->get($this->base($firewall))->assertOk()->assertSee('pfBlockerNG is not installed')->assertSee('Install pfBlockerNG');

        Http::fake(['https://fw.acme.test/api/v2/system/package' => Http::response(['code' => 200, 'data' => []])]);
        $this->actingAs($admin)->post($this->base($firewall) . '/install')->assertRedirect()->assertSessionHas('success');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/system/package') && $r['name'] === PfBlockerNgService::PACKAGE);
    }

    public function test_opnsense_shows_not_supported_without_calling_the_firewall(): void
    {
        [$admin, , $firewall] = $this->fixture('opnsense');

        $this->actingAs($admin)->get($this->base($firewall))->assertOk()->assertSee('pfBlockerNG Not Supported');
        $this->assertSame([], $this->commands);
    }

    public function test_saving_settings_sends_checkboxes_and_values(): void
    {
        [$admin, , $firewall] = $this->fixture();

        $this->actingAs($admin)->post($this->base($firewall) . '/general', [
            'enable_cb' => 'on', 'pfb_interval' => '24', 'pfb_min' => '0', 'pfb_hour' => '0', 'pfb_dailystart' => '3', 'skipfeed' => '0',
        ])->assertRedirect($this->base($firewall) . '?tab=general')->assertSessionHas('success');
        [$action, $args] = $this->lastCall();
        $this->assertSame('save_general', $action);
        $this->assertSame('on', $args['enable_cb']);
        $this->assertSame('', $args['pfb_keep']);
        $this->assertSame('24', $args['pfb_interval']);

        $this->actingAs($admin)->post($this->base($firewall) . '/ip', [
            'ip_placeholder' => '127.1.7.7', 'maxmind_locale' => 'en', 'asn_reporting' => 'disabled', 'inbound_interface' => ['wan'],
            'inbound_deny_action' => 'block', 'outbound_deny_action' => 'reject', 'pass_order' => 'order_0', 'autorule_suffix' => 'autorule',
            'v4suppression' => str_repeat("10.0.0.0/24 # office\n", 80), 'reload' => 'on',
        ])->assertSessionHas('success');
        [$action, $args] = $this->lastCall();
        $this->assertSame('save_ip', $action);
        $this->assertSame([], $args['outbound_interface']);
        $this->assertSame('on', $args['reload']);
        $this->assertStringContainsString('10.0.0.0/24', $args['v4suppression']); // long values travel as an uploaded file

        $this->actingAs($admin)->post($this->base($firewall) . '/dnsbl', [
            'dnsbl_mode' => 'dnsbl_python', 'pfb_dnsvip4' => '_vipabc', 'pfb_dnsport' => 8081, 'pfb_dnsport_ssl' => 8443, 'pfb_dnsbl' => 'on', 'suppression' => '.github.com',
        ])->assertSessionHas('success');
        $this->assertSame('save_dnsbl', $this->lastCall()[0]);
    }

    public function test_firewall_errors_are_shown_to_the_user(): void
    {
        [$admin, , $firewall] = $this->fixture();
        $this->answers['save_dnsbl'] = ['ok' => false, 'error' => 'DNSBL virtual IP: IPv4 VIP not on interface Localhost.'];

        $this->actingAs($admin)->from($this->base($firewall) . '?tab=dnsbl')->post($this->base($firewall) . '/dnsbl', [
            'dnsbl_mode' => 'dnsbl_unbound', 'pfb_dnsport' => 8081, 'pfb_dnsport_ssl' => 8443, 'pfb_dnsbl' => 'on',
        ])->assertRedirect($this->base($firewall) . '?tab=dnsbl')->assertSessionHas('error', 'DNSBL virtual IP: IPv4 VIP not on interface Localhost.');
    }

    public function test_feed_groups_can_be_added_edited_and_deleted(): void
    {
        [$admin, , $firewall] = $this->fixture();

        $this->actingAs($admin)->get($this->base($firewall) . '/groups/ipv4/0/edit')->assertOk()->assertSee('PRI1')->assertSee('feodo.test');
        $this->actingAs($admin)->get($this->base($firewall) . '/groups/dnsbl/create')->assertOk()->assertSee('Add group');
        $this->actingAs($admin)->get($this->base($firewall) . '/groups/ipv4/9/edit')->assertRedirect()->assertSessionHas('error');

        $this->answers['group_save'] = ['ok' => true, 'aliasname' => 'Office_Block', 'update' => ['started' => 'Force Update']];
        $this->actingAs($admin)->post($this->base($firewall) . '/groups/ipv4', [
            'aliasname' => 'Office_Block', 'description' => 'Blocked by the office', 'action' => 'Deny_Both', 'cron' => 'EveryDay',
            'rows' => [['state' => 'Enabled', 'format' => 'auto', 'url' => 'https://x.test/list.txt', 'header' => 'XList']], 'custom' => "203.0.113.5\n", 'update' => 'on',
        ])->assertRedirect($this->base($firewall) . '?tab=feeds')->assertSessionHas('success');
        [$action, $args] = $this->lastCall();
        $this->assertSame('group_save', $action);
        $this->assertSame('ipv4', $args['type']);
        $this->assertNull($args['index']);
        $this->assertSame('XList', $args['rows'][0]['header']);

        $this->actingAs($admin)->put($this->base($firewall) . '/groups/ipv4/0', [
            'aliasname' => 'PRI1', 'action' => 'Deny_Outbound', 'cron' => '01hour', 'rows' => [['state' => 'Enabled', 'url' => 'https://feodo.test/list.txt', 'header' => 'Feodo']],
        ])->assertSessionHas('success');
        $this->assertSame(0, $this->lastCall()[1]['index']);

        $this->actingAs($admin)->post($this->base($firewall) . '/groups/ipv4', ['aliasname' => 'has space', 'action' => 'Deny_Both', 'cron' => 'Never'])
            ->assertSessionHasErrors('aliasname');

        $this->actingAs($admin)->delete($this->base($firewall) . '/groups/ipv4/0?aliasname=PRI1')->assertSessionHas('success');
        [$action, $args] = $this->lastCall();
        $this->assertSame(['group_delete', 'PRI1', 0], [$action, $args['aliasname'], $args['index']]);
    }

    public function test_catalog_update_whitelist_quick_setup_and_progress(): void
    {
        [$admin, , $firewall] = $this->fixture();

        $this->actingAs($admin)->post($this->base($firewall) . '/catalog', ['type' => 'dnsbl', 'key' => 'Malicious', 'action' => 'unbound', 'update' => 'on'])->assertSessionHas('success');
        $this->assertSame(['type' => 'dnsbl', 'key' => 'Malicious', 'action' => 'unbound', 'update' => 'on'], $this->lastCall()[1]);

        $this->answers['run'] = ['ok' => true, 'started' => 'Force Reload - DNSBL'];
        $this->actingAs($admin)->post($this->base($firewall) . '/run', ['task' => 'reload', 'reload' => 'DNSBL'])->assertRedirect($this->base($firewall) . '?tab=update');
        $this->actingAs($admin)->post($this->base($firewall) . '/run', ['task' => 'rm -rf'])->assertSessionHasErrors('task');

        $this->actingAs($admin)->post($this->base($firewall) . '/whitelist', ['domain' => 'ads.example.com'])->assertSessionHas('success');
        $this->assertSame('whitelist_domain', $this->lastCall()[0]);

        $setup = ['inbound_interface' => ['wan'], 'outbound_interface' => ['lan'], 'vip_address' => '10.10.10.1', 'pfb_dnsport' => 8081, 'pfb_dnsport_ssl' => 8443];
        $this->actingAs($admin)->post($this->base($firewall) . '/quick-setup', $setup)->assertSessionHasErrors('confirm');
        $this->answers['quick_setup'] = ['ok' => true, 'created_vip' => '10.10.10.1', 'reload' => ['started' => 'Force Reload - All']];
        $this->actingAs($admin)->post($this->base($firewall) . '/quick-setup', $setup + ['confirm' => '1'])
            ->assertRedirect($this->base($firewall) . '?tab=update')->assertSessionHas('success');
        $this->assertSame('quick_setup', $this->lastCall()[0]);

        $this->answers['progress'] = ['ok' => true, 'running' => true, 'log' => ['Downloading PRI1'], 'log_mtime' => time(), 'now' => time()];
        $this->actingAs($admin)->getJson($this->base($firewall) . '/progress')->assertOk()->assertJson(['running' => true, 'log' => ['Downloading PRI1']]);
    }

    public function test_readonly_user_can_view_but_not_change(): void
    {
        [, $reader, $firewall] = $this->fixture();

        $this->actingAs($reader)->get($this->base($firewall) . '?tab=general')->assertOk()->assertDontSee('Save general settings');
        $this->actingAs($reader)->getJson($this->base($firewall) . '/progress')->assertOk();

        $this->calls = [];
        foreach (['/general', '/ip', '/dnsbl', '/run', '/catalog', '/whitelist', '/quick-setup', '/install', '/groups/ipv4'] as $path) {
            $this->actingAs($reader)->post($this->base($firewall) . $path, ['task' => 'update'])->assertForbidden();
        }
        $this->actingAs($reader)->delete($this->base($firewall) . '/groups/ipv4/0?aliasname=PRI1')->assertForbidden();
        $this->assertSame([], array_filter($this->calls, fn ($c) => $c[0] !== 'status'));
    }

    public function test_other_companies_cannot_open_the_page(): void
    {
        [, , $firewall] = $this->fixture();
        $other = Company::create(['name' => 'Other']);
        $stranger = User::create(['name' => 'Stranger', 'email' => 's@other.test', 'password' => 'password', 'role' => 'admin', 'company_id' => $other->id]);

        $this->assertContains($this->actingAs($stranger)->get($this->base($firewall))->status(), [403, 404]);
        $this->assertSame([], $this->commands);
    }

    public function test_menu_links_to_pfblockerng(): void
    {
        [$admin, , $firewall] = $this->fixture();

        $this->actingAs($admin)->get($this->base($firewall))->assertSee(route('firewall.pfblockerng.index', $firewall), false);
    }
}
