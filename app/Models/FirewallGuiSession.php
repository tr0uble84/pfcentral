<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A technician's firewall web interface session through PFCentral (temporary firewall account, logged). */
class FirewallGuiSession extends Model
{
    protected $fillable = [
        'user_id', 'user_email', 'firewall_id', 'token_hash', 'enter_hash', 'enter_expires_at', 'ip', 'temp_user',
        'temp_user_removed', 'cookie_jar', 'actions', 'requests', 'started_at', 'last_seen_at', 'ended_at', 'end_reason',
    ];
    protected $hidden = ['token_hash', 'enter_hash', 'cookie_jar'];
    protected $casts = [
        'actions' => 'array', 'temp_user_removed' => 'boolean', 'cookie_jar' => 'encrypted',
        'enter_expires_at' => 'datetime', 'started_at' => 'datetime', 'last_seen_at' => 'datetime', 'ended_at' => 'datetime',
    ];

    public function firewall()
    {
        return $this->belongsTo(Firewall::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
