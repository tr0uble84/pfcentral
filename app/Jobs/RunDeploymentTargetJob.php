<?php

namespace App\Jobs;

use App\Models\DeploymentTarget;
use App\Services\Deploy\DeployRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Deploys to one firewall of a started wave. */
class RunDeploymentTargetJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;
    public int $tries = 1;

    public function __construct(public int $targetId)
    {
    }

    public function handle(): void
    {
        $t = DeploymentTarget::with(['firewall', 'deployment'])->find($this->targetId);
        if ($t && $t->status === 'queued') {
            DeployRunner::deploy($t);
        }
    }
}
