<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SystemConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Saving System Settings must not drop a custom port from APP_URL (e.g. https://host:8443 behind a NAT). */
class SystemSettingsPortTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_settings_with_the_same_host_keeps_the_port_and_the_env_file(): void
    {
        config(['app.key' => 'base64:' . base64_encode(str_repeat('x', 32)), 'app.url' => 'https://pfcentral.test:8443']);
        $this->withoutVite();
        $config = $this->mock(SystemConfigurationService::class);
        $config->shouldNotReceive('updateSystemHostname');
        $config->shouldNotReceive('updateEnv');

        $admin = User::create(['name' => 'Jay', 'email' => 'admin@example.com', 'password' => 'password', 'role' => 'admin', 'company_id' => null]);
        $admin->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($admin)->post('/system/settings', ['theme' => 'light', 'site_url' => 'pfcentral.test', 'site_protocol' => 'https'])
            ->assertRedirect(route('system.settings.index'));
    }

    public function test_a_scheme_change_on_the_same_host_keeps_the_custom_port(): void
    {
        config(['app.url' => 'http://pfcentral.test:8443']);
        $env = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($env, "APP_URL=http://pfcentral.test:8443\n");
        $svc = new class($env) extends SystemConfigurationService {
            public array $written = [];
            public function __construct(private string $file) {}
            public function updateEnv(array $data): bool { $this->written += $data; return true; }
            public function appendEnv(array $data): bool { return true; }
            public function updateNginxHostname(string $hostname): bool { return true; }
        };
        $svc->updateSystemHostname('pfcentral.test', 'https');
        $this->assertSame('https://pfcentral.test:8443', $svc->written['APP_URL']);
        $svc->written = [];
        $svc->updateSystemHostname('other.test', 'https');
        $this->assertSame('https://other.test', $svc->written['APP_URL']);
    }
}
