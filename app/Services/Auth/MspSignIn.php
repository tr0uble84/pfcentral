<?php

namespace App\Services\Auth;

use App\Models\SystemSetting;
use App\Services\Vpn\Microsoft;
use App\Services\Vpn\VpnSettings;
use Illuminate\Support\Facades\Crypt;

/**
 * "Sign in with Microsoft 365" for the MSP's own staff (the company running PFCentral).
 *
 * People in the chosen Microsoft 365 groups of the MSP's tenant can sign in and get the
 * administrator role (all companies). Membership is checked with Microsoft at every sign-in.
 * It uses CFBTEL's VPN Microsoft app unless an app of its own is entered.
 */
class MspSignIn
{
    public const MAX_GROUPS = 20;

    /** Tests swap in a fake Microsoft client. @var (\Closure(): Microsoft)|null */
    public static ?\Closure $factory = null;

    public static function get(string $key): ?string
    {
        $value = SystemSetting::where('key', "msp_ms_{$key}")->value('value');

        return $value === null || $value === '' ? null : (string) $value;
    }

    public static function set(string $key, ?string $value): void
    {
        if ($value === null || $value === '') {
            SystemSetting::where('key', "msp_ms_{$key}")->delete();

            return;
        }
        SystemSetting::updateOrCreate(['key' => "msp_ms_{$key}"], ['value' => $value]);
    }

    public static function tenantId(): ?string
    {
        return self::get('tenant_id');
    }

    /** @return array<int, array{id: string, name: string}> */
    public static function groups(): array
    {
        $groups = json_decode((string) self::get('groups'), true);

        return is_array($groups) ? array_values(array_filter($groups, fn ($g) => is_array($g) && Microsoft::isGuid($g['id'] ?? null))) : [];
    }

    public static function setGroups(array $groups): void
    {
        self::set('groups', $groups ? json_encode(array_values($groups)) : null);
    }

    public static function clientId(): ?string
    {
        return self::get('client_id');
    }

    public static function clientSecret(): ?string
    {
        $sealed = self::get('client_secret');
        if (!$sealed) {
            return null;
        }
        try {
            return Crypt::decryptString($sealed);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function setClientSecret(?string $secret): void
    {
        self::set('client_secret', $secret ? Crypt::encryptString($secret) : null);
    }

    public static function usesOwnApp(): bool
    {
        return (bool) self::clientId();
    }

    /** The Microsoft app to use: this sign-in's own app, or CFBTEL's VPN app. */
    public static function appReady(): bool
    {
        return self::usesOwnApp() ? (bool) self::clientSecret() : (VpnSettings::clientId() && VpnSettings::hasClientSecret());
    }

    public static function enabled(): bool
    {
        return self::get('enabled') === '1' && Microsoft::isGuid(self::tenantId()) && self::groups() !== [] && self::appReady();
    }

    public static function microsoft(): Microsoft
    {
        if (self::$factory) {
            return (self::$factory)();
        }

        return self::usesOwnApp()
            ? new Microsoft(self::clientId(), self::clientSecret(), self::tenantId())
            : new Microsoft(VpnSettings::clientId(), VpnSettings::clientSecret(), self::tenantId());
    }

    public static function redirectUri(): string
    {
        return route('login.microsoft.callback');
    }
}
