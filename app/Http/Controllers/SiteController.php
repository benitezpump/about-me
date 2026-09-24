<?php

namespace App\Http\Controllers;

use App\Services\SiteContent;
use App\Services\ViewCounter;
use App\Support\Format;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Sitio público. Solo orquesta: el contenido lo arma `SiteContent` y el HTML sale de las plantillas Blade. */
class SiteController extends Controller
{
    public function home(Request $request, SiteContent $content, ViewCounter $counter): Response
    {
        $data = $content->get();

        // Cuenta personas, no máquinas: sin robots, sin HEAD, sin el propio administrador (cookie que deja el login) y
        // respetando "No rastrear" (DNT) y Global Privacy Control.
        $userAgent = $request->userAgent();
        $skip = $request->isMethod('HEAD')
            || $request->cookie('notrack') !== null
            || $request->header('DNT') === '1'
            || $request->header('Sec-GPC') === '1'
            || ViewCounter::isBot($userAgent);

        // Se lee el total antes de registrar la visita y se le suma esta visita, para que la persona que llega se vea contada
        // y el resultado no dependa de qué consulta termine primero.
        $shows = (bool) $data['profile']->showViewCount;
        $base = $shows ? $counter->total() : null; // null: el contador no está disponible (no se muestra, pero la página sí)
        if (! $skip) {
            $ip = (string) $request->ip();
            // Se registra DESPUÉS de enviar la respuesta: el visitante no espera a la base y un fallo no rompe la página.
            // `defer` (no `afterResponse`) porque limpia cada callback tras ejecutarlo: `afterResponse` se acumula en la
            // aplicación y, con varias peticiones en el mismo proceso, repetiría las anteriores.
            defer(fn () => $counter->record($ip, (string) $userAgent));
        }
        $viewsLabel = $base !== null ? ViewCounter::formatViews($base + ($skip ? 0 : 1)) : null;

        return response()
            ->view('home', $data + ['year' => date('Y'), 'viewsLabel' => $viewsLabel])
            ->header('Cache-Control', 'public, max-age=0, must-revalidate');
    }

    /** Favicon generado con las iniciales del nombre corto, para que cambie con el perfil. */
    public function favicon(SiteContent $content): Response
    {
        $initials = e(Format::initials($content->get()['profile']->displayName));
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" rx="12" fill="#0e1320"/>'
            .'<text x="32" y="42" font-family="sans-serif" font-size="30" font-weight="700" text-anchor="middle" fill="#e2c48a">'
            .$initials.'</text></svg>';

        return response($svg, 200, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'public, max-age=3600']);
    }
}
