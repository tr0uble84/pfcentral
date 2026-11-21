<?php

namespace App\Models\Vpn;

use Illuminate\Database\Eloquent\Model;

/**
 * A sign-in provider's group linked to a VPN access group: its members are members of the access group.
 * provider: "microsoft" (Microsoft 365 / Entra ID group object id). Other providers (Google, Okta, any
 * OpenID Connect directory) plug in here with their own provider name and group id.
 */
class VpnAccessGroupLink extends Model
{
    public const PROVIDERS = ['microsoft' => 'Microsoft 365'];

    protected $table = 'vpn_access_group_links';
    protected $fillable = ['vpn_access_group_id', 'provider', 'external_id', 'external_name'];

    public function group()
    {
        return $this->belongsTo(VpnAccessGroup::class, 'vpn_access_group_id');
    }

    public function providerName(): string
    {
        return self::PROVIDERS[$this->provider] ?? ucfirst($this->provider);
    }
}
