<?php

namespace Tests\Unit;

use App\Support\TrustProxy;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TrustProxyTest extends TestCase
{
    public function test_se_interpreta_como_booleano_numero_de_saltos_o_lista(): void
    {
        foreach ([null, '', '  ', 'false', 'FALSE', 'no', 'off', '0'] as $v) {
            $this->assertFalse(TrustProxy::parse($v), var_export($v, true));
        }
        foreach (['true', 'TRUE', 'yes', 'on'] as $v) {
            $this->assertTrue(TrustProxy::parse($v), $v);
        }
        $this->assertSame(1, TrustProxy::parse('1'));
        $this->assertSame(2, TrustProxy::parse(' 2 '));
        $this->assertSame(['10.0.0.0/8', '172.16.0.1'], TrustProxy::parse('10.0.0.0/8, 172.16.0.1'));
        $this->assertSame(['fd00::/8'], TrustProxy::parse('fd00::/8'));
    }

    /** @return array<string, array{string}> */
    public static function invalidos(): array
    {
        return array_combine(
            ['maybe', '1.5', '999.1.1.1', '10.0.0.0/33', '10.0.0.0/8/8', 'localhost', '10.0.0.0/8; drop', '-1'],
            array_map(fn ($v) => [$v], ['maybe', '1.5', '999.1.1.1', '10.0.0.0/33', '10.0.0.0/8/8', 'localhost', '10.0.0.0/8; drop', '-1']),
        );
    }

    #[DataProvider('invalidos')]
    public function test_un_valor_invalido_falla_al_arrancar_en_lugar_de_confiar_a_ciegas(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/TRUST_PROXY no es válido/');
        TrustProxy::parse($value);
    }

    /**
     * Escenario típico de Render: el visitante envía una cabecera falsa; Cloudflare añade la IP real que vio; el balanceador
     * de Render añade la IP de Cloudflare; la app recibe la conexión del balanceador.
     *   X-Forwarded-For: 6.6.6.6 (falsa), 1.1.1.1 (visitante real), 172.70.0.1 (Cloudflare)  ·  conexión: 10.0.0.1 (balanceador)
     */
    public function test_con_un_numero_de_saltos_se_ignora_la_ip_falsificada_con_true_no(): void
    {
        $xff = ['6.6.6.6', '1.1.1.1', '172.70.0.1'];

        $this->assertSame('10.0.0.1', TrustProxy::clientIp('10.0.0.1', $xff, false), 'sin proxy de confianza: la IP de la conexión');
        $this->assertSame('172.70.0.1', TrustProxy::clientIp('10.0.0.1', $xff, 1), '1 salto: el proxy anterior (prudente, pero no es el visitante)');
        $this->assertSame('1.1.1.1', TrustProxy::clientIp('10.0.0.1', $xff, 2), '2 saltos: el visitante real; lo falsificado se ignora');
        $this->assertSame('6.6.6.6', TrustProxy::clientIp('10.0.0.1', $xff, 3), 'confiar de más deja pasar la IP falsificada');
        $this->assertSame('6.6.6.6', TrustProxy::clientIp('10.0.0.1', $xff, 9), 'más saltos que eslabones: el primero');
        $this->assertSame('6.6.6.6', TrustProxy::clientIp('10.0.0.1', $xff, true), '`true` toma la IP más a la izquierda: el visitante la elige');
    }

    public function test_sin_cabecera_la_ip_es_la_de_la_conexion(): void
    {
        $this->assertSame('10.0.0.1', TrustProxy::clientIp('10.0.0.1', [''], 1));
        $this->assertSame('10.0.0.1', TrustProxy::clientIp('10.0.0.1', [], true));
    }
}
