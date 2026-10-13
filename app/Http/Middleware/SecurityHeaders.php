<?php

namespace App\Http\Middleware;

use App\Services\Seo;
use App\Services\Tenant;
use Closure;

class SecurityHeaders
{
    public function handle($request, Closure $next)
    {
        $nonce = base64_encode(random_bytes(18));
        $request->attributes->set('csp_nonce', $nonce);
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set('Content-Security-Policy', "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'nonce-$nonce'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'; object-src 'none'");
        $catalog = $request->isMethod('GET') && $request->is('catalog/*') && count($request->segments()) === 2;
        $company = $catalog ? app(Tenant::class)->company : null;
        if (! ($catalog && $response->getStatusCode() === 200 && Seo::metadata($company)['indexable'])) {
            if (! $request->is('assets/*', 'media/*', 'logo/*', 'catalog-media/*', 'favicon*', 'apple-touch-icon*')) {
                $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
            }
        }
        if (! $request->is('assets/*')) {
            if (! $request->is('robots.txt', 'sitemap.xml', 'sitemaps/*')) {
                $response->headers->set('Cache-Control', 'no-store, private');
            }
        }

        return $response;
    }
}
