<?php

namespace Tests\Unit;

use App\Support\Format;
use PHPUnit\Framework\TestCase;

class FormatTest extends TestCase
{
    public function test_fechas_en_texto(): void
    {
        $this->assertSame('02/2023', Format::monthYear('2023-02-01'));
        $this->assertSame('marzo de 2015', Format::longMonthYear('2015-03-01'));
        $this->assertSame('diciembre de 2020', Format::longMonthYear('2020-12-31'));
    }

    public function test_periodo_con_fin_en_curso_y_con_texto_libre(): void
    {
        $cerrado = Format::period('2021-03-01', '2022-11-01');
        $this->assertSame(['03/2021', '11/2022', false, null], [$cerrado->from, $cerrado->to, $cerrado->current, $cerrado->label]);

        $enCurso = Format::period('2023-02-01', null);
        $this->assertTrue($enCurso->current);
        $this->assertNull($enCurso->to);

        // Con texto libre sustituye al periodo y deja de contar como "actualidad", aunque no tenga fin.
        $libre = Format::period('2022-07-01', null, '  21 jul – 19 oct 2022  ');
        $this->assertSame('21 jul – 19 oct 2022', $libre->label);
        $this->assertFalse($libre->current);

        $this->assertNull(Format::period('2022-07-01', null, '   ')->label, 'un texto en blanco no cuenta');
    }

    public function test_iniciales_con_acentos_y_espacios_de_mas(): void
    {
        $this->assertSame('AP', Format::initials('Ana Prueba'));
        $this->assertSame('ÁB', Format::initials('  álvaro   Benítez  Ramírez '));
        $this->assertSame('X', Format::initials('x'));
        $this->assertSame('', Format::initials('   '));
    }

    public function test_solo_los_enlaces_http_se_abren_en_pestana_nueva(): void
    {
        $this->assertTrue(Format::isHttp('https://a.com'));
        $this->assertTrue(Format::isHttp('HTTP://a.com'));
        $this->assertFalse(Format::isHttp('mailto:a@b.co'));
        $this->assertFalse(Format::isHttp('tel:+526421234567'));
        $this->assertFalse(Format::isHttp(null));
    }

    public function test_texto_visible_de_un_enlace(): void
    {
        $this->assertSame('github.com/ana', Format::displayUrl('https://www.github.com/ana/'));
        $this->assertSame('ana@example.com', Format::displayUrl('mailto:ana@example.com'));
        $this->assertSame('+526421234567', Format::displayUrl('tel:+526421234567'));
        $this->assertSame('', Format::displayUrl(null));
    }

    public function test_las_tecnologias_salen_del_catalogo_una_por_etiqueta_con_su_nota(): void
    {
        $this->assertSame(
            ['PHP', 'Laravel', 'Browsershot (generación de PDF)'],
            Format::stackItems([
                ['name' => 'PHP', 'note' => null], ['name' => 'Laravel', 'note' => ''],
                ['name' => 'Browsershot', 'note' => 'generación de PDF'],
            ]),
        );
        $this->assertSame([], Format::stackItems([]));
    }
}
