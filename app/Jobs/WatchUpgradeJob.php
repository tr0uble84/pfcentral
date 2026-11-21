<?php

namespace App\Jobs;

use App\Models\FirmwareRolloutItem;
use App\Services\Upgrades\Firmware;
use App\Services\Upgrades\PfSenseFirmwareDriver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Waits for an upgrading firewall, once a minute, until it's back on a new version, says it was already up to
 * date, fails, or misses the deadline. A pfSense upgrade removes the REST API package: when the web page answers
 * but the API doesn't, PFCentral reinstalls the package over SSH, or asks for one console command.
 */
class WatchUpgradeJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 240;
    public int $tries = 1;

    public function __construct(public int $itemId)
    {
    }

    /** Look again in a minute. With the sync queue (tests), the caller runs the watch by hand instead. */
    public static function next(int $itemId, int $seconds = 60): void
    {
        if (config('queue.default') === 'sync') {
            return;
        }
        self::dispatch($itemId)->delay(now()->addSeconds($seconds));
    }

    public function handle(): void
    {
        $item = FirmwareRolloutItem::with(['firewall', 'rollout'])->find($this->itemId);
        if (!$item || !in_array($item->status, ['upgrading', 'needs_api'], true) || !$item->firewall) {
            return;
        }
        $fw = $item->firewall;
        $driver = Firmware::driverFor($fw);
        $minutes = $item->started_at ? $item->started_at->diffInMinutes(now()) : 0;

        try {
            $facts = $driver->facts();
        } catch (\Throwable $e) {
            $facts = null;
        }

        if ($facts !== null) {
            $item->api_misses = 0;
            $rebooted = $item->boot_before && $facts['boot'] && $facts['boot'] > $item->boot_before;
            if ($facts['version'] !== $item->from_version) {
                $this->finish($item, 'done', "Upgraded from {$item->from_version} to {$facts['version']}. The firewall is back and filtering.", $facts['version']);
                Firmware::forgetCachedVersion($fw);
                return;
            }
            $log = '';
            try {
                $log = $driver->log();
            } catch (\Throwable $e) {
            }
            $item->log_tail = $log !== '' ? $log : $item->log_tail;
            if (preg_match('/up to date/i', (string) $item->log_tail)) {
                $this->finish($item, 'up_to_date', 'Already up to date: nothing to install.', $facts['version']);
                return;
            }
            if (!$facts['running'] && ($rebooted || ($minutes >= 5 && !preg_match('/reboot/i', (string) $item->log_tail)))) {
                $this->finish($item, 'failed', ($rebooted ? 'The firewall restarted but is still on ' : 'The upgrade stopped and the firewall is still on ') . $item->from_version . '. See the log below.');
                return;
            }
            $item->status = 'upgrading';
            $item->message = $facts['running'] ? 'Upgrading…' : 'Finishing; the firewall will restart.';
            $item->save();
        } elseif ($driver->reachable()) {
            // The web page answers but the API doesn't: usually the upgrade removed the REST API package.
            $item->api_misses++;
            if ($item->api_misses >= Firmware::API_MISSES) {
                if ($driver->canReinstallApi() && !$item->api_reinstall_tried) {
                    $item->api_reinstall_tried = true;
                    $item->message = 'The firewall is back. Reinstalling the REST API package over SSH…';
                    $item->save();
                    try {
                        $item->log_tail = 'REST API reinstall: ' . $driver->reinstallApi();
                    } catch (\Throwable $e) {
                        Log::warning('Upgrade centre: REST API reinstall failed', ['item' => $item->id, 'error' => $e->getMessage()]);
                        $item->log_tail = 'REST API reinstall failed: ' . $e->getMessage();
                    }
                    $item->api_misses = 0;
                } else {
                    $item->status = 'needs_api';
                    $item->message = 'The firewall is back, but the upgrade removed the REST API package, so PFCentral can\'t confirm the new version. Run this once on the firewall (console option 8 or Diagnostics → Command Prompt), then click "Check again": '
                        . PfSenseFirmwareDriver::API_REINSTALL;
                    $item->save();
                    $item->rollout->refreshStatus();
                    return;
                }
            } else {
                $item->message = 'The firewall\'s web page answers; waiting for its API…';
            }
            $item->save();
        } else {
            $item->message = 'Restarting; waiting for the firewall to come back…';
            $item->save();
        }

        if ($minutes >= Firmware::DEADLINE_MINUTES) {
            $this->finish($item, 'failed', 'The firewall didn\'t come back within ' . Firmware::DEADLINE_MINUTES . ' minutes. Check it from the console; PFCentral has its configuration backup.');
            return;
        }
        self::next($item->id);
    }

    private function finish(FirmwareRolloutItem $item, string $status, string $message, ?string $version = null): void
    {
        $item->fill(['status' => $status, 'message' => $message, 'finished_at' => now(), 'to_version' => $version ?? $item->to_version])->save();
        $item->rollout->refreshStatus();
    }
}
