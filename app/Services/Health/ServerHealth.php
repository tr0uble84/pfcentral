<?php

namespace App\Services\Health;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

/**
 * Health of the PFCentral server itself (not the firewalls): disk, memory,
 * database, MySQL change history, Redis, cache and queue drivers, the
 * scheduler, the queue worker and the root maintenance timer.
 *
 * Every check returns ['key','label','status','value','detail','fix'] where
 * status is ok | warn | bad | unknown. Nothing here throws: a failing probe
 * becomes a 'bad' or 'unknown' check, because this page is most needed
 * exactly when something is broken.
 */
class ServerHealth
{
    public const DISK_WARN = 80;
    public const DISK_BAD = 90;
    public const SCHEDULER_FILE = 'framework/pfcentral-scheduler.heartbeat';
    public const QUEUE_FILE = 'framework/pfcentral-queue.heartbeat';
    public const MAINTENANCE_FILE = '/var/lib/pfcentral/maintenance.json';

    /** All checks, for the Server health page. */
    public static function checks(): array
    {
        $checks = [];
        foreach ([
            'disk', 'memory', 'database', 'mysqlHistory', 'redis', 'cacheStore',
            'queue', 'scheduler', 'worker', 'maintenance', 'appLogs',
        ] as $probe) {
            try {
                $checks[] = self::$probe();
            } catch (\Throwable $e) {
                $checks[] = self::check($probe, ucfirst($probe), 'unknown', '—', 'Could not check: ' . $e->getMessage());
            }
        }

        return $checks;
    }

    /** Overall status: the worst status among the checks. */
    public static function overall(array $checks): string
    {
        $rank = ['ok' => 0, 'unknown' => 1, 'warn' => 2, 'bad' => 3];
        $worst = 'ok';
        foreach ($checks as $c) {
            if ($rank[$c['status']] > $rank[$worst]) {
                $worst = $c['status'];
            }
        }

        return $worst;
    }

    /**
     * The few problems worth a banner on every page for global admins.
     * Cached for a minute in the file cache, so a broken Redis can't break it.
     */
    public static function bannerProblems(): array
    {
        try {
            return Cache::store('file')->remember('pfc:health:banner', 60, function () {
                $out = [];
                $disk = self::disk();
                if (in_array($disk['status'], ['warn', 'bad'], true) && $disk['raw']['pct'] >= 85) {
                    $out[] = 'The server disk is ' . $disk['raw']['pct'] . '% full. PFCentral stops working when it reaches 100%.';
                }
                $redis = self::redis();
                if ($redis['status'] === 'bad') {
                    $out[] = 'Redis is not accepting writes, so pages may hang. ' . $redis['detail'];
                }
                $sched = self::scheduler();
                if ($sched['status'] === 'bad') {
                    $out[] = 'Background tasks have not run for a while (' . $sched['value'] . '), so firewall status is not refreshing.';
                }

                return $out;
            });
        } catch (\Throwable) {
            return [];
        }
    }

    // ----- individual checks -------------------------------------------------

    public static function disk(): array
    {
        $path = base_path();
        $total = @disk_total_space($path) ?: 0;
        $free = @disk_free_space($path) ?: 0;
        if ($total <= 0) {
            return self::check('disk', 'Disk space', 'unknown', '—', 'Could not read the disk size.');
        }
        $used = $total - $free;
        $pct = (int) round($used / $total * 100);
        $status = $pct >= self::DISK_BAD ? 'bad' : ($pct >= self::DISK_WARN ? 'warn' : 'ok');

        return self::check('disk', 'Disk space', $status, $pct . '% used',
            self::bytes($free) . ' free of ' . self::bytes($total) . '.',
            $status === 'ok' ? null : 'Run the maintenance now: sudo /usr/local/sbin/pfcentral-maintenance.sh. If it is still full, see the largest items below or add disk space.',
            ['pct' => $pct, 'free' => $free, 'total' => $total]);
    }

