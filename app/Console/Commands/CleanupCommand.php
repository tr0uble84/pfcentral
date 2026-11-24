<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Daily housekeeping inside PFCentral's own database and storage. It only
 * removes things that are already expired or old: failed jobs older than
 * 30 days, expired cache rows and sessions, finished job batches, and dated
 * log files older than 14 days. Change logs and audit records are kept.
 */
class CleanupCommand extends Command
{
    protected $signature = 'pfcentral:cleanup {--dry-run : Show what would be removed}';

    protected $description = 'Remove expired cache rows, old failed jobs, expired sessions and old logs.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $done = [];

        $done['failed jobs older than 30 days'] = $this->deleteWhere('failed_jobs', fn ($q) => $q->where('failed_at', '<', now()->subDays(30)), $dry);
        $done['expired cache rows'] = $this->deleteWhere('cache', fn ($q) => $q->where('expiration', '<', time()), $dry);
        $done['expired cache locks'] = $this->deleteWhere('cache_locks', fn ($q) => $q->where('expiration', '<', time()), $dry);
        $done['finished job batches older than 2 days'] = $this->deleteWhere('job_batches',
            fn ($q) => $q->whereNotNull('finished_at')->where('finished_at', '<', now()->subDays(2)->timestamp), $dry);
        if (config('session.driver') === 'database') {
            $lifetime = (int) config('session.lifetime', 120) * 60;
            $done['expired sessions'] = $this->deleteWhere(config('session.table', 'sessions'),
                fn ($q) => $q->where('last_activity', '<', time() - $lifetime), $dry);
        }

        $logs = 0;
        foreach (glob(storage_path('logs/*.log')) ?: [] as $file) {
            if (basename($file) !== 'laravel.log' && @filemtime($file) < now()->subDays(14)->timestamp) {
                $logs++;
                if (! $dry) {
                    @unlink($file);
                }
            }
        }
        $done['log files older than 14 days'] = $logs;

        foreach ($done as $what => $n) {
            $this->line(sprintf('%s%d %s', $dry ? 'Would remove ' : 'Removed ', $n, $what));
        }

        return self::SUCCESS;
    }

    /** Delete in chunks so a large table doesn't lock MySQL or write one huge transaction. */
    private function deleteWhere(string $table, \Closure $where, bool $dry): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }
        if ($dry) {
            return $where(DB::table($table))->count();
        }
        $total = 0;
        do {
            $n = $where(DB::table($table))->limit(1000)->delete();
            $total += $n;
        } while ($n === 1000);

        return $total;
    }
}
