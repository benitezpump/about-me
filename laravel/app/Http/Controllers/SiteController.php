<?php

namespace App\Http\Controllers;

use App\Services\SiteContent;
use App\Support\Format;
use Illuminate\Http\Response;

/** Sitio público. Solo orquesta: el contenido lo arma `SiteContent` y el HTML sale de las plantillas Blade. */
class SiteController extends Controller
{
    public function home(SiteContent $content): Response
    {
        return response()
            ->view('home', $content->get() + ['year' => date('Y'), 'viewsLabel' => null])
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
