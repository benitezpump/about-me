<?php

namespace App\Http\Middleware;

use App\Support\TrustProxy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sustituye al `TrustProxies` de Laravel, que solo entiende listas de IP y `*` y no un número de saltos. Modos (ver
 * App\Support\TrustProxy y config/security.php):
 *
 *  - ninguno: la IP es la de la conexión directa.
 *  - lista de IP/CIDR: Symfony toma la primera IP de X-Forwarded-For que no sea de un proxy de confianza.
 *  - número de saltos (o `true`): se calcula la IP aquí y se deja como IP de la conexión, de modo que `$request->ip()`, el
 *    límite de intentos y el contador la usen tal cual. Lo que el visitante escribió antes en la cabecera se ignora.
 */
class ApplyTrustedProxies
{
    public function handle(Request $request, Closure $next): Response
    {
        $trust = TrustProxy::parse(config('security.trust_proxy'));

        // Símbolo compartido entre peticiones (proceso persistente, pruebas): se restablece siempre.
        SymfonyRequest::setTrustedProxies([], SymfonyRequest::HEADER_X_FORWARDED_FOR | SymfonyRequest::HEADER_X_FORWARDED_PROTO);

        if ($trust === false) {
            return $next($request);
        }

        if (is_array($trust)) {
            SymfonyRequest::setTrustedProxies(
                $trust,
                SymfonyRequest::HEADER_X_FORWARDED_FOR | SymfonyRequest::HEADER_X_FORWARDED_PROTO
                | SymfonyRequest::HEADER_X_FORWARDED_HOST | SymfonyRequest::HEADER_X_FORWARDED_PORT,
            );

            return $next($request);
        }

        $forwarded = explode(',', (string) $request->headers->get('X-Forwarded-For', ''));
        $request->server->set('REMOTE_ADDR', TrustProxy::clientIp((string) $request->server->get('REMOTE_ADDR'), $forwarded, $trust));

        // Con HTTPS terminado en el proxy (Render), la aplicación ve http: se toma el esquema del proxy más cercano.
        $protos = array_map('trim', explode(',', strtolower((string) $request->headers->get('X-Forwarded-Proto', ''))));
        if (end($protos) === 'https') {
            $request->server->set('HTTPS', 'on');
            $request->server->set('SERVER_PORT', 443);
        }

        return $next($request);
    }
}
