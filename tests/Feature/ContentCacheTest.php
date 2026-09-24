<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Services\SiteContent;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\Fixture;
use Tests\TestCase;

class ContentCacheTest extends TestCase
{
    use DatabaseTransactions;

    private function queries(callable $fn): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $fn();

        return count(DB::getQueryLog());
    }

    public function test_la_segunda_peticion_no_toca_la_base(): void
    {
        Fixture::insert();

        $this->get('/')->assertOk();
        $this->assertSame(0, $this->queries(fn () => $this->get('/')->assertOk()), 'servida desde la caché');
    }

    public function test_editar_con_un_modelo_invalida_la_cache_al_instante(): void
    {
        Fixture::insert();
        $this->get('/')->assertOk()->assertSee('Desarrolladora de ejemplo.');

        Profile::find(1)->update(['headline' => 'Titular nuevo.']);

        $this->get('/')->assertSee('Titular nuevo.')->assertDontSee('Desarrolladora de ejemplo.');
    }

    public function test_borrar_con_un_modelo_tambien_invalida(): void
    {
        Fixture::insert();
        $this->get('/')->assertSee('Proyecto reciente');

        \App\Models\Project::where('title', 'Proyecto reciente')->firstOrFail()->delete();

        $this->get('/')->assertDontSee('Proyecto reciente');
    }

    public function test_una_escritura_que_se_salta_los_modelos_no_invalida_hasta_llamar_a_forget(): void
    {
        Fixture::insert();
        $this->get('/')->assertSee('Desarrolladora de ejemplo.');

        DB::table('profile')->update(['headline' => 'Por SQL directo.']);
        $this->get('/')->assertSee('Desarrolladora de ejemplo.', false); // sigue la copia en caché (vence a los 60 s)

        SiteContent::forget();
        $this->get('/')->assertSee('Por SQL directo.');
    }

    public function test_una_carga_que_empezo_antes_de_una_edicion_no_se_guarda(): void
    {
        Fixture::insert();

        // Una carga lenta lee, y en medio llega una edición que invalida la caché: lo leído ya es viejo.
        $lenta = new class extends SiteContent
        {
            public function load(): array
            {
                $leido = parent::load();
                SiteContent::forget();

                return $leido;
            }
        };
        $lenta->get();

        $this->assertNull(Cache::get('site.content'), 'el resultado obsoleto no debe quedar en caché');
    }

    public function test_un_error_de_carga_no_se_cachea_y_la_siguiente_peticion_reintenta(): void
    {
        try {
            app(SiteContent::class)->get();
            $this->fail('sin perfil debe fallar');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('No hay perfil', $e->getMessage());
        }

        Fixture::insert();
        $this->get('/')->assertOk()->assertSee('Ana');
    }

    public function test_con_ttl_cero_no_hay_cache(): void
    {
        Fixture::insert();
        config(['security.content_cache_ttl' => 0]);

        $this->get('/')->assertOk();
        $this->assertGreaterThan(0, $this->queries(fn () => $this->get('/')->assertOk()));
    }
}
