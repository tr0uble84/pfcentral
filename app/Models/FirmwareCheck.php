<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The latest upgrade pre-check of one firewall. */
class FirmwareCheck extends Model
{
    protected $fillable = ['firewall_id', 'status', 'version', 'available_version', 'up_to_date', 'facts', 'checks', 'error', 'checked_at'];
    protected $casts = ['facts' => 'array', 'checks' => 'array', 'up_to_date' => 'boolean', 'checked_at' => 'datetime'];

    public function firewall()
    {
        return $this->belongsTo(Firewall::class);
    }
}
