<?php

namespace App\Providers;

use App\Listeners\LogAuthenticationEvent;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        foreach ([Login::class, Logout::class, Failed::class, PasswordReset::class, Lockout::class] as $event) {
            Event::listen($event, [LogAuthenticationEvent::class, 'record']);
        }
        if ($this->app->isProduction()) {
            config(['session.secure' => true, 'session.http_only' => true, 'session.same_site' => 'lax']);
        }

        $throttled = function (Request $request, array $headers) {
            event(new Lockout($request));

            return response('Too many attempts. Please try again later.', 429, $headers);
        };
        foreach (['login' => 5, 'password-reset' => 5] as $name => $attempts) {
            RateLimiter::for($name, fn (Request $request) => [
                Limit::perMinute(20)->by($name.'|ip|'.$request->ip())->response($throttled),
                Limit::perMinute($attempts)->by($name.'|account|'.hash('sha256', strtolower((string) $request->input('email'))))->response($throttled),
            ]);
        }

        ResetPassword::createUrlUsing(fn (object $user, string $token): string => rtrim(config('app.url'), '/').'/reset-password/'.$token.'?email='.urlencode($user->email)
        );
        RateLimiter::for('inspection-uploads', fn (Request $request) => [
            Limit::perMinute(10)->by('inspection-minute|'.$request->user()->id),
            Limit::perHour(60)->by('inspection-hour|'.$request->user()->id),
        ]);
    }
}
