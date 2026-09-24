<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad (equivalentes a las de helmet en la versión Node).
 *
 * Sitio público: CSP estricta, sin scripts ni estilos inline (una prueba lo vigila).
 * Panel (/admin): Filament usa Livewire y Alpine, que necesitan `unsafe-inline` y `unsafe-eval` en `script-src`. Es una
 * concesión conocida y acotada a esas rutas; el resto de directivas sigue siendo estricto.
 */
class SecurityHeaders
{
    private const PUBLIC_CSP = [
        "default-src 'self'",
        "script-src 'self'",
        "style-src 'self' https://fonts.googleapis.com",
        'font-src https://fonts.gstatic.com',
        "img-src 'self' data:",
        "connect-src 'self'",
        "object-src 'none'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'none'",
    ];

    private const PANEL_CSP = [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://fonts.bunny.net",
        "font-src 'self' data: https://fonts.gstatic.com https://fonts.bunny.net",
        "img-src 'self' data: blob:",
        "connect-src 'self'",
        "object-src 'none'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'none'",
    ];

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $panel = $request->is('admin', 'admin/*', 'livewire', 'livewire/*', 'livewire-*/*');
        $h = $response->headers;

        $h->set('Content-Security-Policy', implode('; ', $panel ? self::PANEL_CSP : self::PUBLIC_CSP));
        $h->set('Cross-Origin-Opener-Policy', 'same-origin');
        $h->set('Cross-Origin-Resource-Policy', 'same-origin');
        $h->set('Origin-Agent-Cluster', '?1');
        $h->set('Referrer-Policy', 'no-referrer');
        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('X-DNS-Prefetch-Control', 'off');
        $h->set('X-Download-Options', 'noopen');
        $h->set('X-Frame-Options', 'SAMEORIGIN');
        $h->set('X-Permitted-Cross-Domain-Policies', 'none');
        $h->set('X-XSS-Protection', '0');
        $h->remove('X-Powered-By');

        // Solo con HTTPS de verdad (cookies `Secure`): en http local un HSTS dejaría el navegador forzando https.
        if (config('session.secure') === true) {
            $h->set('Strict-Transport-Security', 'max-age=15552000; includeSubDomains');
        }

        return $response;
    }
}
