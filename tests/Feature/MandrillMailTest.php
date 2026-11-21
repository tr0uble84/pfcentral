<?php

namespace Tests\Feature;

use App\Mail\Transport\MandrillTransport;
use App\Models\SystemSetting;
use App\Models\User;
use App\Providers\MailConfigServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class MandrillMailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('x', 32))]);
        $this->withoutVite();
        Http::preventStrayRequests();
    }

    private function email(): Email
    {
        return (new Email())->from(new Address('noreply@cfbtel.com', 'CFBTEL'))->to('admin@example.com')->cc('ops@cfbtel.com')
            ->subject('Hello')->text('Plain body')->html('<p>HTML body</p>');
    }

    public function test_mandrill_transport_sends_the_raw_message_through_the_api(): void
    {
        Http::fake([MandrillTransport::ENDPOINT => Http::response([
            ['email' => 'admin@example.com', 'status' => 'sent', '_id' => 'abc123'],
            ['email' => 'ops@cfbtel.com', 'status' => 'queued', '_id' => 'abc124'],
        ])]);
        $sent = (new MandrillTransport('md-key'))->send($this->email());

        $this->assertSame('abc123', $sent->getMessageId());
        Http::assertSent(function ($req) {
            return $req->url() === MandrillTransport::ENDPOINT
                && $req['key'] === 'md-key'
                && $req['from_email'] === 'noreply@cfbtel.com'
                && $req['from_name'] === 'CFBTEL'
                && $req['to'] === ['admin@example.com', 'ops@cfbtel.com']
                && str_contains($req['raw_message'], 'Subject: Hello')
                && str_contains($req['raw_message'], 'HTML body');
        });
    }

    public function test_mandrill_errors_and_rejections_are_reported(): void
    {
        Http::fake([MandrillTransport::ENDPOINT => Http::sequence()
            ->push(['status' => 'error', 'code' => -1, 'name' => 'Invalid_Key', 'message' => 'Invalid API key'], 500)
            ->push([['email' => 'admin@example.com', 'status' => 'rejected', 'reject_reason' => 'unsigned'], ['email' => 'ops@cfbtel.com', 'status' => 'rejected', 'reject_reason' => 'unsigned']]),
        ]);
        foreach (['Invalid API key', 'admin@example.com (unsigned)'] as $expected) {
            try {
                (new MandrillTransport('bad'))->send($this->email());
                $this->fail('Expected an exception');
            } catch (TransportException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }
    }

    public function test_settings_save_mandrill_with_an_encrypted_key_and_mail_uses_it(): void
    {
        $admin = User::create(['name' => 'Jay', 'email' => 'admin@example.com', 'password' => 'password', 'role' => 'admin', 'company_id' => null]);
        $admin->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($admin)->post('/system/settings', [
            'theme' => 'light', 'mail_driver' => 'mandrill', 'mandrill_secret' => 'md-secret-key',
            'mail_from_address' => 'noreply@cfbtel.com', 'mail_from_name' => 'CFBTEL',
        ])->assertRedirect();
        $stored = SystemSetting::where('key', 'mandrill_secret')->value('value');
        $this->assertNotSame('md-secret-key', $stored);
        $this->assertSame('md-secret-key', Crypt::decryptString($stored));

        // The page never shows the saved key; a blank field keeps it.
        $this->actingAs($admin)->get('/system/settings')->assertOk()->assertSee('Mandrill')->assertDontSee('md-secret-key');
        $this->actingAs($admin)->post('/system/settings', ['theme' => 'light', 'mail_driver' => 'mandrill', 'mandrill_secret' => ''])->assertRedirect();
        $this->assertSame('md-secret-key', Crypt::decryptString(SystemSetting::where('key', 'mandrill_secret')->value('value')));

        // As on the next request: settings are read when the app boots.
        app()->forgetInstance('mail.manager');
        Mail::clearResolvedInstances();
        (new MailConfigServiceProvider($this->app))->boot();
        $this->assertSame('mandrill', config('mail.default'));
        Http::fake([MandrillTransport::ENDPOINT => Http::response([['email' => 'behi@cfbtel.com', 'status' => 'sent', '_id' => 'x1']])]);
        Mail::raw('Test', fn ($m) => $m->to('behi@cfbtel.com')->subject('Test'));
        Http::assertSent(fn ($req) => $req['key'] === 'md-secret-key' && $req['to'] === ['behi@cfbtel.com'] && $req['from_email'] === 'noreply@cfbtel.com');
    }
}
