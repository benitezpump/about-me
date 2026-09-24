<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Tests\Support\Fixture;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use DatabaseTransactions;

    /** Lo que ve la app como IP del visitante, dadas la conexión directa y X-Forwarded-For. */
    private function ipSeen(string|bool|int $trust, string $remote = '10.0.0.1', string $xff = '6.6.6.6, 1.1.1.1, 172.70.0.1'): string
    {
        config(['security.trust_proxy' => is_bool($trust) ? ($trust ? 'true' : 'false') : (string) $trust]);
        Route::get('/_ip', fn (Request $r) => $r->ip());

        return $this->withServerVariables(['REMOTE_ADDR' => $remote])->get('/_ip', ['X-Forwarded-For' => $xff])->getContent();
    }

    public function test_con_un_numero_de_saltos_se_ignora_la_ip_falsificada_con_true_no(): void
    {
        $this->assertSame('10.0.0.1', $this->ipSeen(false));
        $this->assertSame('172.70.0.1', $this->ipSeen(1));
        $this->assertSame('1.1.1.1', $this->ipSeen(2));
        $this->assertSame('6.6.6.6', $this->ipSeen(3));
        $this->assertSame('6.6.6.6', $this->ipSeen(true), '`true` toma la IP más a la izquierda: un visitante puede elegirla');
    }

    public function test_una_lista_de_proxies_de_confianza_se_comporta_como_los_saltos_que_cubre(): void
    {
        $this->assertSame('1.1.1.1', $this->ipSeen('10.0.0.0/8, 172.70.0.0/16'));
        $this->assertSame('172.70.0.1', $this->ipSeen('10.0.0.0/8'));
    }

    public function test_el_esquema_https_del_proxy_se_respeta_solo_si_se_confia_en_el(): void
    {
        Route::get('/_secure', fn (Request $r) => $r->isSecure() ? 'https' : 'http');

        // URL absoluta: el helper de pruebas arma las siguientes con el esquema de la petición anterior.
        config(['security.trust_proxy' => '1']);
        $this->assertSame('https', $this->get('http://localhost/_secure', ['X-Forwarded-Proto' => 'https'])->getContent());

        config(['security.trust_proxy' => 'false']);
        $this->assertSame('http', $this->get('http://localhost/_secure', ['X-Forwarded-Proto' => 'https'])->getContent(), 'sin confianza, la cabecera se ignora');
    }

    public function test_un_valor_invalido_de_trust_proxy_falla_al_arrancar(): void
    {
        config(['security.trust_proxy' => 'localhost']);

        $this->expectException(InvalidArgumentException::class);
        (new AppServiceProvider($this->app))->boot();
    }

    public function test_el_sitio_publico_lleva_csp_estricta_y_las_cabeceras_de_endurecimiento(): void
    {
        Fixture::insert();
        $r = $this->get('/');

        $csp = (string) $r->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("script-src 'self'", $csp);
        $this->assertStringNotContainsString('unsafe-inline', $csp);
        $this->assertStringNotContainsString('unsafe-eval', $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $r->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $this->assertFalse($r->headers->has('X-Powered-By'));
    }

    public function test_hsts_solo_con_cookies_seguras(): void
    {
        Fixture::insert();

        config(['session.secure' => false]);
        $this->assertFalse($this->get('/')->headers->has('Strict-Transport-Security'), 'en http local un HSTS dejaría al navegador forzando https');

        config(['session.secure' => true]);
        $this->assertStringContainsString('max-age=15552000', (string) $this->get('/')->headers->get('Strict-Transport-Security'));
    }

    public function test_el_panel_relaja_solo_script_src_y_style_src_por_livewire_y_alpine(): void
    {
        $csp = (string) $this->get('/admin/login')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("script-src 'self' 'unsafe-inline' 'unsafe-eval'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
    }
}
