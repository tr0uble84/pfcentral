<?php

namespace App\Models\Vpn;

use App\Models\Company;
use App\Models\Firewall;
use Illuminate\Database\Eloquent\Model;

/** A company firewall with WireGuard remote access managed by PFCentral. */
class VpnSite extends Model
{
    protected $table = 'vpn_sites';
    protected $fillable = [
        'company_id', 'firewall_id', 'status', 'tunnel', 'tunnel_name', 'server_public_key',
        'subnet', 'endpoint', 'dns', 'allowed_ips', 'last_error', 'access_group_id', 'access_group_name',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function firewall()
    {
        return $this->belongsTo(Firewall::class);
    }

    public function devices()
    {
        return $this->hasMany(VpnDevice::class);
    }

    public function isReady(): bool
    {
        return $this->status === 'ready' && $this->tunnel && $this->server_public_key && $this->endpoint && $this->subnet;
    }
}
