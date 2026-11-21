<?php

namespace App\Services\Vpn;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * VPN module settings, kept in system_settings (keys "vpn_*") so they're set on a settings page,
 * not in the server's .env. The Microsoft client secret is encrypted with the app key.
 */
class VpnSettings
{
    private const KEYS = [
        'ms_client_id', 'ms_client_secret', 'portal_host', 'max_devices', 'proxy_ips',
        'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password', 'mail_from_address', 'mail_from_name', 'support_email',
        'invite_subject', 'invite_body', 'setup_client_id', 'setup_client_secret',
    ];
    private const SECRETS = ['ms_client_secret', 'smtp_password', 'setup_client_secret'];

    public const DEFAULT_INVITE_SUBJECT = 'Your {company} VPN access';
    public const DEFAULT_INVITE_BODY = "Hello {name},\n\n{company} has given you VPN access.\n\nSet your password here (the link works until {expires}):\n{link}\n\nThen sign in at {portal} with this email address and add your computer or phone.\n\nIf you didn't expect this email, you can ignore it.\n\nCFBTEL Support";

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = Cache::remember("vpn_setting:{$key}", 60, fn () => SystemSetting::where('key', "vpn_{$key}")->value('value') ?? '');
        return $value === '' ? $default : $value;
    }

    public static function set(string $key, ?string $value): void
    {
        if (!in_array($key, self::KEYS, true)) {
            throw new \InvalidArgumentException("Unknown VPN setting {$key}");
        }
        if (in_array($key, self::SECRETS, true) && $value !== null && $value !== '') {
            $value = Crypt::encryptString($value);
        }
        SystemSetting::updateOrCreate(['key' => "vpn_{$key}"], ['value' => $value ?? '']);
        Cache::forget("vpn_setting:{$key}");
    }

    public static function clientId(): ?string
    {
        return self::get('ms_client_id');
    }

    public static function clientSecret(): ?string
    {
        $sealed = self::get('ms_client_secret');
        if (!$sealed) {
            return null;
        }
        try {
            return Crypt::decryptString($sealed);
        } catch (\Throwable) {
            return null; // app key changed: the secret has to be entered again
        }
    }

    /** Decrypted value of an encrypted setting, or null. */
    public static function secret(string $key): ?string
    {
        $sealed = self::get($key);
        if (!$sealed) {
            return null;
        }
        try {
            return Crypt::decryptString($sealed);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function inviteSubject(): string
    {
        return self::get('invite_subject') ?: self::DEFAULT_INVITE_SUBJECT;
    }

    public static function inviteBody(): string
    {
        return self::get('invite_body') ?: self::DEFAULT_INVITE_BODY;
    }

    public static function hasClientSecret(): bool
    {
        return self::clientSecret() !== null;
    }

    /** Public host name of the staff page, e.g. vpn.example.com (no scheme, no path). */
    public static function portalHost(): ?string
    {
        return self::get('portal_host', env('VPN_PORTAL_HOST'));
    }

    public static function portalUrl(string $path = '/vpn'): string
    {
        $host = self::portalHost();
        return $host ? 'https://' . $host . $path : rtrim(config('app.url'), '/') . $path;
    }

    public static function maxDevices(): int
    {
        return max(1, min(50, (int) self::get('max_devices', '5')));
    }

    /** Reverse proxies in front of the staff VPN page whose X-Forwarded-For may be trusted. */
    public static function proxyIps(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) self::get('proxy_ips', ''))), fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP)));
    }

    public static function microsoftReady(): bool
    {
        return self::clientId() && self::hasClientSecret();
    }
}
