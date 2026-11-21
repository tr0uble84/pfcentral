<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Whether a firewall's web interface is locked to PFCentral (last known state). */
class FirewallGuiLock extends Model
{
    protected $fillable = ['firewall_id', 'locked', 'allowed', 'seen', 'port', 'changed_by', 'error', 'checked_at'];
    protected $casts = ['locked' => 'boolean', 'allowed' => 'array', 'seen' => 'array', 'checked_at' => 'datetime'];

    public function firewall()
    {
        return $this->belongsTo(Firewall::class);
    }
}
