<?php

namespace App\Providers;

use App\Models\IntegrationAccessToken;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class IntegrationApiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(IntegrationAccessToken::class);

        RateLimiter::for('integration-api-token', fn (Request $request) =>
            Limit::perMinute(config('integration-api.requests_per_minute'))
                ->by('integration-token:'.$request->user()->currentAccessToken()->getKey()));
    }
}
