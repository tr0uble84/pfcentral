<?php

namespace App\Providers;

use App\Services\Vpn\ClientIp;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class VpnServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Staff VPN page: per visitor, also behind the reverse proxy.
        RateLimiter::for('vpn-portal', fn (Request $request) => Limit::perMinute(30)->by('vpn|' . ClientIp::of($request)));
    }
}
