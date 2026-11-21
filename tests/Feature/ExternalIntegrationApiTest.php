<?php

namespace Tests\Feature;

use App\Models\{Company, Firewall, IntegrationAccessToken, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, File};
use Tests\TestCase;

class ExternalIntegrationApiTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Company $otherCompany;
    private User $user;
    private Firewall $firewall;
    private Firewall $otherFirewall;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('x', 32)), 'app.debug' => false]);
        $this->withoutVite();
        $this->company = Company::create(['name' => 'Tenant A']);
        $this->otherCompany = Company::create(['name' => 'Tenant B']);
        User::factory()->create(['company_id' => $this->otherCompany->id, 'role' => 'readonly']);
        $this->user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'readonly']);
        $this->firewall = Firewall::create([
            'name' => 'A firewall', 'company_id' => $this->company->id, 'netgate_id' => 'tenant-a-fw',
            'os_type' => 'pfsense', 'url' => 'https://private.example.test',
            'api_key' => 'secret-key-probe', 'api_secret' => 'secret-secret-probe',
            'api_token' => 'secret-token-probe', 'ssh_password' => 'secret-password-probe',
            'ssh_username' => 'secret-user-probe', 'tls_public_key_pin' => 'private-pin-probe',
        ]);
        $this->otherFirewall = Firewall::create([
            'name' => 'B firewall', 'company_id' => $this->otherCompany->id,
            'netgate_id' => 'tenant-b-fw', 'url' => 'https://other.example.test',
        ]);
        $this->token = $this->issue($this->user, $this->company);
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        parent::tearDown();
    }

    private function issue(User $user, Company $company, array $abilities = ['inventory:read']): string
    {
        $issued = $user->createToken('API test', $abilities, now()->addDays(1));
        $issued->accessToken->forceFill(['company_id' => $company->id])->save();
        return $issued->plainTextToken;
    }

    private function api(string $path, ?string $token = null)
    {
        $this->app['auth']->forgetGuards();
        return $this->get('https://localhost/api/v1/'.$path,
            ['Authorization' => 'Bearer '.($token ?? $this->token)]);
    }

    public function test_requires_bearer_even_without_json_accept_header(): void
    {
        $this->get('https://localhost/api/v1/firewalls')->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json');
        $this->api('firewalls', 'invalid')->assertUnauthorized();
        $this->api('firewalls', $this->token.'tampered')->assertUnauthorized();
    }

    public function test_browser_session_is_not_api_authentication(): void
    {
        $this->actingAs($this->user)->get('https://localhost/api/v1/firewalls')->assertUnauthorized();
    }

    public function test_plain_http_is_rejected_without_redirecting_token(): void
    {
        $this->get('http://localhost/api/v1/firewalls', ['Authorization' => 'Bearer '.$this->token])
            ->assertForbidden()->assertHeaderMissing('Location');
    }

    public function test_company_lists_and_me_are_scoped(): void
    {
        $this->api('companies')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->company->id);
        $this->api('companies/'.$this->company->id)->assertOk();
        $this->api('companies/'.$this->otherCompany->id)->assertNotFound();
        $this->api('me')->assertOk()->assertJsonPath('data.company_id', $this->company->id)
            ->assertJsonMissingPath('data.email')->assertJsonMissingPath('data.password');
    }

    public function test_firewall_queries_are_scoped_and_have_an_explicit_field_allowlist(): void
    {
        $this->api('firewalls?company_id='.$this->otherCompany->id)->assertForbidden();
        $response = $this->api('firewalls')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->firewall->id)
            ->assertHeader('Cache-Control', 'no-store, private');
        $expected = ['id', 'company_id', 'name', 'os_type', 'netgate_id', 'description', 'created_at', 'updated_at'];
        $this->assertSame($expected, array_keys($response->json('data.0')));
        $detail = $this->api('firewalls/'.$this->firewall->id)->assertOk();
        $this->assertSame($expected, array_keys($detail->json('data')));
        foreach (['secret-key-probe', 'secret-secret-probe', 'secret-token-probe', 'secret-password-probe',
            'secret-user-probe', 'private-pin-probe', 'private.example.test'] as $secret) {
            $response->assertDontSee($secret);
            $detail->assertDontSee($secret);
        }
        $this->api('firewalls/'.$this->otherFirewall->id)->assertNotFound();
        $this->api('firewalls/999999')->assertNotFound();
        $this->api('firewalls/tenant-a-fw')->assertNotFound();
    }

    public function test_global_admin_token_is_still_pinned_to_one_company(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'company_id' => null]);
        $token = $this->issue($admin, $this->company);
        $this->api('companies', $token)->assertOk()->assertJsonCount(1, 'data');
        $this->api('firewalls/'.$this->otherFirewall->id, $token)->assertNotFound();
    }

    public function test_explicit_msp_token_reads_all_companies_and_supports_company_filter(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'company_id' => null]);
        $issued = $admin->createToken('MSP test', ['inventory:read', 'inventory:read:all'], now()->addDay());
        $token = $issued->plainTextToken;
        $this->api('me', $token)->assertOk()->assertJsonPath('data.all_companies', true)->assertJsonPath('data.company_id', null);
        $this->api('companies', $token)->assertOk()->assertJsonCount(2, 'data');
        $this->api('companies/'.$this->otherCompany->id, $token)->assertOk();
        $this->api('firewalls', $token)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2);
        $this->api('firewalls/'.$this->otherFirewall->id, $token)->assertOk();
        $this->api('firewalls?company_id='.$this->otherCompany->id, $token)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.company_id', $this->otherCompany->id);
        $this->api('firewalls?company_id=99999', $token)->assertOk()->assertJsonCount(0, 'data');
        $this->api('firewalls?page=1&per_page=1', $token)->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_company_user_cannot_use_or_issue_an_all_company_token(): void
    {
        $issued = $this->user->createToken('Unauthorized MSP', ['inventory:read', 'inventory:read:all'], now()->addDay());
        $this->api('companies', $issued->plainTextToken)->assertForbidden();
        $this->artisan('integration:token:issue', ['--user' => $this->user->id, '--all-companies' => true,
            '--name' => 'Denied MSP'])->assertFailed();
        $admin = User::factory()->create(['role' => 'admin', 'company_id' => $this->company->id]);
        $this->artisan('integration:token:issue', ['--user' => $admin->id, '--all-companies' => true,
            '--name' => 'Denied company admin'])->assertFailed();
    }

    public function test_msp_token_stops_working_when_owner_loses_global_admin_role(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'company_id' => null]);
        $issued = $admin->createToken('MSP test', ['inventory:read', 'inventory:read:all'], now()->addDay());
        $this->api('companies', $issued->plainTextToken)->assertOk();
        $admin->update(['company_id' => $this->company->id]);
        $this->api('companies', $issued->plainTextToken)->assertForbidden();
        $admin->update(['company_id' => null, 'role' => 'readonly']);
        $this->api('companies', $issued->plainTextToken)->assertForbidden();
    }

    public function test_cli_issues_msp_token_only_for_global_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'company_id' => null]);
        $directory = storage_path('app/private/integration-tokens');
        $existing = File::glob($directory.'/*.json');
        try {
            $this->artisan('integration:token:issue', ['--user' => $admin->id, '--all-companies' => true,
                '--name' => 'MSP CLI'])->assertSuccessful();
            $newFiles = array_values(array_diff(File::glob($directory.'/*.json'), $existing));
            $this->assertCount(1, $newFiles);
            $saved = json_decode(file_get_contents($newFiles[0]), true);
            $this->assertTrue($saved['all_companies']);
            $this->assertNull($saved['company_id']);
            $this->api('companies', $saved['token'])->assertOk()->assertJsonCount(2, 'data');
            $this->artisan('integration:token:issue', ['--user' => $admin->id, '--company' => $this->company->id,
                '--all-companies' => true, '--name' => 'Ambiguous'])->assertFailed();
        } finally {
            foreach (array_diff(File::glob($directory.'/*.json'), $existing) as $path) {
                File::delete($path);
            }
        }
    }

    public function test_expired_revoked_and_unapproved_nonexpiring_tokens_are_rejected(): void
    {
        $record = IntegrationAccessToken::findToken($this->token);
        $record->update(['expires_at' => now()->subMinute()]);
        $this->api('firewalls')->assertUnauthorized();
        $record->update(['expires_at' => null]);
        $this->api('firewalls')->assertUnauthorized();
        $record->delete();
        $this->api('firewalls')->assertUnauthorized();
    }

    public function test_explicit_nonexpiring_msp_token_survives_time_and_remains_revocable(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'company_id' => null]);
        $issued = $admin->createToken('Permanent MSP', ['inventory:read', 'inventory:read:all', 'inventory:never-expires']);
        $this->assertNull($issued->accessToken->expires_at);
        $this->api('companies', $issued->plainTextToken)->assertOk()->assertJsonCount(2, 'data');
        try {
            $this->travel(180)->days();
            $this->api('companies', $issued->plainTextToken)->assertOk();
        } finally {
            $this->travelBack();
        }
        $issued->accessToken->delete();
        $this->api('companies', $issued->plainTextToken)->assertUnauthorized();
    }

    public function test_nonexpiring_tokens_require_explicit_ability_and_current_global_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'company_id' => null]);
        $unapproved = $admin->createToken('Unapproved permanent', ['inventory:read', 'inventory:read:all']);
        $this->api('companies', $unapproved->plainTextToken)->assertUnauthorized();
        $issued = $admin->createToken('Permanent MSP', ['inventory:read', 'inventory:read:all', 'inventory:never-expires']);
        $admin->update(['company_id' => $this->company->id]);
        $this->api('companies', $issued->plainTextToken)->assertUnauthorized();
        $companyToken = $this->user->createToken('Unauthorized permanent', ['inventory:read', 'inventory:never-expires']);
        $companyToken->accessToken->forceFill(['company_id' => $this->company->id])->save();
        $this->api('companies', $companyToken->plainTextToken)->assertUnauthorized();
    }

    public function test_cli_issues_nonexpiring_msp_token_privately(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'company_id' => null]);
        $directory = storage_path('app/private/integration-tokens');
        $existing = File::glob($directory.'/*.json');
        try {
            $this->artisan('integration:token:issue', ['--user' => $admin->id, '--all-companies' => true,
                '--never-expires' => true, '--name' => 'Permanent MSP CLI'])->assertSuccessful();
            $newFiles = array_values(array_diff(File::glob($directory.'/*.json'), $existing));
            $this->assertCount(1, $newFiles);
            $this->assertSame(0600, fileperms($newFiles[0]) & 0777);
            $saved = json_decode(file_get_contents($newFiles[0]), true);
            $this->assertTrue($saved['never_expires']);
            $this->assertNull($saved['expires_at']);
            $this->assertTrue($saved['all_companies']);
            $this->api('companies', $saved['token'])->assertOk()->assertJsonCount(2, 'data');
        } finally {
            foreach (array_diff(File::glob($directory.'/*.json'), $existing) as $path) {
                File::delete($path);
            }
        }
    }

    public function test_cli_rejects_nonexpiring_for_non_global_admin_and_ambiguous_expiration(): void
    {
        $this->artisan('integration:token:issue', ['--user' => $this->user->id, '--company' => $this->company->id,
            '--never-expires' => true, '--name' => 'Denied permanent'])->assertFailed();
        $admin = User::factory()->create(['role' => 'admin', 'company_id' => null]);
        $this->artisan('integration:token:issue', ['--user' => $admin->id, '--all-companies' => true,
            '--never-expires' => true, '--days' => 30, '--name' => 'Ambiguous permanent'])->assertFailed();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_missing_ability_wildcard_only_and_unscoped_tokens_are_rejected(): void
    {
        $this->api('firewalls', $this->issue($this->user, $this->company, []))->assertForbidden();
        $this->api('firewalls', $this->issue($this->user, $this->company, ['*']))->assertForbidden();
        IntegrationAccessToken::findToken($this->token)->update(['company_id' => null]);
        $this->api('firewalls')->assertForbidden();
    }

    public function test_owner_reassignment_and_role_removal_disable_existing_token(): void
    {
        $this->user->update(['company_id' => $this->otherCompany->id]);
        $this->api('firewalls')->assertForbidden();
        $this->user->update(['company_id' => $this->company->id, 'role' => 'disabled']);
        $this->api('firewalls')->assertForbidden();
    }

    public function test_orphaned_owner_token_is_rejected(): void
    {
        $this->user->delete();
        $this->api('firewalls')->assertUnauthorized();
    }

    public function test_deleted_company_revokes_its_tokens(): void
    {
        $this->company->delete();
        $this->api('firewalls')->assertUnauthorized();
    }

    public function test_unconfigured_application_returns_json_without_setup_redirect(): void
    {
        User::query()->delete();
        $this->api('firewalls')->assertStatus(503)->assertHeaderMissing('Location')
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_pagination_is_bounded_and_stable(): void
    {
        Firewall::create(['name' => 'Second A firewall', 'company_id' => $this->company->id, 'url' => 'https://another.example.test']);
        $this->api('firewalls?per_page=1&page=1')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2);
        $this->api('firewalls?per_page=101')->assertUnprocessable();
        $this->api('firewalls?page=0')->assertUnprocessable();
        $this->api('firewalls?page[]=1')->assertUnprocessable();
    }

    public function test_write_methods_are_unavailable_and_data_is_unchanged(): void
    {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->call($method, 'https://localhost/api/v1/firewalls/'.$this->firewall->id,
                ['name' => 'modified'], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token])
                ->assertStatus(405)->assertHeader('Content-Type', 'application/json');
        }
        $this->assertSame('A firewall', $this->firewall->fresh()->name);
    }

    public function test_token_and_pre_authentication_rate_limits(): void
    {
        config(['integration-api.requests_per_minute' => 2]);
        $this->api('me')->assertOk();
        $this->api('me')->assertOk();
        $this->api('me')->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_invalid_tokens_are_rate_limited(): void
    {
        config(['integration-api.unauthenticated_requests_per_minute' => 2]);
        $this->api('me', 'invalid')->assertUnauthorized();
        $this->api('me', 'invalid')->assertUnauthorized();
        $this->api('me', 'invalid')->assertStatus(429);
    }

    public function test_openapi_is_authenticated_and_describes_only_get_operations(): void
    {
        $this->get('https://localhost/api/v1/openapi.json')->assertUnauthorized();
        $response = $this->api('openapi.json')->assertOk()->assertJsonPath('openapi', '3.0.3');
        $this->assertCount(8, $response->json('paths'));
        foreach ($response->json('paths') as $path) {
            $this->assertSame(['get'], array_keys($path));
        }
    }

    public function test_cli_refuses_cross_company_and_invalid_lifetime(): void
    {
        $this->artisan('integration:token:issue', ['--user' => $this->user->id,
            '--company' => $this->otherCompany->id, '--name' => 'Denied'])->assertFailed();
        $this->artisan('integration:token:issue', ['--user' => $this->user->id,
            '--company' => $this->company->id, '--name' => 'Denied', '--days' => 91])->assertFailed();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_cli_issues_private_scoped_token_and_revokes_it(): void
    {
        $directory = storage_path('app/private/integration-tokens');
        $existing = File::glob($directory.'/*.json');
        try {
            $this->artisan('integration:token:issue', ['--user' => $this->user->id,
                '--company' => $this->company->id, '--name' => 'CLI test', '--days' => 7])->assertSuccessful();
            $newFiles = array_values(array_diff(File::glob($directory.'/*.json'), $existing));
            $this->assertCount(1, $newFiles);
            $this->assertSame(0600, fileperms($newFiles[0]) & 0777);
            $this->assertSame(0700, fileperms($directory) & 0777);
            $saved = json_decode(file_get_contents($newFiles[0]), true);
            $this->api('me', $saved['token'])->assertOk();
            $this->artisan('integration:token:revoke', ['id' => $saved['token_id']])->assertSuccessful();
            $this->api('me', $saved['token'])->assertUnauthorized();
        } finally {
            foreach (array_diff(File::glob($directory.'/*.json'), $existing) as $path) {
                File::delete($path);
            }
        }
    }
}
