<?php

namespace App\Jobs;

use App\Models\Firewall;
use App\Services\Upgrades\Firmware;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Upgrade centre: pre-checks and "is a newer version available?" for one firewall. */
class CheckFirmwareJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $timeout = 180;
    public int $tries = 1;
    public int $uniqueFor = 300;

    public function __construct(public int $firewallId)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->firewallId;
    }

    public function handle(): void
    {
        if ($fw = Firewall::find($this->firewallId)) {
            Firmware::check($fw);
        }
    }
}
