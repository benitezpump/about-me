<?php

namespace Tests\Unit;

use App\Casts\PgTextArray;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PgTextArrayTest extends TestCase
{
    public function test_lee_literales_de_postgresql(): void
    {
        $this->assertSame([], PgTextArray::parse('{}'));
        $this->assertSame([], PgTextArray::parse(''));
        $this->assertSame(['uno', 'dos'], PgTextArray::parse('{uno,dos}'));
        $this->assertSame(['uno', null], PgTextArray::parse('{"uno",NULL}'));
        $this->assertSame(['a, b', 'c "d"', 'e\\f'], PgTextArray::parse('{"a, b","c \\"d\\"","e\\\\f"}'));
    }

    /** @return array<string, array{list<string>}> */
    public static function ida_y_vuelta(): array
    {
        return [
            'vacío' => [[]],
            'simple' => [['Primer párrafo.', 'Segundo párrafo.']],
            'comas y comillas' => [['Con, coma', 'Con "comillas"', "Con 'apóstrofo'"]],
            'barras' => [['C:\\ruta\\archivo', 'a\\"b']],
            'unicode' => [['Benítez Ramírez', '日本語', 'emoji 🙂']],
            'saltos de línea' => [["línea 1\nlínea 2"]],
            'llaves y espacios' => [['{no es un arreglo}', '  espacios  ']],
        ];
    }

    /** @param list<string> $items */
    #[DataProvider('ida_y_vuelta')]
    public function test_escribir_y_volver_a_leer_no_pierde_nada(array $items): void
    {
        $this->assertSame($items, PgTextArray::parse(PgTextArray::literal($items)));
    }
}