    public static function memory(): array
    {
        $info = @file_get_contents('/proc/meminfo');
        if (! $info || ! preg_match('/MemTotal:\s+(\d+)/', $info, $t) || ! preg_match('/MemAvailable:\s+(\d+)/', $info, $a)) {
            return self::check('memory', 'Memory', 'unknown', '—', 'Could not read memory use.');
        }
        $total = (int) $t[1] * 1024;
        $avail = (int) $a[1] * 1024;
        $pct = (int) round(($total - $avail) / $total * 100);
        $load = function_exists('sys_getloadavg') ? (sys_getloadavg() ?: [0])[0] : null;
        $cpus = max(1, (int) @preg_match_all('/^processor/m', (string) @file_get_contents('/proc/cpuinfo')));
        $status = $pct >= 95 ? 'bad' : ($pct >= 90 ? 'warn' : 'ok');

        return self::check('memory', 'Memory and load', $status, $pct . '% used',
            self::bytes($avail) . ' available of ' . self::bytes($total) . ($load !== null ? sprintf('. Load %.2f on %d CPU%s.', $load, $cpus, $cpus > 1 ? 's' : '') : '.'),
            $status === 'ok' ? null : 'Check what is using memory with: top -o %MEM',
            ['pct' => $pct, 'load' => $load, 'cpus' => $cpus]);
    }

    public static function database(): array
    {
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            return self::check('database', 'Database', 'bad', 'Not reachable', self::short($e->getMessage()), 'Check that MySQL is running: sudo systemctl status mysql');
        }
        $size = null;
        $top = [];
        if (DB::getDriverName() === 'mysql') {
            $rows = DB::select('SELECT table_name AS t, data_length + index_length AS b, table_rows AS r
                FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY b DESC');
            $size = array_sum(array_map(fn ($r) => (int) $r->b, $rows));
            $top = array_map(fn ($r) => ['table' => $r->t, 'size' => self::bytes((int) $r->b), 'rows' => (int) $r->r], array_slice($rows, 0, 5));
        }

        return self::check('database', 'Database', 'ok', $size !== null ? self::bytes($size) : 'Connected',
            $top ? 'Largest tables: ' . implode(', ', array_map(fn ($t) => $t['table'] . ' ' . $t['size'], $top)) . '.' : 'Connected.',
            null, ['size' => $size, 'top' => $top]);
    }

    /** MySQL binary log (change history): the cause of the 2026-11-24 full disk. */
    public static function mysqlHistory(): array
    {
        if (DB::getDriverName() !== 'mysql') {
            return self::check('mysqlHistory', 'MySQL change history', 'ok', 'Not used', 'This database does not keep a change history.');
        }
        $vars = DB::selectOne('SELECT @@log_bin AS on_, @@binlog_expire_logs_seconds AS expire');
        if (! (int) $vars->on_) {
            return self::check('mysqlHistory', 'MySQL change history', 'ok', 'Off', 'MySQL does not keep a change history on this server.');
        }
        $expireHours = (int) round(((int) $vars->expire) / 3600);
        $bytes = null;
        try {
            $bytes = array_sum(array_map(fn ($r) => (int) ($r->File_size ?? 0), DB::select('SHOW BINARY LOGS')));
        } catch (\Throwable) {
            // The app's database user usually can't list them; the maintenance timer reports it instead.
            $m = self::maintenanceStatus();
            $bytes = isset($m['binlog_bytes']) ? (int) $m['binlog_bytes'] : null;
        }
        $keep = $expireHours >= 48 ? round($expireHours / 24) . ' days' : $expireHours . ' hours';
        $status = $expireHours > 7 * 24 ? 'warn' : 'ok';
        if ($bytes !== null && ($total = @disk_total_space(base_path())) && $bytes / $total > 0.25) {
            $status = 'bad';
        }

        return self::check('mysqlHistory', 'MySQL change history', $status,
            $bytes !== null ? self::bytes($bytes) : 'Kept ' . $keep,
            'MySQL keeps every change for ' . $keep . ' (binary log).' . ($bytes === null ? ' Size unknown until the maintenance timer has run.' : ''),
            $status === 'ok' ? null : 'The maintenance timer keeps one day. Install it with: sudo bash ' . base_path('scripts/install-maintenance.sh'),
            ['bytes' => $bytes, 'expire_hours' => $expireHours]);
    }

