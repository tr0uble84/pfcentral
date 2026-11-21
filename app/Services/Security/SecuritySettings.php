<?php

namespace App\Services\Security;

use App\Models\SystemSetting;

/**
 * System → Security. Everything here is off until a global admin turns it on:
 *  - two-factor sign-in for everyone who signs in with a password (Microsoft 365 sign-ins count as two-factor);
 *  - opening firewall web interfaces through PFCentral, on their own host name;
 *  - the addresses a locked firewall web interface still accepts.
 */
class SecuritySettings
{
    public static function get(string $key, ?string $default = null): ?string
    {
        try {
            $v = SystemSetting::where('key', "security_{$key}")->value('value');
        } catch (\Throwable) {
            return $default; // database not ready
        }
        return $v === null || $v === '' ? $default : (string) $v;
    }

    public static function set(string $key, ?string $value): void
    {
        SystemSetting::updateOrCreate(['key' => "security_{$key}"], ['value' => (string) ($value ?? '')]);
    }

    public static function requireTwoFactor(): bool
    {
        return self::get('require_2fa') === '1';
    }

    public static function guiProxyEnabled(): bool
    {
        return self::get('gui_proxy') === '1' && self::guiHost() !== null;
    }

    /** e.g. fwgui.cfbtel.com: only the proxied firewall web interfaces are served there. */
    public static function guiHost(): ?string
    {
        $h = strtolower(trim((string) self::get('gui_host')));
        return $h !== '' && preg_match('/^[a-z0-9.-]{1,190}$/', $h) ? $h : null;
    }

    /** Minutes without a click before a web interface session ends. */
    public static function guiIdleMinutes(): int
    {
        return max(5, min(240, (int) self::get('gui_idle_minutes', '30')));
    }

    public const GUI_MAX_HOURS = 8;

    /** @return string[] addresses a locked web interface accepts (PFCentral's own and any extra ones). */
    public static function lockAllowed(): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', (string) self::get('lock_allowed')) as $a) {
            if ($a !== '' && self::validAddress($a)) {
                $out[] = $a;
            }
        }
        return array_values(array_unique($out));
    }

    /** An IP address or a network in CIDR form. */
    public static function validAddress(string $a): bool
    {
        if (filter_var($a, FILTER_VALIDATE_IP)) {
            return true;
        }
        if (preg_match('#^(.+)/(\d{1,3})$#', $a, $m) && filter_var($m[1], FILTER_VALIDATE_IP)) {
            $max = str_contains($m[1], ':') ? 128 : 32;
            return (int) $m[2] >= 8 && (int) $m[2] <= $max;
        }
        return false;
    }
}
