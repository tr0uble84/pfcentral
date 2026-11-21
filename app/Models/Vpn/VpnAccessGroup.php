<?php

namespace App\Models\Vpn;

use Illuminate\Database\Eloquent\Model;

/**
 * A company's VPN access group: which of its firewalls, and who may use them
 * (everyone with VPN access, people picked by hand, members of linked sign-in provider groups).
 */
class VpnAccessGroup extends Model
{
    protected $table = 'vpn_access_groups';
    protected $fillable = ['company_id', 'name', 'everyone'];
    protected $casts = ['everyone' => 'boolean'];

    public function sites()
    {
        return $this->belongsToMany(VpnSite::class, 'vpn_access_group_sites', 'vpn_access_group_id', 'vpn_site_id');
    }

    public function users()
    {
        return $this->belongsToMany(VpnUser::class, 'vpn_access_group_users', 'vpn_access_group_id', 'vpn_user_id');
    }

    public function links()
    {
        return $this->hasMany(VpnAccessGroupLink::class, 'vpn_access_group_id');
    }
}
