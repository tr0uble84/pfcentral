<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\Health\ServerHealth;
use App\Support\RedisCacheSwitch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class ServerHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store('file')->forget('pfc:health:banner');
    }

    private function user(string $email, ?int $company): User
    {
        $u = User::create(['name' => 'U', 'email' => $email, 'password' => 'password', 'role' => 'admin', 'company_id' => $company]);
        $u->forceFill(['email_verified_at' => now()])->save();

        return $u;
    }

    public function test_global_admin_sees_the_page_and_others_do_not(): void
    {
        $acme = Company::create(['name' => 'Acme']);
        $global = $this->user('jay@example.test', null);
        $companyAdmin = $this->user('it@acme.test', $acme->id);

        $this->actingAs($global)->get('/system/health')->assertOk()->assertSee('Server health')->assertSee('Disk space');
        $this->actingAs($global)->getJson('/system/health/data')->assertOk()->assertJsonStructure(['checks' => [['key', 'label', 'status', 'value', 'detail']], 'overall']);

        $this->actingAs($companyAdmin)->get('/system/health')->assertForbidden();
        $this->actingAs($companyAdmin)->postJson('/system/health/maintenance')->assertForbidden();
        $this->actingAs($companyAdmin)->postJson('/system/health/cleanup')->assertForbidden();
    }

    public function test_every_check_has_a_known_status_and_never_throws(): void
    {
        $checks = ServerHealth::checks();
        $this->assertCount(11, $checks);
        foreach ($checks as $c) {
            $this->assertContains($c['status'], ['ok', 'warn', 'bad', 'unknown'], $c['key']);
        }
        $this->assertContains(ServerHealth::overall($checks), ['ok', 'warn', 'bad', 'unknown']);
    }

    public function test_cache_in_mysql_is_flagged(): void
    {
        config(['cache.default' => 'database']);
        $this->assertSame('warn', ServerHealth::cacheStore()['status']);
        config(['cache.default' => 'redis']);
        $this->assertSame('ok', ServerHealth::cacheStore()['status']);
    }

    public function test_scheduler_heartbeat_is_read(): void
    {
        $file = storage_path(ServerHealth::SCHEDULER_FILE);
        @unlink($file);
        $this->assertSame('warn', ServerHealth::scheduler()['status']);
        ServerHealth::beatScheduler();
        $this->assertSame('ok', ServerHealth::scheduler()['status']);
        touch($file, time() - 3600);
        $this->assertSame('bad', ServerHealth::scheduler()['status']);
        ServerHealth::beatScheduler();
    }

    public function test_cleanup_removes_only_expired_rows(): void
    {
        DB::table('cache')->insert([
            ['key' => 'old', 'value' => 'x', 'expiration' => time() - 10],
            ['key' => 'live', 'value' => 'x', 'expiration' => time() + 600],
        ]);
        DB::table('failed_jobs')->insert([
            ['uuid' => 'a', 'connection' => 'db', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()->subDays(40)],
            ['uuid' => 'b', 'connection' => 'db', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()->subDay()],
        ]);

        $this->artisan('pfcentral:cleanup', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(2, DB::table('cache')->count());

        $this->artisan('pfcentral:cleanup')->assertSuccessful();
        $this->assertSame(['live'], DB::table('cache')->pluck('key')->all());
        $this->assertSame(['b'], DB::table('failed_jobs')->pluck('uuid')->all());
    }

    public function test_cache_switch_edits_env_only_when_redis_works(): void
    {
        $dir = sys_get_temp_dir() . '/pfc-env-' . uniqid();
        mkdir($dir);
        file_put_contents("$dir/.env", "APP_NAME=PFCentral\nCACHE_STORE=database\nCACHE_DRIVER=database\n");
        $this->app->useEnvironmentPath($dir);

        // Redis not usable: nothing changes.
        Redis::shouldReceive('connection')->andThrow(new \RuntimeException('Connection refused'));
        $this->assertStringStartsWith('skipped', RedisCacheSwitch::apply());
        $this->assertStringContainsString('CACHE_STORE=database', file_get_contents("$dir/.env"));

        // Redis works: both keys move to redis, and the old cache rows are freed.
        DB::table('cache')->insert(['key' => 'k', 'value' => 'v', 'expiration' => time() + 60]);
        $conn = \Mockery::mock();
        $conn->shouldReceive('setex')->andReturn(true);
        $conn->shouldReceive('get')->andReturn('1');
        $conn->shouldReceive('del')->andReturn(1);
        Redis::swap(\Mockery::mock()->shouldReceive('connection')->andReturn($conn)->getMock());
        if (! class_exists(\Redis::class) && ! class_exists(\Predis\Client::class)) {
            $this->markTestSkipped('No Redis client for PHP installed here.');
        }
        $this->assertSame('moved the cache from MySQL to Redis', RedisCacheSwitch::apply());
        $env = file_get_contents("$dir/.env");
        $this->assertStringContainsString("CACHE_STORE=redis\n", $env);
        $this->assertStringContainsString("CACHE_DRIVER=redis\n", $env);
        $this->assertSame(0, DB::table('cache')->count());
        $this->assertSame('redis', env('CACHE_STORE'));

        // Running again changes nothing.
        $this->assertSame('skipped: cache already uses redis', RedisCacheSwitch::apply());

        putenv('CACHE_STORE=array');
        $_ENV['CACHE_STORE'] = $_SERVER['CACHE_STORE'] = 'array';
        @unlink("$dir/.env");
        @rmdir($dir);
    }

    public function test_banner_lists_disk_problems_for_global_admins_only(): void
    {
        $global = $this->user('jay@example.test', null);
        $acme = Company::create(['name' => 'Acme']);
        $companyAdmin = $this->user('it@acme.test', $acme->id);
        Cache::store('file')->put('pfc:health:banner', ['The server disk is 97% full. PFCentral stops working when it reaches 100%.'], 60);

        $this->actingAs($global)->get('/system/health')->assertSee('The server disk is 97% full');
        $this->actingAs($companyAdmin)->get('/dashboard')->assertDontSee('The server disk is 97% full');
        Cache::store('file')->forget('pfc:health:banner');
    }
}
