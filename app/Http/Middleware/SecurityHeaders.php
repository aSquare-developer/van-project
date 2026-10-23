<?php

namespace App\Http\Middleware;

use App\Models\ShiftCheck;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $check = $request->route('check');
        $capture = $response->isSuccessful() && $request->routeIs('shifts.checks.show')
            && $check instanceof ShiftCheck && $request->user()?->can('submitPhoto', $check);
        $response->headers->set('Permissions-Policy', ($capture ? 'camera=(self)' : 'camera=()').', microphone=(), geolocation=()');
        $response->headers->set('Cache-Control', 'no-store, private');
        if (app()->isProduction()) {
            $images = $capture ? "'self' data: blob:" : "'self' data:";
            $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self'; img-src {$images}; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
            if ($request->isSecure()) {
                $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
            }
        }

        return $response;
    }
}
