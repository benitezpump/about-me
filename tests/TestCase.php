<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    private static bool $schemaReady = false;

    /**
     * Las pruebas de integración corren contra PostgreSQL REAL, nunca contra SQLite: el esquema usa restricciones
     * NOT VALID, llaves compuestas y RLS. Define TEST_DATABASE_URL apuntando a una base DESECHABLE: se borra el esquema
     * `public` completo y se recrea (por eso no se usa `migrate:fresh`, que no elimina las funciones de PostgreSQL).
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $url = getenv('TEST_DATABASE_URL') ?: ($_ENV['TEST_DATABASE_URL'] ?? '');
        if ($url === '') {
            $this->markTestSkipped('Define TEST_DATABASE_URL (PostgreSQL desechable) para correr las pruebas de integración.');
        }

        config(['database.default' => 'pgsql', 'database.connections.pgsql.url' => $url]);

        if (! self::$schemaReady) {
            DB::purge('pgsql');
            DB::unprepared('drop schema public cascade; create schema public');
            Artisan::call('migrate', ['--force' => true]);
            self::$schemaReady = true;
        }
    }
}
