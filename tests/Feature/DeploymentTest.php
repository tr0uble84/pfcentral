<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deployment;
use App\Models\DeploymentTarget;
use App\Models\Firewall;
use App\Models\User;
use App\Services\Auth\MspSignIn;
use App\Services\Deploy\DeployAccess;
use App\Services\Deploy\DeployHelper;
use App\Services\Deploy\DeployItems;
use App\Services\Deploy\DeployRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeploymentTest extends TestCase
{
    use RefreshDatabase;

    private const JAY = '55555555-5555-4555-8555-555555555555';
    private const SAM = '66666666-6666-4666-8666-666666666666';
    private const GROUP = '77777777-7777-4777-8777-777777777777';

    /** firewall name => what the fake firewall does */
    private array $fw = [];
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        DeployHelper::$fake = function (Firewall $f, string $action, array $args) {
            $this->calls[] = [$f->name, $action, $args];
            $mode = $this->fw[$f->name] ?? 'ok';
            $plan = array_map(fn ($n) => ['n' => $n, 'kind' => $args['items'][$n]['kind'] ?? 'rule', 'label' => '', 'status' => 'create', 'messages' => []], array_keys($args['items'] ?? []));
            if ($mode === 'no_pfb') {
                $plan = array_map(fn ($p) => $p['kind'] === 'pfb_ipv4' ? ['status' => 'needs_package', 'messages' => ['pfBlockerNG is not installed on this firewall.']] + $p : $p, $plan);
            }
            if ($mode === 'bad_iface') {
                $plan[0]['status'] = 'error';
                $plan[0]['messages'] = ['Interface "DMZ" isn\'t on this firewall.'];
            }
            return match ($action) {
                'check' => ['ok' => true, 'version' => '2.8.1-RELEASE', 'pfblockerng' => ['installed' => $mode !== 'no_pfb', 'version' => '3.2.0', 'compatible' => $mode !== 'no_pfb'], 'interfaces' => [], 'plan' => $plan],
                'apply' => $mode === 'pf_fails'
                    ? ['ok' => false, 'error' => 'The firewall could not load the new rules: syntax error', 'undone' => true]
                    : ['ok' => true, 'changed' => count($plan), 'undo' => [['kind' => 'rule', 'key' => ['descr' => 'Allow RDP'], 'before' => null]], 'plan' => $plan, 'pfb_update' => true],
                'undo' => ['ok' => true, 'undone' => count($args['undo'] ?? [])],
            };
        };
    }

    protected function tearDown(): void
    {
        DeployHelper::$fake = null;
        MspSignIn::$factory = null;
        parent::tearDown();
    }

    private function world(): array
    {
        $acme = Company::create(['name' => 'Acme']);
        $beta = Company::create(['name' => 'Beta']);
        $mk = function (string $email, string $role, ?int $company) {
            $u = User::create(['name' => $email, 'email' => $email, 'password' => 'password', 'role' => $role, 'company_id' => $company]);
            $u->forceFill(['email_verified_at' => now()])->save();
            return $u;
        };
        $fw = fn (string $name, Company $c, string $os = 'pfsense') => Firewall::create(['name' => $name, 'company_id' => $c->id, 'url' => 'https://' . strtolower(str_replace(' ', '', $name)) . '.test', 'os_type' => $os, 'auth_method' => 'basic', 'api_key' => 'u', 'api_secret' => 'p', 'netgate_id' => strtolower(str_replace(' ', '-', $name))]);
        $jay = $mk('admin@example.com', 'admin', null);
        $jay->forceFill(['ms_oid' => self::JAY])->save();
        DeployAccess::set([['oid' => self::JAY, 'name' => 'Jay', 'upn' => 'admin@example.com']], [], 'test');
        return [
            'global' => $jay,
            'acmeAdmin' => $jay,
            'companyAdmin' => $mk('it@acme.test', 'admin', $acme->id),
            'acmeRo' => $mk('ro@acme.test', 'readonly', $acme->id),
            'hq' => $fw('Acme HQ', $acme),
            'branch' => $fw('Acme Branch', $acme),
            'opn' => $fw('Acme OPN', $acme, 'opnsense'),
            'beta' => $fw('Beta Main', $beta),
        ];
    }

    private function items(): array
    {
        return [
            ['kind' => 'pfb_ipv4', 'name' => 'RDPDC0pfblocker', 'descr' => 'RDP Dynamic IP Allow List', 'rows' => [['format' => 'rsync', 'state' => 'Flex', 'url' => 'https://lists.example.com/ip.txt', 'header' => 'RDPDC0Feed']], 'action' => 'Alias_Permit', 'cron' => '01hour'],
            ['kind' => 'nat', 'interface' => 'WAN', 'protocol' => 'tcp', 'source' => 'pfB_RDPDC0pfblocker', 'destination_port' => '3389', 'target' => '192.168.1.10', 'descr' => 'RDP to DC01'],
            ['kind' => 'rule', 'interface' => 'WAN', 'action' => 'block', 'protocol' => 'tcp', 'destination_port' => '3389', 'descr' => 'Allow RDP', 'placement' => 'bottom'],
            ['kind' => 'alias', 'name' => 'Office', 'type' => 'host', 'entries' => "1.2.3.4\n5.6.7.8"],
        ];
    }

    public function test_items_are_cleaned_and_named(): void
    {
        [$items, $errors] = DeployItems::normalize($this->items());
        $this->assertSame([], $errors);
        $this->assertSame("1.2.3.4\n5.6.7.8", $items[3]['entries']);
        $this->assertSame('Alias Office (host, 2 entries)', $items[3]['label']);
        $this->assertSame('pfBlockerNG IPv4 list RDPDC0pfblocker (Alias Permit)', $items[0]['label']);
        $this->assertStringContainsString('3389 → 192.168.1.10', $items[1]['label']);
        $this->assertSame(['pfBlockerNG'], DeployItems::packages($items));

        [, $errors] = DeployItems::normalize([['kind' => 'rule', 'descr' => ''], ['kind' => 'alias', 'name' => 'bad name!'], ['kind' => 'nat', 'descr' => 'x'], ['kind' => 'wat']]);
        $this->assertContains('Change 1 (Firewall rule): Description is needed.', $errors);
        $this->assertContains('Change 2 (Alias): the name can only use letters, numbers and underscores.', $errors);
        $this->assertContains('Change 3 (Port forward): Outside port is needed.', $errors);
        $this->assertContains('Change 4: unknown kind of change.', $errors);

        // Remove keeps only what identifies the item; settings can't be removed.
        [$items, $errors] = DeployItems::normalize([
            ['kind' => 'rule', 'mode' => 'remove', 'interface' => 'LAN', 'descr' => 'Old rule', 'action' => 'pass'],
            ['kind' => 'timezone', 'mode' => 'remove', 'timezone' => 'Nope/Zone'],
            ['kind' => 'schedule', 'name' => 'WorkHours', 'ranges' => [['days' => [5, 1, 9], 'start' => '08:00', 'stop' => '17:59']]],
            ['kind' => 'rule', 'interface' => '@internal', 'descr' => 'Block SMTP', 'action' => 'block'],
        ]);
        $this->assertSame([], $errors);
        $this->assertSame(['kind' => 'rule', 'mode' => 'remove', 'interface' => 'LAN', 'descr' => 'Old rule', 'label' => 'Remove rule on LAN: Old rule'], $items[0]);
        $this->assertSame(['present', 'America/Los_Angeles'], [$items[1]['mode'], $items[1]['timezone']]);
        $this->assertSame([1, 5], $items[2]['ranges'][0]['days']);
        $this->assertSame('Block rule on every internal interface: Block SMTP', $items[3]['label']);
        [, $errors] = DeployItems::normalize([]);
        $this->assertSame(['Add at least one change.'], $errors);
    }

    public function test_a_deployment_is_checked_per_firewall_and_scoped_to_the_company(): void
    {
        $w = $this->world();
        $this->fw = ['Acme Branch' => 'no_pfb'];

        $this->actingAs($w['acmeRo'])->get('/deployments')->assertOk();
        $this->actingAs($w['acmeRo'])->get('/deployments/new')->assertForbidden();

        $this->actingAs($w['companyAdmin'])->get('/deployments/new')->assertForbidden();
        // OPNsense is left out.
        $r = $this->actingAs($w['acmeAdmin'])->post('/deployments', [
            'name' => 'RDP allow list',
            'firewall_ids' => [$w['hq']->id, $w['branch']->id, $w['opn']->id, $w['beta']->id],
            'items' => json_encode($this->items()),
        ]);
        $d = Deployment::firstOrFail();
        $r->assertRedirect(route('deployments.show', $d))->assertSessionHas('warning');
        $this->assertNull($d->company_id); // spans two companies: only global admins see it
        $this->assertSame([$w['hq']->id, $w['branch']->id, $w['beta']->id], $d->targets()->orderBy('id')->pluck('firewall_id')->all());
        $d->targets()->where('firewall_id', $w['beta']->id)->delete();
        $d->update(['company_id' => $w['hq']->company_id]);

        $t = $d->targets()->get()->keyBy('firewall_id');
        $this->assertSame('ready', $t[$w['hq']->id]->status);
        $this->assertSame(1, $t[$w['hq']->id]->wave);
        $this->assertSame('needs_package', $t[$w['branch']->id]->status);
        $this->assertSame(2, $t[$w['branch']->id]->wave);
        $this->assertStringContainsString('pfBlockerNG is not installed', $t[$w['branch']->id]->message);
        $this->assertSame('ready', $d->fresh()->status);
        $this->assertSame(['check', 'check', 'check'], array_column($this->calls, 1));

        // Beta can't see it; the page and its data work for Acme.
        $this->actingAs($w['global'])->get("/deployments/{$d->id}")->assertOk()->assertSee('RDP allow list');
        $data = $this->actingAs($w['acmeRo'])->getJson("/deployments/{$d->id}/data")->assertOk()->json();
        $this->assertSame(['pfBlockerNG'], $data['packages']);
        $this->assertTrue($data['waves'][0]['canStart']);

        // Bad input comes back with errors.
        $this->actingAs($w['acmeAdmin'])->post('/deployments', ['firewall_ids' => [$w['hq']->id], 'items' => json_encode([['kind' => 'rule']])])
            ->assertSessionHasErrors('items');
    }

    public function test_waves_deploy_skip_firewalls_without_the_package_and_can_be_undone(): void
    {
        $w = $this->world();
        $this->fw = ['Acme Branch' => 'no_pfb'];
        $this->actingAs($w['acmeAdmin'])->post('/deployments', ['firewall_ids' => [$w['hq']->id, $w['branch']->id], 'items' => json_encode($this->items())]);
        $d = Deployment::firstOrFail();

        $this->actingAs($w['acmeRo'])->postJson("/deployments/{$d->id}/waves/1/start")->assertForbidden();
        $this->actingAs($w['acmeAdmin'])->postJson("/deployments/{$d->id}/waves/2/start")->assertStatus(409);
        $this->actingAs($w['acmeAdmin'])->postJson("/deployments/{$d->id}/waves/1/start")->assertOk();

        $hq = DeploymentTarget::where('firewall_id', $w['hq']->id)->first();
        $this->assertSame('done', $hq->status);
        $this->assertSame(4, $hq->changed);
        $this->assertStringContainsString('pfBlockerNG is updating', $hq->message);
        $this->assertNotEmpty($hq->undo);
        $raw = DB::table('deployment_targets')->where('id', $hq->id)->value('undo');
        $this->assertStringNotContainsString('Allow RDP', $raw);
        $apply = collect($this->calls)->firstWhere(1, 'apply');
        $this->assertStringContainsString("deployment #{$d->id}", $apply[2]['label']);

        // Wave 2: the firewall without pfBlockerNG is skipped and reported, which doesn't block the deployment.
        $this->actingAs($w['acmeAdmin'])->postJson("/deployments/{$d->id}/waves/2/start")->assertOk();
        $branch = DeploymentTarget::where('firewall_id', $w['branch']->id)->first();
        $this->assertSame('needs_package', $branch->status);
        $this->assertStringContainsString('install it and retry', $branch->message);
        $this->assertSame('completed', $d->fresh()->status);
        $this->assertSame(1, collect($this->calls)->where(1, 'apply')->count());

        // pfBlockerNG installed later: retry deploys it.
        $this->fw = [];
        $this->actingAs($w['acmeAdmin'])->postJson("/deployments/{$d->id}/targets/{$branch->id}/retry")->assertOk();
        $this->assertSame('done', $branch->fresh()->status);

        // Undo one, then all.
        $this->actingAs($w['acmeAdmin'])->postJson("/deployments/{$d->id}/targets/{$hq->id}/undo")->assertOk();
        $this->assertSame('reverted', $hq->fresh()->status);
        $this->assertSame([['kind' => 'rule', 'key' => ['descr' => 'Allow RDP'], 'before' => null]], collect($this->calls)->firstWhere(1, 'undo')[2]['undo']);
        $this->actingAs($w['acmeAdmin'])->postJson("/deployments/{$d->id}/undo")->assertOk();
        $this->assertSame('reverted', $branch->fresh()->status);
        $this->actingAs($w['acmeAdmin'])->postJson("/deployments/{$d->id}/undo")->assertStatus(409);
        $this->assertSame('completed', $d->fresh()->status);
    }

    public function test_a_firewall_that_cannot_load_the_rules_is_undone_and_a_bad_fit_is_not_deployed(): void
    {
        $w = $this->world();
        $this->actingAs($w['global'])->post('/deployments', ['firewall_ids' => [$w['hq']->id, $w['branch']->id], 'items' => json_encode($this->items())]);
        $d = Deployment::firstOrFail();
        DeploymentTarget::where('firewall_id', $w['branch']->id)->update(['wave' => 1]);
        $this->fw = ['Acme HQ' => 'pf_fails', 'Acme Branch' => 'bad_iface'];

        $this->actingAs($w['global'])->postJson("/deployments/{$d->id}/waves/1/start")->assertOk();
        $hq = DeploymentTarget::where('firewall_id', $w['hq']->id)->first();
        $branch = DeploymentTarget::where('firewall_id', $w['branch']->id)->first();
        $this->assertSame('undone', $hq->status);
        $this->assertStringContainsString('undone automatically', $hq->message);
        $this->assertSame('failed', $branch->status);
        $this->assertStringContainsString('nothing was changed', $branch->message);
        $this->assertSame(1, collect($this->calls)->where(1, 'apply')->count());
        $this->assertSame('attention', $d->fresh()->status);

        $this->actingAs($w['global'])->postJson("/deployments/{$d->id}/targets/{$branch->id}/skip")->assertOk();
        $this->fw = [];
        $this->actingAs($w['global'])->postJson("/deployments/{$d->id}/targets/{$hq->id}/retry")->assertOk();
        $this->assertSame('done', $hq->fresh()->status);
        $this->assertSame('completed', $d->fresh()->status);
    }

    public function test_a_wave_can_be_scheduled_and_drafts_edited(): void
    {
        $w = $this->world();
        $this->actingAs($w['global'])->post('/deployments', ['firewall_ids' => [$w['hq']->id, $w['branch']->id], 'items' => json_encode($this->items())]);
        $d = Deployment::firstOrFail();

        // Edit while nothing has started: checks run again.
        $this->actingAs($w['global'])->get("/deployments/{$d->id}/edit")->assertOk()->assertSee('Check the firewalls');
        $this->actingAs($w['global'])->put("/deployments/{$d->id}", ['name' => 'Only the rule', 'firewall_ids' => [$w['hq']->id], 'items' => json_encode([$this->items()[2]])])
            ->assertRedirect(route('deployments.show', $d));
        $d->refresh();
        $this->assertSame('Only the rule', $d->name);
        $this->assertCount(1, $d->items);
        $this->assertSame(1, $d->targets()->count());

        $this->actingAs($w['global'])->postJson("/deployments/{$d->id}/waves/1/schedule", ['at' => now()->subHour()->format('Y-m-d\TH:i'), 'tz' => 'UTC'])->assertStatus(422);
        $this->actingAs($w['global'])->postJson("/deployments/{$d->id}/waves/1/schedule", ['at' => now()->addHour()->format('Y-m-d\TH:i'), 'tz' => 'America/Los_Angeles'])->assertOk();
        $this->assertSame(1, $d->fresh()->scheduled_wave);

        DeployRunner::runDue();
        $this->assertSame('ready', $d->targets()->first()->status);

        $this->travel(10)->hours();
        DeployRunner::runDue();
        $this->assertSame('done', $d->targets()->first()->status);
        $this->assertNull($d->fresh()->scheduled_at);
        $this->actingAs($w['global'])->get("/deployments/{$d->id}/edit")->assertStatus(409);
        $this->actingAs($w['global'])->get('/deployments')->assertOk()->assertSee('Only the rule')->assertSee('1 of 1 deployed');
    }

    public function test_only_the_picked_microsoft_365_people_and_groups_can_deploy(): void
    {
        $w = $this->world();
        $ms = new MspFakeMicrosoft();
        $ms->groups = [self::GROUP => ['name' => 'Firewall Team', 'members' => [self::SAM]]];
        MspSignIn::$factory = fn () => $ms;
        MspSignIn::set('tenant_id', PfCentralBrandAndSignInTest::TENANT);
        MspSignIn::set('client_id', 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        MspSignIn::setClientSecret('s3cret');

        $sam = User::create(['name' => 'Sam', 'email' => 'tech@example.com', 'password' => 'password', 'role' => 'admin', 'company_id' => null]);
        $sam->forceFill(['email_verified_at' => now(), 'ms_oid' => self::SAM])->save();
        $pw = User::create(['name' => 'Local', 'email' => 'local@cfbtel.com', 'password' => 'password', 'role' => 'admin', 'company_id' => null]);
        $pw->forceFill(['email_verified_at' => now()])->save();

        // Jay is picked by name; Sam isn't yet; a global admin without Microsoft 365 never can; company admins never can.
        $this->assertTrue(DeployAccess::allows($w['global']));
        $this->actingAs($sam)->get('/deployments/new')->assertForbidden();
        $this->actingAs($pw)->get('/deployments/new')->assertForbidden();
        $this->actingAs($w['companyAdmin'])->get('/deployments/new')->assertForbidden();
        $this->actingAs($sam)->get('/deployments')->assertOk()->assertSee('You are not one of the people who can deploy')->assertDontSee('href="' . route('deployments.create') . '"', false);

        // Only global admins pick who can deploy, from the Microsoft 365 tenant.
        $this->actingAs($w['companyAdmin'])->getJson('/deployments/access/search?type=groups&q=fire')->assertForbidden();
        $this->actingAs($w['global'])->getJson('/deployments/access/search?type=groups&q=fire')->assertOk()->assertJsonPath('results.0.name', 'Firewall Team');
        $this->actingAs($w['global'])->postJson('/deployments/access', [
            'users' => [['oid' => self::JAY, 'name' => 'Jay', 'upn' => 'admin@example.com']],
            'groups' => [['id' => self::GROUP, 'name' => 'Firewall Team'], ['id' => 'not-a-guid', 'name' => 'x']],
        ])->assertOk()->assertJsonCount(1, 'access.groups');

        // Sam is in the group (checked with Microsoft, then remembered for a few minutes).
        $this->actingAs($sam)->get('/deployments/new')->assertOk();
        $this->actingAs($sam)->get('/deployments/new')->assertOk();
        $this->assertSame(1, collect($ms->calls)->filter(fn ($c) => $c === 'memberOf ' . self::SAM)->count());
        $this->actingAs($sam)->get('/deployments')->assertSee('Who can deploy')->assertSee('Firewall Team');

        // Nobody picked: nobody can deploy, not even Jay.
        DeployAccess::set([], [], 'test');
        $this->actingAs($w['global'])->get('/deployments/new')->assertForbidden();
        $this->actingAs($w['global'])->get('/deployments')->assertSee('Nobody can deploy yet');
    }

    public function test_changes_can_be_copied_from_a_firewall(): void
    {
        $w = $this->world();
        DeployHelper::$fake = function (Firewall $f, string $action) {
            $this->assertSame('export', $action);
            return ['ok' => true, 'version' => '2.8.1', 'items' => [
                ['kind' => 'alias', 'name' => 'Office', 'type' => 'host', 'entries' => ['1.2.3.4'], 'descr' => ''],
                ['kind' => 'rule', 'interface' => 'LAN', 'action' => 'pass', 'protocol' => 'any', 'source' => 'LANnet', 'destination' => 'any', 'descr' => 'Default allow LAN'],
                ['kind' => 'host_override', 'host' => 'nas', 'domain' => 'corp.local', 'ip' => '10.0.0.20'],
                ['kind' => 'rule', 'interface' => 'LAN', 'descr' => ''], // no description: can't be copied
            ]];
        };
        $r = $this->actingAs($w['global'])->getJson("/deployments/copy-from/{$w['hq']->id}")->assertOk();
        $this->assertSame(['Alias Office (host, 1 entries)', 'Pass rule on LAN: Default allow LAN', 'DNS host override nas.corp.local → 10.0.0.20'], array_column($r->json('items'), 'label'));
        $this->actingAs($w['companyAdmin'])->getJson("/deployments/copy-from/{$w['hq']->id}")->assertForbidden();
        $this->actingAs($w['global'])->getJson("/deployments/copy-from/{$w['opn']->id}")->assertStatus(422);
    }
}
