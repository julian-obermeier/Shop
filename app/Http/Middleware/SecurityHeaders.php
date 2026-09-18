<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response=$next($request);

        $response->headers->set('X-Content-Type-Options','nosniff');
        $response->headers->set('X-Frame-Options','DENY');
        $response->headers->set('Referrer-Policy','strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy','camera=(), microphone=(), geolocation=(), payment=()');
        $response->headers->set('Cross-Origin-Opener-Policy','same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy','same-origin');
        $response->headers->set('X-Permitted-Cross-Domain-Policies','none');

        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; ".
            "base-uri 'self'; ".
            "object-src 'none'; ".
            "frame-ancestors 'none'; ".
            "form-action 'self'; ".
            "img-src 'self' data:; ".
            "font-src 'self'; ".
            "style-src 'self' 'unsafe-inline'; ".
            "script-src 'self'; ".
            "connect-src 'self'"
        );

        if($request->isSecure()){
            $response->headers->set('Strict-Transport-Security','max-age=31536000; includeSubDomains');
        }

        if($request->user()){
            $response->headers->set('Cache-Control','no-store, private, max-age=0');
            $response->headers->set('Pragma','no-cache');
        }

        return $response;
    }
}
