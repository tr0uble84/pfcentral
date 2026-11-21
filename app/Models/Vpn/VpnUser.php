<?php

namespace App\Models\Vpn;

use App\Models\Company;
use Illuminate\Database\Eloquent\Model;

/**
 * Someone a company admin gave VPN access:
 *  - "microsoft": identified by their Microsoft 365 object id (ms_oid), signs in with Microsoft;
 *  - "local": email (upn) and a password the person sets through an emailed invitation link.
 */
class VpnUser extends Model
{
    protected $table = 'vpn_users';
    protected $fillable = [
        'company_id', 'auth_type', 'source', 'ms_oid', 'upn', 'name', 'added_by', 'last_login_at', 'blocked_at', 'blocked_by',
        'password', 'invite_token_hash', 'invite_expires_at', 'invited_at', 'password_set_at', 'failed_logins', 'locked_until',
        'mfa_mode', 'mfa_secret', 'mfa_enabled_at', 'mfa_recovery', 'mfa_last_step',
    ];
    protected $hidden = ['password', 'invite_token_hash', 'mfa_secret', 'mfa_recovery'];
    protected $casts = [
        'last_login_at' => 'datetime',
        'blocked_at' => 'datetime',
        'invite_expires_at' => 'datetime',
        'invited_at' => 'datetime',
        'password_set_at' => 'datetime',
        'locked_until' => 'datetime',
        'mfa_secret' => 'encrypted',
        'mfa_enabled_at' => 'datetime',
        'mfa_recovery' => 'encrypted:array',
        'mfa_last_step' => 'integer',
    ];

    /** Came in through the company's Microsoft 365 group (not added by hand). */
    public function viaGroup(): bool
    {
        return $this->source === 'group';
    }

    public function isLocal(): bool
    {
        return $this->auth_type === 'local';
    }

    /** Blocked from the live VPN page: no sign-in, no devices, until unblocked. */
    public function isBlocked(): bool
    {
        return $this->blocked_at !== null;
    }

    /** "active", "invited" (no password yet), "locked" or "blocked". */
    public function status(): string
    {
        if ($this->isBlocked()) {
            return 'blocked';
        }
        if ($this->isLocal() && $this->locked_until && $this->locked_until->isFuture()) {
            return 'locked';
        }
        return $this->isLocal() && !$this->password ? 'invited' : 'active';
    }

    /** Ties a session to the current password: changing it signs out older sessions. */
    public function passwordVersion(): string
    {
        return substr(hash('sha256', (string) $this->password), 0, 20);
    }

    public static function findLocalByEmail(string $email): ?self
    {
        return static::where('auth_type', 'local')->where('upn', strtolower(trim($email)))->first();
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function devices()
    {
        return $this->hasMany(VpnDevice::class);
    }

    public function accessGroups()
    {
        return $this->belongsToMany(VpnAccessGroup::class, 'vpn_access_group_users', 'vpn_user_id', 'vpn_access_group_id');
    }

    public function activeDevices()
    {
        return $this->hasMany(VpnDevice::class)->whereNull('revoked_at');
    }
}
