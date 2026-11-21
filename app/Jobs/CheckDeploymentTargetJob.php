<?php

namespace App\Jobs;

use App\Models\DeploymentTarget;
use App\Services\Deploy\DeployHelper;
use App\Services\Deploy\DeployRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Pre-check of one firewall in a deployment that hasn't started: what each change would do there. Changes nothing. */
class CheckDeploymentTargetJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 200;
    public int $tries = 1;

    public function __construct(public int $targetId)
    {
    }

    public function handle(): void
    {
        $t = DeploymentTarget::with(['firewall', 'deployment'])->find($this->targetId);
        if (!$t || !$t->firewall || !$t->deployment || $t->status !== 'checking') {
            return;
        }
        try {
            $answer = (new DeployHelper($t->firewall))->check($t->deployment->items);
            [$status, $message] = DeployRunner::verdict($answer['plan'] ?? []);
            $t->update(['status' => $status === 'ok' ? 'ready' : $status, 'message' => $message, 'check' => DeployRunner::facts($answer), 'checked_at' => now()]);
        } catch (\Throwable $e) {
            $t->update(['status' => 'problem', 'message' => 'Couldn\'t check this firewall: ' . $e->getMessage(), 'check' => null, 'checked_at' => now()]);
        }
        $t->deployment->refreshStatus();
    }
}
