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
        // Brute-force protection for register/login: 10 attempts per minute per IP + email.
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip() . '|' . strtolower((string) $request->input('email')));
        });
    }
}