    /** Redis must answer and accept writes (it refuses writes when it can't save to a full disk). */
    public static function redis(): array
    {
        $uses = in_array('redis', [config('cache.default'), config('queue.default'), config('session.driver')], true)
            || config('broadcasting.default') === 'reverb';
        try {
            $r = Redis::connection(config('cache.stores.redis.connection', 'cache'));
            $key = 'pfc:health:probe';
            $r->setex($key, 30, '1');
            $ok = (string) $r->get($key) === '1';
            $r->del($key);
            if (! $ok) {
                throw new \RuntimeException('A test value could not be read back.');
            }
        } catch (\Throwable $e) {
            $msg = self::short($e->getMessage());
            $full = str_contains($msg, 'MISCONF');

            return self::check('redis', 'Redis', $uses ? 'bad' : 'warn', $full ? 'Refusing writes' : 'Not working',
                $full ? 'Redis could not save to disk and stopped accepting changes.' : $msg,
                $full ? 'Free disk space, then run: sudo systemctl restart redis-server' : 'Check Redis: sudo systemctl status redis-server');
        }

        return self::check('redis', 'Redis', 'ok', 'Working', 'Answers and accepts writes.');
    }

    public static function cacheStore(): array
    {
        $store = (string) config('cache.default');
        if ($store === 'database') {
            return self::check('cacheStore', 'Cache', 'warn', 'In MySQL',
                'The cache is kept in MySQL. It changes every few seconds, which fills the MySQL change history quickly.',
                'Updating PFCentral moves it to Redis automatically when Redis works. Or set CACHE_STORE=redis in .env and run: php artisan config:cache');
        }

        return self::check('cacheStore', 'Cache', 'ok', $store === 'redis' ? 'In Redis' : ucfirst($store), 'Cache store: ' . $store . '.');
    }

    public static function queue(): array
    {
        $driver = (string) config('queue.default');
        $pending = null;
        try {
            if ($driver === 'database' && Schema::hasTable('jobs')) {
                $pending = DB::table('jobs')->count();
            } elseif ($driver === 'redis') {
                $pending = (int) Redis::connection(config('queue.connections.redis.connection', 'default'))
                    ->llen('queues:' . config('queue.connections.redis.queue', 'default'));
            }
        } catch (\Throwable) {
        }
        $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count() : 0;
        $status = ($pending !== null && $pending > 500) || $failed > 50 ? 'warn' : 'ok';

        return self::check('queue', 'Job queue', $status,
            ($pending !== null ? $pending . ' waiting' : ucfirst($driver)),
            $failed . ' job' . ($failed === 1 ? '' : 's') . ' failed in the last 24 hours. Queue: ' . $driver . '.',
            $status === 'ok' ? null : 'See failed jobs with: php artisan queue:failed',
            ['pending' => $pending, 'failed24h' => $failed, 'driver' => $driver]);
    }

    public static function scheduler(): array
    {
        $age = self::fileAge(storage_path(self::SCHEDULER_FILE));
        if ($age === null) {
            return self::check('scheduler', 'Scheduled tasks', 'warn', 'No signal yet',
                'The scheduler has not checked in since this version was installed.',
                'It checks in every minute. If this stays, check the cron line: crontab -u www-data -l');
        }
        $status = $age > 600 ? 'bad' : ($age > 180 ? 'warn' : 'ok');

        return self::check('scheduler', 'Scheduled tasks', $status, self::ago($age),
            'Last run of the task scheduler (firewall status, backups, deployments).',
            $status === 'ok' ? null : 'Check the cron line for the web user: crontab -u www-data -l (it should run "php artisan schedule:run" every minute).',
            ['age' => $age]);
    }

