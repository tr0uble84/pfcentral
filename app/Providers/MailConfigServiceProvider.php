<?php

namespace App\Providers;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class MailConfigServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Mandrill (Mailchimp Transactional) over its HTTPS API; the key is stored encrypted.
        \Illuminate\Support\Facades\Mail::extend('mandrill', fn (array $config) => new \App\Mail\Transport\MandrillTransport((string) ($config['key'] ?? '')));

        // Only attempt to load settings if the table exists (prevents migration errors)
        if (!Schema::hasTable('system_settings')) {
            return;
        }

        try {
            $settings = SystemSetting::whereIn('key', [
                'mail_driver',
                'mailgun_domain',
                'mailgun_secret',
                'mailgun_endpoint',
                'mandrill_secret',
                'mail_from_address',
                'mail_from_name'
            ])->pluck('value', 'key');

            if ($settings->get('mail_driver') === 'mailgun') {
                // Set Default Mailer
                Config::set('mail.default', 'mailgun');

                // Configure Mailgun
                if ($settings->has('mailgun_domain') && $settings->has('mailgun_secret')) {
                    Config::set('services.mailgun.domain', $settings->get('mailgun_domain'));
                    Config::set('services.mailgun.secret', $settings->get('mailgun_secret'));
                    // Ensure the endpoint is set (default is US)
                    Config::set('services.mailgun.endpoint', $settings->get('mailgun_endpoint', 'api.mailgun.net'));
                }
            }

            if ($settings->get('mail_driver') === 'mandrill' && $settings->get('mandrill_secret')) {
                try {
                    $key = \Illuminate\Support\Facades\Crypt::decryptString($settings->get('mandrill_secret'));
                } catch (\Throwable) {
                    $key = null; // app key changed: enter the API key again
                }
                if ($key) {
                    Config::set('mail.mailers.mandrill', ['transport' => 'mandrill', 'key' => $key]);
                    Config::set('mail.default', 'mandrill');
                }
            }

            // Configure From Address
            if ($settings->has('mail_from_address')) {
                Config::set('mail.from.address', $settings->get('mail_from_address'));
            }

            if ($settings->has('mail_from_name')) {
                Config::set('mail.from.name', $settings->get('mail_from_name'));
            }

        } catch (\Exception $e) {
            // Failsafe: If DB connection fails or table doesn't exist yet, do nothing and let default config take over.
        }
    }
}
