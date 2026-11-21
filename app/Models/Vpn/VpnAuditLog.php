<?php

namespace App\Models\Vpn;

use Illuminate\Database\Eloquent\Model;

class VpnAuditLog extends Model
{
    protected $table = 'vpn_audit_logs';
    public $timestamps = false;
    protected $fillable = ['company_id', 'actor', 'action', 'created_at'];
    protected $casts = ['created_at' => 'datetime'];

    public static function record(?int $companyId, string $actor, string $action): void
    {
        static::create(['company_id' => $companyId, 'actor' => mb_substr($actor, 0, 255), 'action' => mb_substr($action, 0, 500), 'created_at' => now()]);
    }
}
