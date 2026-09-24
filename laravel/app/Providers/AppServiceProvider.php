<?php

namespace App\Providers;

use App\Support\TrustProxy;
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
    }
}
