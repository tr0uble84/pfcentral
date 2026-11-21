<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An upgrade of several firewalls, done in waves that an admin starts one at a time. */
class FirmwareRollout extends Model
{
    protected $fillable = ['name', 'company_id', 'created_by', 'status', 'current_wave'];

    public function items()
    {
        return $this->hasMany(FirmwareRolloutItem::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /** Global admins see every rollout; company admins and users only their company's. */
    public function visibleTo(User $user): bool
    {
        return $user->isGlobalAdmin() || ($this->company_id !== null && (int) $this->company_id === (int) $user->company_id);
    }

    public function waves(): array
    {
        return $this->items->pluck('wave')->unique()->sort()->values()->all();
    }

    /** Recompute the rollout's status from its items. */
    public function refreshStatus(): void
    {
        if ($this->status === 'stopped' || $this->status === 'draft') {
            return;
        }
        $items = $this->items()->get();
        $bad = $items->whereIn('status', FirmwareRolloutItem::NEEDS_ATTENTION)->count();
        $active = $items->whereIn('status', FirmwareRolloutItem::ACTIVE)->count();
        $open = $items->whereIn('status', ['pending'])->count();
        $status = $bad ? 'attention' : ($active || $open ? 'running' : 'completed');
        if ($status !== $this->status) {
            $this->update(['status' => $status]);
        }
    }
}
