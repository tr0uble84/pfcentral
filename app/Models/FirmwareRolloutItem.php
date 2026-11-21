<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One firewall in a rollout. */
class FirmwareRolloutItem extends Model
{
    /** Being worked on now. */
    public const ACTIVE = ['queued', 'backing_up', 'starting', 'upgrading'];
    /** Finished well. */
    public const DONE = ['done', 'up_to_date', 'skipped'];
    /** Stopped and needs someone to look. */
    public const NEEDS_ATTENTION = ['failed', 'needs_api'];

    protected $fillable = [
        'firmware_rollout_id', 'firewall_id', 'wave', 'status', 'from_version', 'to_version', 'backup_path', 'backup_at',
        'boot_before', 'api_misses', 'api_reinstall_tried', 'message', 'log_tail', 'started_at', 'finished_at',
    ];
    protected $casts = ['backup_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'api_reinstall_tried' => 'boolean'];

    public function rollout()
    {
        return $this->belongsTo(FirmwareRollout::class, 'firmware_rollout_id');
    }

    public function firewall()
    {
        return $this->belongsTo(Firewall::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }
}
