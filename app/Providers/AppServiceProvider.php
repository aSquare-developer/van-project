<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        foreach (['login' => 5, 'password-reset' => 5] as $name => $attempts) {
            RateLimiter::for($name, fn (Request $request) => [
                Limit::perMinute(20)->by($name.'|ip|'.$request->ip()),
                Limit::perMinute($attempts)->by($name.'|account|'.hash('sha256', strtolower((string) $request->input('email')))),
            ]);
        }

        ResetPassword::createUrlUsing(fn (object $user, string $token): string => rtrim(config('app.url'), '/').'/reset-password/'.$token.'?email='.urlencode($user->email)
        );
    }
}
