<?php

namespace App\Models\Vpn;

use Illuminate\Database\Eloquent\Model;

/** A support ticket sent from the CFBTEL VPN Windows app. */
class VpnSupportReport extends Model
{
    protected $table = 'vpn_support_reports';
    protected $guarded = ['id'];
    protected $casts = ['emailed_at' => 'datetime'];

    public function reference(): string
    {
        return ($this->getAttributes()['reference'] ?? null) ?: 'VPN-' . $this->id;
    }

    /** A random, unused reference like "VPN-48213". */
    public static function newReference(): string
    {
        for ($i = 0; $i < 50; $i++) {
            $ref = 'VPN-' . random_int(10000, 99999);
            if (!static::where('reference', $ref)->exists()) {
                return $ref;
            }
        }
        return 'VPN-' . random_int(100000, 999999);
    }
}