    public static function worker(): array
    {
        $age = self::fileAge(storage_path(self::QUEUE_FILE));
        if ($age === null) {
            return self::check('worker', 'Queue worker', 'unknown', 'No signal yet', 'The queue worker reports in every 5 minutes.');
        }
        $status = $age > 1800 ? 'bad' : ($age > 900 ? 'warn' : 'ok');

        return self::check('worker', 'Queue worker', $status, self::ago($age),
            'Last job the background worker finished.',
            $status === 'ok' ? null : 'Restart the workers: sudo supervisorctl restart all',
            ['age' => $age]);
    }

    public static function maintenance(): array
    {
        $m = self::maintenanceStatus();
        $cmd = 'sudo bash ' . base_path('scripts/install-maintenance.sh');
        if (! $m) {
            return self::check('maintenance', 'Maintenance timer', 'warn', 'Not installed',
                'The timer that cleans up logs and MySQL history every 6 hours has not reported in.',
                'Install it once on the server: ' . $cmd);
        }
        $age = isset($m['finished_at']) ? max(0, time() - strtotime($m['finished_at'])) : null;
        $status = $age === null || $age > 13 * 3600 ? 'warn' : 'ok';

        return self::check('maintenance', 'Maintenance timer', $status,
            $age !== null ? 'Ran ' . self::ago($age) : 'Unknown',
            'Every 6 hours.' . (isset($m['freed_bytes']) ? ' Last run freed ' . self::bytes((int) $m['freed_bytes']) . '.' : '')
                . (! empty($m['notes']) ? ' ' . implode(' ', array_slice((array) $m['notes'], 0, 3)) : ''),
            $status === 'ok' ? null : 'Check it with: systemctl status pfcentral-maintenance.timer. Reinstall with: ' . $cmd,
            $m);
    }

    public static function appLogs(): array
    {
        $dir = storage_path('logs');
        $bytes = 0;
        foreach (glob($dir . '/*.log') ?: [] as $f) {
            $bytes += (int) @filesize($f);
        }
        $status = $bytes > 1024 ** 3 ? 'bad' : ($bytes > 500 * 1024 ** 2 ? 'warn' : 'ok');

        return self::check('appLogs', 'PFCentral logs', $status, self::bytes($bytes),
            'Log files in ' . $dir . '.',
            $status === 'ok' ? null : 'The maintenance timer trims them. To trim now: sudo /usr/local/sbin/pfcentral-maintenance.sh',
            ['bytes' => $bytes]);
    }

    // ----- heartbeats --------------------------------------------------------

    /** Called every minute by the scheduler. */
    public static function beatScheduler(): void
    {
        @touch(storage_path(self::SCHEDULER_FILE));
    }

    /** Called by a queued job every 5 minutes. */
    public static function beatQueue(): void
    {
        @touch(storage_path(self::QUEUE_FILE));
    }

    // ----- helpers -----------------------------------------------------------

    public static function maintenanceStatus(): ?array
    {
        $raw = @file_get_contents(self::MAINTENANCE_FILE);
        $data = $raw ? json_decode($raw, true) : null;

        return is_array($data) ? $data : null;
    }

    private static function check(string $key, string $label, string $status, string $value, string $detail, ?string $fix = null, array $raw = []): array
    {
        return compact('key', 'label', 'status', 'value', 'detail', 'fix', 'raw');
    }

    private static function fileAge(string $file): ?int
    {
        clearstatcache(true, $file);
        $t = @filemtime($file);

        return $t ? max(0, time() - $t) : null;
    }

    public static function bytes(int|float $b): string
    {
        $u = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($b >= 1024 && $i < count($u) - 1) {
            $b /= 1024;
            $i++;
        }

        return ($i === 0 ? (int) $b : number_format($b, $b >= 10 ? 0 : 1)) . ' ' . $u[$i];
    }

    public static function ago(int $s): string
    {
        return match (true) {
            $s < 90 => $s . ' seconds ago',
            $s < 5400 => round($s / 60) . ' minutes ago',
            $s < 172800 => round($s / 3600) . ' hours ago',
            default => round($s / 86400) . ' days ago',
        };
    }

    private static function short(string $m): string
    {
        $m = trim(preg_replace('/\s+/', ' ', $m));

        return mb_strlen($m) > 220 ? mb_substr($m, 0, 217) . '…' : $m;
    }
}
