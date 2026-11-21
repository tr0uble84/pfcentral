<?php

namespace App\Models\Vpn;

use Illuminate\Database\Eloquent\Model;

/** One WireGuard peer on a site's firewall. The private key is shown once and never stored. */
class VpnDevice extends Model
{
    protected $table = 'vpn_devices';
    protected $fillable = ['vpn_user_id', 'vpn_site_id', 'name', 'public_key', 'address', 'revoked_at'];
    protected $casts = ['revoked_at' => 'datetime'];

    /** Peer name on the firewall; OPNsense allows only [0-9A-Za-z._-]. */
    public function peerName(): string
    {
        return 'cfbvpn-' . $this->id;
    }

    public function user()
    {
        return $this->belongsTo(VpnUser::class, 'vpn_user_id');
    }

    public function site()
    {
        return $this->belongsTo(VpnSite::class, 'vpn_site_id');
    }
}
