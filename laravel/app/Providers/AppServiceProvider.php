<?php

namespace App\Providers;

use App\Support\TrustProxy;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Un TRUST_PROXY inválido debe fallar al arrancar, no confiar a ciegas en cabeceras que el visitante controla.
        TrustProxy::parse(config('security.trust_proxy'));

        // Marca este navegador al iniciar sesión para que las visitas del propio administrador al sitio no cuenten.
        // No identifica a nadie: es solo un "1". Dura un año.
        Event::listen(Login::class, function (): void {
            Cookie::queue(cookie('notrack', '1', 365 * 24 * 60, '/', null, config('session.secure') === true, true, false, 'lax'));
        });
    }
}
