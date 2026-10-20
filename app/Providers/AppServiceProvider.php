<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
        RateLimiter::for('ingest-ip', fn(Request $request) => Limit::perMinute(300)->by($request->ip()));

        RateLimiter::for('ingest-source', function (Request $request) {
            $source = $request->attributes->get('source');

            return Limit::perMinute(120)->by('source:' . ($source?->id ?? $request->ip()));
        });
    }
}
