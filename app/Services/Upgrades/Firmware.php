<?php

namespace App\Services\Upgrades;

use App\Models\Firewall;
use App\Models\FirmwareCheck;
use App\Models\FirmwareRolloutItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

/**
 * Upgrade centre rules: which firewalls a person may upgrade, the pre-checks, and where backups go.
 */
class Firmware
{
    /** Below this, an upgrade is refused (KB). */
    public const DISK_FAIL_KB = 700 * 1024;
    /** Below this, a warning (KB). */
    public const DISK_WARN_KB = 1536 * 1024;
    /** Thousands of saved config versions have stopped upgrades (seen on 24.11 → 25.07). */
    public const BACKUPS_WARN = 500;
    public const BACKUPS_FAIL = 2000;
    /** How long to wait for a firewall to come back on the new version. */
    public const DEADLINE_MINUTES = 60;
    /** Missed API answers (one a minute) while the web page answers, before treating the API package as removed. */
    public const API_MISSES = 3;

    private static ?\Closure $resolver = null;

    /** Tests swap in a fake firewall. */
    public static function resolveDriversWith(?\Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    public static function driverFor(Firewall $firewall): FirmwareDriver
    {
        if (self::$resolver) {
            return (self::$resolver)($firewall);
        }
        if ($firewall->isOpnSense()) {
            throw new \RuntimeException('The upgrade centre supports pfSense for now. Upgrade OPNsense from its own page.');
        }
        return new PfSenseFirmwareDriver($firewall);
    }

    /** Firewalls this person may see and upgrade. */
    public static function firewallsFor(User $user): Builder
    {
        $q = Firewall::query()->with('company')->orderBy('name');
        return $user->isGlobalAdmin() ? $q : $q->where('company_id', $user->company_id);
    }

    public static function canUpgrade(User $user, Firewall $firewall): bool
    {
        return !$user->isReadOnly() && ($user->isGlobalAdmin() || (int) $firewall->company_id === (int) $user->company_id);
    }

    /**
     * Pre-checks from the firewall's facts. Each: key, label, level (ok, warn, fail, info) and detail.
     *
     * @return array<int, array{key: string, label: string, level: string, detail: string}>
     */
    public static function evaluate(array $facts, FirmwareDriver $driver): array
    {
        $out = [];
        $free = $facts['disk_free_kb'];
        $out[] = ['key' => 'disk', 'label' => 'Free disk space', 'level' => $free === null ? 'warn' : ($free < self::DISK_FAIL_KB ? 'fail' : ($free < self::DISK_WARN_KB ? 'warn' : 'ok')),
            'detail' => $free === null ? 'Couldn\'t read the free space.' : self::gb($free) . ' free' . ($free < self::DISK_FAIL_KB ? ': at least ' . self::gb(self::DISK_FAIL_KB) . ' is needed' : '')];

        $n = $facts['backups'];
        $out[] = ['key' => 'backups', 'label' => 'Saved config versions', 'level' => $n === null ? 'warn' : ($n > self::BACKUPS_FAIL ? 'fail' : ($n > self::BACKUPS_WARN ? 'warn' : 'ok')),
            'detail' => $n === null ? 'Couldn\'t count them.' : number_format($n) . ' in /cf/conf/backup' . ($n > self::BACKUPS_WARN ? '. Thousands of these have stopped upgrades: lower Diagnostics → Backup & Restore → Config History → backup count, and remove old ones.' : '')];

        $out[] = ['key' => 'running', 'label' => 'Another upgrade', 'level' => $facts['running'] ? 'fail' : 'ok',
            'detail' => $facts['running'] ? 'An upgrade is already running on this firewall.' : 'None running.'];

        $out[] = ['key' => 'filter', 'label' => 'Firewall filtering', 'level' => strcasecmp($facts['pf'], 'Enabled') === 0 ? 'ok' : 'warn',
            'detail' => $facts['pf'] !== '' ? "pf is {$facts['pf']}." : 'Couldn\'t read the packet filter state.'];

        $out[] = ['key' => 'rollback', 'label' => 'Way back', 'level' => $facts['zfs'] ? 'ok' : 'warn',
            'detail' => $facts['zfs'] ? 'ZFS: the previous version stays in the boot menu, and PFCentral keeps a config backup.' : 'No ZFS, so no boot-menu rollback. The config backup PFCentral takes first is the way back.'];

        $out[] = ['key' => 'api', 'label' => 'REST API package', 'level' => $driver->canReinstallApi() ? 'ok' : 'warn',
            'detail' => $driver->canReinstallApi()
                ? 'A pfSense upgrade removes it. PFCentral reinstalls it over SSH when the firewall is back.'
                : 'A pfSense upgrade removes it. Add SSH details to this firewall in PFCentral to have it reinstalled automatically; otherwise you run one command on the console afterwards.'];

        $netgate = stripos($facts['maker'], 'netgate') !== false;
        $out[] = ['key' => 'hardware', 'label' => 'Hardware', 'level' => 'info',
            'detail' => $netgate ? "Netgate hardware ({$facts['product']}): online upgrades keep working after 26.10." : 'Own hardware: from pfSense 26.10, moving to a new major version needs a subscription or a reinstall from the installer.'];

        return $out;
    }

    /** ok, warn or fail for a list of checks. */
    public static function verdict(array $checks): string
    {
        $levels = array_column($checks, 'level');
        return in_array('fail', $levels, true) ? 'fail' : (in_array('warn', $levels, true) ? 'warn' : 'ok');
    }

    public static function failures(array $checks): array
    {
        return array_values(array_map(fn ($c) => "{$c['label']}: {$c['detail']}", array_filter($checks, fn ($c) => $c['level'] === 'fail')));
    }

    private static function gb(int $kb): string
    {
        return number_format($kb / 1048576, 1) . ' GB';
    }

    /** Run the pre-checks and the "is there a newer version?" check, and save the result. */
    public static function check(Firewall $firewall): FirmwareCheck
    {
        $row = FirmwareCheck::firstOrNew(['firewall_id' => $firewall->id]);
        try {
            $driver = self::driverFor($firewall);
            $facts = $driver->facts();
            $checks = self::evaluate($facts, $driver);
            $avail = $driver->available();
            $row->fill([
                'status' => self::verdict($checks),
                'version' => $facts['version'],
                'available_version' => $avail['version'],
                'up_to_date' => $avail['up_to_date'],
                'facts' => $facts,
                'checks' => $checks,
                'error' => null,
                'checked_at' => now(),
            ])->save();
        } catch (\Throwable $e) {
            $row->fill(['status' => 'error', 'error' => mb_substr($e->getMessage(), 0, 500), 'checked_at' => now()])->save();
        }
        return $row;
    }

    /** Keep a config backup, encrypted, for this upgrade. */
    public static function storeBackup(FirmwareRolloutItem $item, string $xml): string
    {
        $path = "upgrade-backups/{$item->firewall_id}/" . now()->format('Ymd-His') . "-rollout{$item->firmware_rollout_id}.xml.enc";
        Storage::disk('local')->put($path, Crypt::encryptString($xml));
        return $path;
    }

    public static function readBackup(string $path): string
    {
        return Crypt::decryptString(Storage::disk('local')->get($path));
    }

    /** The dashboard reads the version from a long-lived cache: drop it after an upgrade. */
    public static function forgetCachedVersion(Firewall $firewall): void
    {
        Cache::forget('firewall_static_info_' . $firewall->id);
        Cache::forget('firewall_status_' . $firewall->id);
    }
}
