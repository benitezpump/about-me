<?php

namespace App\Providers;

use App\Services\ViewCounter;
use App\Support\SqlSchema;
use App\Support\TrustProxy;
use InvalidArgumentException;
use Illuminate\Auth\Events\Login;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\NoPendingMigrations;
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
        $this->app->singleton(ViewCounter::class, fn () => new ViewCounter((string) config('security.stats_timezone')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Un TRUST_PROXY inválido debe fallar al arrancar, no confiar a ciegas en cabeceras que el visitante controla.
        TrustProxy::parse(config('security.trust_proxy'));

        $zone = (string) config('security.stats_timezone');
        if (! in_array($zone, timezone_identifiers_list(), true)) {
            throw new InvalidArgumentException("STATS_TIMEZONE no es una zona horaria válida: \"{$zone}\" (ejemplo: America/Hermosillo).");
        }

        // Laravel no vuelve a correr la migración `apply_sql_schema` en una base que ya la registró: los archivos SQL nuevos se
        // aplican aquí, al final de cada `migrate` (también cuando "no hay nada que migrar"). Sigue bajo el bloqueo de --isolated.
        $applySql = function (MigrationsEnded|NoPendingMigrations $event): void {
            if ($event->method === 'up' && ! (($event->options ?? [])['pretend'] ?? false)) {
                SqlSchema::apply();
            }
        };
        Event::listen(MigrationsEnded::class, $applySql);
        Event::listen(NoPendingMigrations::class, $applySql);

        // Marca este navegador al iniciar sesión para que las visitas del propio administrador al sitio no cuenten.
        // No identifica a nadie: es solo un "1". Dura un año.
        Event::listen(Login::class, function (): void {
            Cookie::queue(cookie('notrack', '1', 365 * 24 * 60, '/', null, config('session.secure') === true, true, false, 'lax'));
        });
    }
}
