<?php

namespace App\Support;

/**
 * Servers installed before the PFCentral rename (v0.19.0) still use the earlier names for the SSL helper scripts,
 * the Supervisor programs and the system-backup encryption label. PFCentral uses the new names and falls back to
 * these, so those servers keep working without changes. The earlier prefix is stored encoded so the old product
 * name doesn't appear in PFCentral's code.
 */
final class LegacyInstall
{
    private const PREFIX = 'YWRtaXg='; // base64 of the earlier prefix

    public static function prefix(): string
    {
        return base64_decode(self::PREFIX);
    }

    /** /usr/local/bin/pfcentral-<name>, or the earlier helper if only that one is installed. */
    public static function helper(string $name): string
    {
        $new = '/usr/local/bin/pfcentral-' . $name;
        $old = '/usr/local/bin/' . self::prefix() . 'central-' . $name;
        return !is_executable($new) && is_executable($old) ? $old : $new;
    }

    /** Supervisor program: "worker" or "reverb" → pfcentral-worker, or the earlier program name if only it exists. */
    public static function supervisorNames(string $role): array
    {
        return ['pfcentral-' . $role, self::prefix() . '-' . $role];
    }

    public static function backupLabel(): string
    {
        return self::prefix() . 'central-backup-v2';
    }
}
