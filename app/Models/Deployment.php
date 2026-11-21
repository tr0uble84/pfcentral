<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Changes (rules, aliases, port forwards, pfBlockerNG lists) sent to several firewalls, in waves. */
class Deployment extends Model
{
    protected $fillable = ['name', 'company_id', 'created_by', 'status', 'items', 'current_wave', 'scheduled_wave', 'scheduled_at', 'scheduled_by'];
    protected $casts = ['items' => 'array', 'scheduled_at' => 'datetime'];

    public function targets()
    {
        return $this->hasMany(DeploymentTarget::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /** Global admins see every deployment; company admins and users only their company's. */
    public function visibleTo(User $user): bool
    {
        return $user->isGlobalAdmin() || ($this->company_id !== null && (int) $this->company_id === (int) $user->company_id);
    }

    /** No wave has started: the changes and firewalls can still be edited. */
    public function isDraft(): bool
    {
        return in_array($this->status, ['draft', 'ready'], true);
    }

    public function waves(): array
    {
        return $this->targets->pluck('wave')->unique()->sort()->values()->all();
    }

    /** Recompute the deployment's status from its firewalls. */
    public function refreshStatus(): void
    {
        if ($this->status === 'stopped') {
            return;
        }
        $t = $this->targets()->get();
        if ($this->isDraft()) {
            $status = $t->whereIn('status', ['pending', 'checking'])->count() ? 'draft' : 'ready';
        } else {
            $bad = $t->whereIn('status', DeploymentTarget::NEEDS_ATTENTION)->count();
            $busy = $t->whereIn('status', DeploymentTarget::ACTIVE)->count();
            $open = $t->filter(fn ($x) => in_array($x->status, DeploymentTarget::NOT_STARTED, true) || ($x->status === 'needs_package' && !$x->started_at))->count();
            $status = $bad ? 'attention' : ($busy || $open ? 'running' : 'completed');
        }
        if ($status !== $this->status) {
            $this->update(['status' => $status]);
        }
    }
}
