<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken;

class IntegrationAccessToken extends PersonalAccessToken
{
    protected $table = 'personal_access_tokens';

    protected $fillable = ['name', 'token', 'abilities', 'expires_at', 'company_id'];

    protected $casts = [
        'abilities' => 'json',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'company_id' => 'integer',
    ];
}
