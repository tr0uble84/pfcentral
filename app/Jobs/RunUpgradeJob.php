<?php

namespace App\Jobs;

use App\Models\FirmwareRolloutItem;
use App\Services\Upgrades\Firmware;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * One firewall in a started wave: pre-checks again, config backup, then start the upgrade on the firewall.
 * WatchUpgradeJob then waits for it to come back.
 */
class RunUpgradeJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;
    public int $tries = 1;

    public function __construct(public int $itemId)
    {
    }

    public function handle(): void
    {
        $item = FirmwareRolloutItem::with(['firewall', 'rollout'])->find($this->itemId);
        if (!$item || $item->status !== 'queued' || !$item->firewall) {
            return;
        }
        $fw = $item->firewall;
        try {
            $driver = Firmware::driverFor($fw);

            $item->update(['status' => 'backing_up', 'message' => 'Checking the firewall and saving its configuration…']);
            $facts = $driver->facts();
            $checks = Firmware::evaluate($facts, $driver);
            if ($fails = Firmware::failures($checks)) {
                $this->fail($item, 'Pre-checks failed. ' . implode(' ', $fails));
                return;
            }
            $path = Firmware::storeBackup($item, $driver->backup());
            $item->update(['backup_path' => $path, 'backup_at' => now(), 'from_version' => $facts['version'], 'boot_before' => $facts['boot']]);

            $item->update(['status' => 'starting', 'message' => 'Starting the upgrade…']);
            $driver->start();
            $item->update(['status' => 'upgrading', 'started_at' => now(), 'message' => 'Upgrading. The firewall reboots by itself when it\'s done.']);
            WatchUpgradeJob::next($item->id);
        } catch (\Throwable $e) {
            Log::warning('Upgrade centre: starting the upgrade failed', ['item' => $item->id, 'firewall' => $fw->id, 'error' => $e->getMessage()]);
            $this->fail($item, 'Couldn\'t start the upgrade: ' . $e->getMessage());
        }
    }

    private function fail(FirmwareRolloutItem $item, string $message): void
    {
        $item->update(['status' => 'failed', 'message' => mb_substr($message, 0, 1000), 'finished_at' => now()]);
        $item->rollout->refreshStatus();
    }
}
