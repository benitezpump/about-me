<?php

namespace App\Filament\Widgets;

use App\Services\ViewCounter;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Visitas a la portada: total, hoy y los últimos 7 y 30 días (con visitantes únicos por día). */
class VisitStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $heading = 'Visitas a la portada';

    protected ?string $description = 'Entre paréntesis, visitantes únicos. No cuenta robots, tus propias visitas (este navegador quedó marcado al iniciar sesión) ni a quien envía "No rastrear". Una persona que vuelve otro día cuenta otra vez: no se guarda nada que permita reconocerla de un día a otro.';

    /** @return array<int, Stat> */
    protected function getStats(): array
    {
        $s = app(ViewCounter::class)->stats();
        $visitors = fn (int $n): string => number_format($n).' '.($n === 1 ? 'visitante' : 'visitantes');

        return [
            Stat::make('Visualizaciones en total', number_format($s['totalViews'])),
            Stat::make('Hoy', number_format($s['today']['views']))->description($visitors($s['today']['visitors'])),
            Stat::make('Últimos 7 días', number_format($s['last7']['views']))->description($visitors($s['last7']['visitors'])),
            Stat::make('Últimos 30 días', number_format($s['last30']['views']))->description($visitors($s['last30']['visitors'])),
        ];
    }
}
