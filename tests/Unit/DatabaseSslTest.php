<?php

namespace Tests\Unit;

use App\Support\DatabaseSsl;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class DatabaseSslTest extends TestCase
{
    private const PEM = "-----BEGIN CERTIFICATE-----\nMIIBfake\nline2\n-----END CERTIFICATE-----";

    public function test_acepta_el_pem_en_varias_lineas_o_en_una_sola_con_n_literales_y_sin_valor_es_opcional(): void
    {
        $this->assertNull(DatabaseSsl::parseCa(null));
        $this->assertNull(DatabaseSsl::parseCa('   '));
        $this->assertSame(self::PEM, DatabaseSsl::parseCa(self::PEM));
        $this->assertSame(self::PEM, DatabaseSsl::parseCa(str_replace("\n", '\\n', self::PEM)), 'una línea con \\n literales se normaliza');
    }

    public function test_un_valor_que_no_es_un_certificado_falla_con_un_mensaje_claro(): void
    {
        foreach (['no soy un certificado', '-----BEGIN CERTIFICATE-----', 'https://supabase.com/ca.crt'] as $v) {
            try {
                DatabaseSsl::parseCa($v);
                $this->fail("debía rechazar: {$v}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('DATABASE_SSL_CA debe contener el certificado completo', $e->getMessage());
            }
        }
    }

    public function test_con_ca_se_quita_sslmode_de_la_url_que_de_otro_modo_pisaria_la_verificacion(): void
    {
        $url = 'postgres://postgres.abc:p%40ss@aws-0-us-west-1.pooler.supabase.com:5432/postgres?sslmode=require&application_name=x';

        $limpia = DatabaseSsl::withoutSslMode($url);

        $this->assertStringNotContainsString('sslmode', $limpia);
        $this->assertStringContainsString('application_name=x', $limpia, 'el resto de parámetros se conserva');
        $this->assertStringContainsString('postgres.abc:p%40ss@', $limpia, 'usuario y contraseña codificada intactos');
        $this->assertSame('postgres://u:p@h:5432/db', DatabaseSsl::withoutSslMode('postgres://u:p@h:5432/db?sslmode=require'));
        $this->assertSame('postgres://u:p@h:5432/db', DatabaseSsl::withoutSslMode('postgres://u:p@h:5432/db'));
        $this->assertNull(DatabaseSsl::withoutSslMode(null));
    }

    public function test_la_ca_se_escribe_una_sola_vez_en_un_archivo_segun_su_contenido(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ca-test-'.bin2hex(random_bytes(4));
        mkdir($dir);

        $a = DatabaseSsl::materialize(self::PEM, $dir);
        $b = DatabaseSsl::materialize(self::PEM, $dir);
        $otro = DatabaseSsl::materialize(self::PEM.'x', $dir);

        $this->assertSame($a, $b);
        $this->assertNotSame($a, $otro);
        $this->assertSame(self::PEM."\n", file_get_contents($a));

        array_map('unlink', glob($dir.DIRECTORY_SEPARATOR.'*'));
        rmdir($dir);
    }
}
