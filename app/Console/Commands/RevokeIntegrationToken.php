<?php

namespace App\Console\Commands;

use App\Models\IntegrationAccessToken;
use Illuminate\Console\Command;

class RevokeIntegrationToken extends Command
{
    protected $signature = 'integration:token:revoke {id : Numeric token ID}';
    protected $description = 'Revoke one integration token immediately';

    public function handle(): int
    {
        if (! ctype_digit((string) $this->argument('id'))) {
            $this->error('A numeric token ID is required.');
            return self::FAILURE;
        }
        $count = IntegrationAccessToken::whereKey($this->argument('id'))->delete();
        $this->info($count ? 'Token revoked.' : 'Token was already absent.');

        return self::SUCCESS;
    }
}
