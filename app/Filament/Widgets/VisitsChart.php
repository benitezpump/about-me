<?php

namespace App\Filament\Widgets;

use App\Services\ViewCounter;
use Filament\Widgets\ChartWidget;

/** Visualizaciones y visitantes únicos de los últimos 14 días, del más antiguo al más reciente. */
class VisitsChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected ?string $heading = 'Últimos 14 días';

    protected int|string|array $columnSpan = 'full';

    protected function getType(): string
    {
        return 'bar';
    }

    /** @return array<string, mixed> */
    protected function getData(): array
    {
        $series = array_reverse(app(ViewCounter::class)->stats()['series']);

        return [
            'datasets' => [
                ['label' => 'Visualizaciones', 'data' => array_column($series, 'views')],
                ['label' => 'Visitantes únicos', 'data' => array_column($series, 'visitors')],
            ],
            'labels' => array_map(fn (array $d) => substr($d['day'], 5), $series),
        ];
    }
}
