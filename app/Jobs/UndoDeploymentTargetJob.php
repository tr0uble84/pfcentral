<?php

namespace App\Jobs;

use App\Models\DeploymentTarget;
use App\Services\Deploy\DeployRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Takes a deployment off one firewall again: removes what it added and puts back what it changed. */
class UndoDeploymentTargetJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 400;
    public int $tries = 1;

    public function __construct(public int $targetId)
    {
    }

    public function handle(): void
    {
        $t = DeploymentTarget::with(['firewall', 'deployment'])->find($this->targetId);
        if ($t && $t->status === 'undoing') {
            DeployRunner::undo($t);
        }
    }
}
