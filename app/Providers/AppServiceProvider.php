<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RateLimiter::for('dictionary', function () {
            return Limit::perSecond(2);
        });

        RateLimiter::for('definitions-ai', function () {
            return Limit::perMinute((int) config('services.definitions_ai.rate_limit', 10));
        });
    }
}
