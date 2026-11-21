<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One firewall in a deployment. */
class DeploymentTarget extends Model
{
    /** Pre-check done or not yet run; the firewall's wave hasn't started. */
    public const NOT_STARTED = ['pending', 'checking', 'ready', 'problem'];
    /** Being worked on now. */
    public const ACTIVE = ['queued', 'deploying', 'undoing'];
    /** Finished: nothing more will happen unless someone retries or undoes. */
    public const FINISHED = ['done', 'needs_package', 'skipped', 'reverted'];
    /** Stopped and needs someone to look. */
    public const NEEDS_ATTENTION = ['failed', 'undone'];

    protected $fillable = ['deployment_id', 'firewall_id', 'wave', 'status', 'check', 'checked_at', 'undo', 'result', 'changed', 'message', 'started_at', 'finished_at'];
    protected $casts = ['check' => 'array', 'result' => 'array', 'undo' => 'encrypted:array', 'checked_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    protected $hidden = ['undo'];

    public function deployment()
    {
        return $this->belongsTo(Deployment::class);
    }

    public function firewall()
    {
        return $this->belongsTo(Firewall::class);
    }
}
