<?php

namespace Tests\Feature;

use App\Jobs\WatchUpgradeJob;
use App\Models\Company;
use App\Models\Firewall;
use App\Models\FirmwareCheck;
use App\Models\FirmwareRollout;
use App\Models\FirmwareRolloutItem;
use App\Models\User;
use App\Services\Upgrades\Firmware;
use App\Services\Upgrades\FirmwareDriver;
use App\Services\Upgrades\PfSenseFirmwareDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** One firewall without the network. */
class FakeFirmware implements FirmwareDriver
{
    public string $version = '25.07.1-RELEASE';
    public ?string $offer = '25.11-RELEASE';
    public int $diskKb = 4 * 1024 * 1024;
    public int $backups = 40;
    public bool $apiUp = true;
    public bool $webUp = true;
    public bool $ssh = false;
    public bool $running = false;
    public int $boot = 1000;
    public array $calls = [];
    public string $log = '';

    public function facts(): array
    {
        $this->calls[] = 'facts';
        if (!$this->apiUp) {
            throw new \RuntimeException('API down');
        }
        return ['version' => $this->version, 'disk_free_kb' => $this->diskKb, 'backups' => $this->backups, 'zfs' => true, 'maker' => 'Protectli', 'product' => 'VP2420',
            'pf' => 'Enabled', 'running' => $this->running, 'boot' => $this->boot];
    }

    public function available(): array
    {
        return ['version' => $this->offer, 'up_to_date' => $this->offer === null, 'output' => ''];
    }

    public function backup(): string
    {
        $this->calls[] = 'backup';
        return '<?xml version="1.0"?><pfsense><system><hostname>fw</hostname></system></pfsense>';
    }

    public function start(): void
    {
        $this->calls[] = 'start';
        $this->running = true;
    }

    public function log(): string
    {
        return $this->log;
    }

    public function reachable(): bool
    {
        return $this->webUp;
    }

    public function canReinstallApi(): bool
    {
        return $this->ssh;
    }

    public function reinstallApi(): string
    {
        $this->calls[] = 'reinstall';
        $this->apiUp = true;
        return 'Installing pfSense-pkg-RESTAPI... done';
    }

    /** The upgrade finished and the firewall restarted on the new version. */
    public function comeBack(string $version): void
    {
        $this->version = $version;
        $this->running = false;
        $this->boot = 2000;
    }
}

class UpgradeCentreTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, FakeFirmware> */
    private array $fw = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Http::preventStrayRequests();
        Firmware::resolveDriversWith(fn (Firewall $f) => $this->fw[$f->id] ??= new FakeFirmware());
    }

    protected function tearDown(): void
    {
        Firmware::resolveDriversWith(null);
        parent::tearDown();
    }

    private function world(): array
    {
        $acme = Company::create(['name' => 'Acme']);
        $beta = Company::create(['name' => 'Beta']);
        $mk = function (string $name, string $email, string $role, ?int $company) {
            $u = User::create(['name' => $name, 'email' => $email, 'password' => 'password', 'role' => $role, 'company_id' => $company]);
            $u->forceFill(['email_verified_at' => now()])->save();
            return $u;
        };
        $fwMk = fn (string $name, Company $c, string $os = 'pfsense') => Firewall::create(['name' => $name, 'company_id' => $c->id, 'url' => 'https://' . strtolower(str_replace(' ', '', $name)) . '.test', 'os_type' => $os, 'auth_method' => 'basic', 'api_key' => 'u', 'api_secret' => 'p', 'netgate_id' => strtolower(str_replace(' ', '-', $name))]);
        return [
            'global' => $mk('Jay', 'admin@example.com', 'admin', null),
            'acmeAdmin' => $mk('Acme IT', 'it@acme.test', 'admin', $acme->id),
            'acmeRo' => $mk('Acme RO', 'ro@acme.test', 'readonly', $acme->id),
            'hq' => $fwMk('Acme HQ', $acme),
            'branch' => $fwMk('Acme Branch', $acme),
            'opn' => $fwMk('Acme OPN', $acme, 'opnsense'),
            'beta' => $fwMk('Beta Main', $beta),
        ];
    }

    public function test_checks_run_and_are_scoped_to_the_company(): void
    {
        $w = $this->world();
        $this->actingAs($w['acmeRo'])->get('/upgrades')->assertOk()->assertSee('Acme HQ')->assertDontSee('Beta Main');
        $this->actingAs($w['acmeRo'])->postJson('/upgrades/check', ['firewall_ids' => [$w['hq']->id]])->assertForbidden();

        $this->actingAs($w['acmeAdmin'])->postJson('/upgrades/check', ['firewall_ids' => [$w['hq']->id, $w['beta']->id, $w['opn']->id]])->assertOk();
        $c = FirmwareCheck::where('firewall_id', $w['hq']->id)->first();
        $this->assertSame('warn', $c->status); // no SSH: the REST API needs a console command afterwards;
        $this->assertSame('25.11-RELEASE', $c->available_version);
        $this->assertNull(FirmwareCheck::where('firewall_id', $w['beta']->id)->first()); // other company
        $this->assertNull(FirmwareCheck::where('firewall_id', $w['opn']->id)->first()); // OPNsense isn't checked here

        $this->fw[$w['hq']->id]->backups = 5000;
        $this->fw[$w['hq']->id]->diskKb = 100 * 1024;
        Firmware::check($w['hq']);
        $c->refresh();
        $this->assertSame('fail', $c->status);
        $this->assertSame(['disk', 'backups'], array_values(array_map(fn ($x) => $x['key'], array_filter($c->checks, fn ($x) => $x['level'] === 'fail'))));
        $this->actingAs($w['acmeAdmin'])->getJson('/upgrades/data')->assertOk()->assertJsonCount(3, 'firewalls');
    }

    public function test_waves_back_up_upgrade_and_wait_for_the_firewall(): void
    {
        $w = $this->world();
        $res = $this->actingAs($w['acmeAdmin'])->post('/upgrades/plan', ['firewall_ids' => [$w['hq']->id, $w['branch']->id, $w['opn']->id]]);
        $rollout = FirmwareRollout::firstOrFail();
        $res->assertRedirect("/upgrades/rollouts/{$rollout->id}")->assertSessionHas('warning');
        $this->assertSame([1, 2], $rollout->items()->orderBy('wave')->pluck('wave')->all());
        $hq = $rollout->items()->where('firewall_id', $w['hq']->id)->first();
        $branch = $rollout->items()->where('firewall_id', $w['branch']->id)->first();

        // Other companies can't see it; wave 2 waits for wave 1.
        $this->actingAs($w['global'])->get("/upgrades/rollouts/{$rollout->id}")->assertOk()->assertSee('Acme HQ');
        $this->actingAs($w['acmeAdmin'])->postJson("/upgrades/rollouts/{$rollout->id}/waves/2/start")->assertStatus(409);

        $this->actingAs($w['acmeAdmin'])->postJson("/upgrades/rollouts/{$rollout->id}/waves/1/start")->assertOk();
        $hq->refresh();
        $fake = $this->fw[$w['hq']->id];
        $this->assertSame(['facts', 'backup', 'start'], $fake->calls);
        $this->assertSame('upgrading', $hq->status);
        $this->assertSame('25.07.1-RELEASE', $hq->from_version);
        $this->assertNotNull($hq->backup_path);
        $this->assertStringNotContainsString('<pfsense>', Storage::disk('local')->get($hq->backup_path)); // stored encrypted
        $this->actingAs($w['acmeAdmin'])->get("/upgrades/rollouts/{$rollout->id}/items/{$hq->id}/backup")->assertOk()->assertSee('<hostname>fw</hostname>', false);
        $this->actingAs($w['acmeRo'])->get("/upgrades/rollouts/{$rollout->id}/items/{$hq->id}/backup")->assertForbidden();

        // Still upgrading, then restarting (no answer at all), then back on the new version.
        (new WatchUpgradeJob($hq->id))->handle();
        $this->assertSame('upgrading', $hq->fresh()->status);
        $fake->apiUp = false;
        $fake->webUp = false;
        (new WatchUpgradeJob($hq->id))->handle();
        $this->assertSame('upgrading', $hq->fresh()->status);
        $fake->apiUp = true;
        $fake->webUp = true;
        $fake->comeBack('25.11-RELEASE');
        (new WatchUpgradeJob($hq->id))->handle();
        $hq->refresh();
        $this->assertSame('done', $hq->status);
        $this->assertSame('25.11-RELEASE', $hq->to_version);

        $this->actingAs($w['acmeAdmin'])->postJson("/upgrades/rollouts/{$rollout->id}/waves/2/start")->assertOk();
        $this->assertSame('upgrading', $branch->fresh()->status);
        $this->fw[$w['branch']->id]->comeBack('25.11-RELEASE');
        (new WatchUpgradeJob($branch->id))->handle();
        $this->assertSame('completed', $rollout->fresh()->status);
    }

    public function test_failed_checks_stop_a_firewall_and_it_can_be_retried_or_skipped(): void
    {
        $w = $this->world();
        $this->actingAs($w['acmeAdmin'])->post('/upgrades/plan', ['firewall_ids' => [$w['hq']->id, $w['branch']->id]]);
        $rollout = FirmwareRollout::firstOrFail();
        $hq = $rollout->items()->where('firewall_id', $w['hq']->id)->first();
        $this->fw[$w['hq']->id] = new FakeFirmware();
        $this->fw[$w['hq']->id]->diskKb = 100 * 1024;

        $this->actingAs($w['acmeAdmin'])->postJson("/upgrades/rollouts/{$rollout->id}/waves/1/start")->assertOk();
        $hq->refresh();
        $this->assertSame('failed', $hq->status);
        $this->assertStringContainsString('Free disk space', $hq->message);
        $this->assertNotContains('start', $this->fw[$w['hq']->id]->calls);
        $this->assertSame('attention', $rollout->fresh()->status);
        $this->actingAs($w['acmeAdmin'])->postJson("/upgrades/rollouts/{$rollout->id}/waves/2/start")->assertStatus(409);

        $this->fw[$w['hq']->id]->diskKb = 4 * 1024 * 1024;
        $this->actingAs($w['acmeAdmin'])->postJson("/upgrades/rollouts/{$rollout->id}/items/{$hq->id}/retry")->assertOk();
        $this->assertSame('upgrading', $hq->fresh()->status);

        // Skipping the branch firewall's wave item before it starts; then stop.
        $branch = $rollout->items()->where('firewall_id', $w['branch']->id)->first();
        $this->actingAs($w['acmeAdmin'])->postJson("/upgrades/rollouts/{$rollout->id}/items/{$branch->id}/skip")->assertOk();
        $this->assertSame('skipped', $branch->fresh()->status);
        $this->actingAs($w['acmeAdmin'])->postJson("/upgrades/rollouts/{$rollout->id}/stop")->assertOk();
        $this->assertSame('stopped', $rollout->fresh()->status);
    }

    public function test_the_rest_api_package_is_reinstalled_or_asked_for_after_the_upgrade(): void
    {
        $w = $this->world();
        $this->actingAs($w['acmeAdmin'])->post('/upgrades/plan', ['firewall_ids' => [$w['hq']->id, $w['branch']->id]]);
        $rollout = FirmwareRollout::firstOrFail();
        $rollout->items()->update(['wave' => 1]);
        $this->fw[$w['hq']->id] = new FakeFirmware();
        $this->fw[$w['branch']->id] = new FakeFirmware();
        $this->fw[$w['branch']->id]->ssh = true;
        $this->actingAs($w['acmeAdmin'])->postJson("/upgrades/rollouts/{$rollout->id}/waves/1/start")->assertOk();

        foreach ([$w['hq']->id, $w['branch']->id] as $id) {
            $this->fw[$id]->comeBack('25.11-RELEASE');
            $this->fw[$id]->apiUp = false; // the upgrade removed the REST API package; the web page answers
        }
        $hq = $rollout->items()->where('firewall_id', $w['hq']->id)->first();
        $branch = $rollout->items()->where('firewall_id', $w['branch']->id)->first();
        for ($n = 0; $n < Firmware::API_MISSES; $n++) {
            (new WatchUpgradeJob($hq->id))->handle();
            (new WatchUpgradeJob($branch->id))->handle();
        }
        // Without SSH: one console command, then "Check again".
        $hq->refresh();
        $this->assertSame('needs_api', $hq->status);
        $this->assertStringContainsString(PfSenseFirmwareDriver::API_REINSTALL, $hq->message);
        // With SSH: reinstalled by PFCentral, then confirmed on the next look.
        $this->assertContains('reinstall', $this->fw[$w['branch']->id]->calls);
        (new WatchUpgradeJob($branch->id))->handle();
        $this->assertSame('done', $branch->fresh()->status);

        $this->fw[$w['hq']->id]->apiUp = true;
        $this->actingAs($w['acmeAdmin'])->postJson("/upgrades/rollouts/{$rollout->id}/items/{$hq->id}/retry")->assertOk();
        $this->assertSame('done', $hq->fresh()->status);
        $this->assertSame('completed', $rollout->fresh()->status);
    }

    public function test_a_firewall_that_does_not_come_back_fails_at_the_deadline(): void
    {
        $w = $this->world();
        $this->actingAs($w['acmeAdmin'])->post('/upgrades/plan', ['firewall_ids' => [$w['hq']->id]]);
        $rollout = FirmwareRollout::firstOrFail();
        $this->actingAs($w['acmeAdmin'])->postJson("/upgrades/rollouts/{$rollout->id}/waves/1/start")->assertOk();
        $item = $rollout->items()->first();
        $this->fw[$w['hq']->id]->apiUp = false;
        $this->fw[$w['hq']->id]->webUp = false;
        $item->update(['started_at' => now()->subMinutes(Firmware::DEADLINE_MINUTES + 1)]);
        (new WatchUpgradeJob($item->id))->handle();
        $item->refresh();
        $this->assertSame('failed', $item->status);
        $this->assertStringContainsString('didn\'t come back', $item->message);
    }

    public function test_the_old_bulk_system_update_goes_through_the_upgrade_centre(): void
    {
        $w = $this->world();
        $this->actingAs($w['acmeAdmin'])->post('/firewalls/bulk/action', ['firewall_ids' => [$w['hq']->id], 'action' => 'update'])
            ->assertRedirect('/upgrades/rollouts/' . FirmwareRollout::firstOrFail()->id);
        $this->assertSame('pending', FirmwareRolloutItem::firstOrFail()->status);
        $this->assertSame([], $this->fw[$w['hq']->id]->calls ?? []);
    }

    public function test_pfsense_output_parsers(): void
    {
        $f = PfSenseFirmwareDriver::parseFacts("version=24.11-RELEASE\npatch=1\ndisk_free_kb=5242880\nbackups=12\nzfs=pfSense\nmaker=Netgate\nproduct=4200\npf=Enabled\nrunning=0\nboot=1795200000\n");
        $this->assertSame('24.11-RELEASE (patch 1)', $f['version']);
        $this->assertTrue($f['zfs']);
        $this->assertFalse($f['running']);
        $this->assertSame(5242880, $f['disk_free_kb']);
        $a = PfSenseFirmwareDriver::parseAvailable(">>> Updating repositories metadata... done.\n25.11-RELEASE version of pfSense Plus is available");
        $this->assertSame('25.11-RELEASE', $a['version']);
        $this->assertTrue(PfSenseFirmwareDriver::parseAvailable("Your system is up to date")['up_to_date']);
        $this->expectException(\RuntimeException::class);
        PfSenseFirmwareDriver::parseFacts('nothing');
    }
}
