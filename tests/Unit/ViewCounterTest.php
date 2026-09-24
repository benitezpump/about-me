<?php

namespace Tests\Unit;

use App\Services\ViewCounter;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Portada de "visitas" de tests/units.test.ts (versión Node). */
class ViewCounterTest extends TestCase
{
    public function test_formato_singular_y_plural(): void
    {
        $this->assertSame('1 visualización', ViewCounter::formatViews(1));
        $this->assertSame('0 visualizaciones', ViewCounter::formatViews(0));
        $this->assertSame('1,234 visualizaciones', ViewCounter::formatViews(1234));
    }

    /** @return array<string, array{?string}> */
    public static function robots(): array
    {
        $bots = ['Googlebot/2.1', 'LinkedInBot/1.0', 'curl/8.4.0', 'WhatsApp/2.23', 'Lighthouse', '', 'python-requests/2.31',
            'Mozilla/5.0 (compatible; UptimeRobot/2.0)', 'facebookexternalhit/1.1', 'Slackbot-LinkExpanding 1.0', 'HeadlessChrome/120'];

        return array_combine(array_map(fn ($b) => $b === '' ? '(vacío)' : $b, $bots), array_map(fn ($b) => [$b], $bots)) + ['(sin user-agent)' => [null]];
    }

    #[DataProvider('robots')]
    public function test_detecta_robots(?string $userAgent): void
    {
        $this->assertTrue(ViewCounter::isBot($userAgent));
    }

    public function test_un_navegador_real_no_es_un_robot(): void
    {
        $this->assertFalse(ViewCounter::isBot('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36'));
        $this->assertFalse(ViewCounter::isBot('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1'));
    }

    public function test_el_dia_depende_de_la_zona_horaria_no_del_servidor(): void
    {
        $at = CarbonImmutable::parse('2026-09-23T03:30:00Z'); // 20:30 del 22 en Hermosillo (UTC-7); ya es 23 en UTC

        $this->assertSame('2026-09-22', (new ViewCounter('America/Hermosillo'))->dayKey($at));
        $this->assertSame('2026-09-23', (new ViewCounter('UTC'))->dayKey($at));
    }

    public function test_sumar_dias_cruza_meses_y_anios(): void
    {
        $this->assertSame('2026-02-28', ViewCounter::shiftDay('2026-03-01', -1));
        $this->assertSame('2025-12-31', ViewCounter::shiftDay('2026-01-01', -1));
        $this->assertSame('2024-02-29', ViewCounter::shiftDay('2024-03-01', -1));
        $this->assertSame('2026-03-01', ViewCounter::shiftDay('2026-02-28', 1));
    }
}
