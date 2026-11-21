<?php

namespace App\Jobs;

use App\Models\Firewall;
use App\Services\Integration\FirewallStatusCollector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class CollectIntegrationFirewallStatus implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 75;
    public int $uniqueFor = 300;

    public function __construct(public int $firewallId) { $this->onQueue('integration-status'); }

    public function uniqueId(): string { return 'integration-status:'.$this->firewallId; }

    public function handle(FirewallStatusCollector $collector): void
    {
        $lock = Cache::lock('integration-status-execution:'.$this->firewallId, 90);
        if (! $lock->get()) return;
        try {
            $firewall = Firewall::find($this->firewallId);
            if ($firewall) $collector->collect($firewall);
        } finally { $lock->release(); }
    }
}
