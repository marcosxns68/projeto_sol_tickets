<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        RateLimiter::for('integrations', function (Request $request) {
            $integration = $request->attributes->get('integration');
            return Limit::perMinute(120)->by($integration?->id ? 'integration:'.$integration->id : $request->ip());
        });
    }
}
