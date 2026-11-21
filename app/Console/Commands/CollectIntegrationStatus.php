<?php

namespace App\Console\Commands;

use App\Jobs\CollectIntegrationFirewallStatus;
use App\Models\Firewall;
use App\Services\Integration\FirewallStatusCollector;
use Illuminate\Console\Command;

class CollectIntegrationStatus extends Command
{
    protected $signature = 'integration:status:collect {--firewall= : Optional numeric firewall ID} {--sync : Collect a single selected firewall immediately}';
    protected $description = 'Queue read-only firewall status collection; HTTP status endpoints never contact firewalls';

    public function handle(FirewallStatusCollector $collector): int
    {
        $selected = $this->option('firewall');
        if ($selected !== null && (! ctype_digit((string) $selected) || (int) $selected < 1)) {
            $this->error('Provide a positive numeric --firewall ID.'); return self::FAILURE;
        }
        if ($this->option('sync') && $selected === null) {
            $this->error('--sync requires --firewall to avoid a long whole-fleet CLI request.'); return self::FAILURE;
        }
        if (! $this->option('sync') && config('queue.default') === 'sync') {
            $this->error('Configure an asynchronous queue before scheduling whole-fleet status collection.'); return self::FAILURE;
        }
        $query = Firewall::query()->when($selected !== null, fn ($query) => $query->whereKey((int) $selected))->orderBy('id');
        $count = 0;
        foreach ($query->cursor() as $firewall) {
            if ($this->option('sync')) {
                $snapshot = $collector->collect($firewall);
                $this->line('Collected firewall '.$firewall->id.': '.($snapshot['online'] ? 'reachable' : 'unreachable').'; snapshot saved.');
            } else CollectIntegrationFirewallStatus::dispatch($firewall->id);
            $count++;
        }
        $this->info('Selected '.$count.' firewall(s). Queue deduplication prevents duplicate pending jobs.');
        return $count > 0 ? self::SUCCESS : self::FAILURE;
    }
}
