<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Contador de visualizaciones de la portada, pensado para la privacidad:
 *  - `page_views` guarda solo totales por día (no hay registro por visita);
 *  - `daily_visitors` guarda un hash (IP + navegador + sal del día) únicamente para no contar dos veces al mismo visitante en el
 *    mismo día. La sal rota cada día y ambas tablas se purgan a los 2 días, así que el hash no permite seguir a nadie de un día a
 *    otro.
 * Cuenta personas, no máquinas: el controlador excluye robots, HEAD, "No rastrear" (DNT), Global Privacy Control y al propio
 * administrador (cookie `notrack`).
 */
class ViewCounter
{
    /** Robots, vistas previas de enlaces y clientes de scripts: no son personas leyendo la página. */
    private const BOT = '/bot|crawl|spider|slurp|preview|scanner|monitor|uptime|headless|lighthouse|pagespeed|curl|wget|python|httpclient|java\/|go-http|axios|node-fetch|okhttp|facebookexternalhit|whatsapp|telegram|discord|skype|embedly|pinterest|reddit/i';

    private const TOTAL_KEY = 'visits.total';

    private const TOTAL_TTL = 30;

    public function __construct(private readonly string $timeZone) {}

    public static function isBot(?string $userAgent): bool
    {
        return $userAgent === null || strlen($userAgent) < 10 || preg_match(self::BOT, $userAgent) === 1;
    }

    /** 1 → '1 visualización'; 1234 → '1,234 visualizaciones'. */
    public static function formatViews(int $n): string
    {
        return number_format($n).' '.($n === 1 ? 'visualización' : 'visualizaciones');
    }

    /** Suma días a 'AAAA-MM-DD' sin depender de la zona horaria del servidor. */
    public static function shiftDay(string $day, int $delta): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $day, 'UTC')->addDays($delta)->format('Y-m-d');
    }

    /** Día calendario ('AAAA-MM-DD') en la zona horaria configurada: el día depende de ella, no del servidor. */
    public function dayKey(?CarbonInterface $at = null): string
    {
        return ($at ? CarbonImmutable::instance($at) : CarbonImmutable::now())->setTimezone($this->timeZone)->format('Y-m-d');
    }

    /** Registra una visita. Un fallo de la base nunca debe afectar a la página: se informa y se sigue. */
    public function record(string $ip, string $userAgent): void
    {
        try {
            $day = $this->dayKey();
            $hash = hash('sha256', $this->saltFor($day)."|{$ip}|{$userAgent}");

            // Una sola sentencia (atómica): si el hash ya existía hoy suma una visualización; si no, también un visitante.
            // Con su propia transacción (punto de guardado si ya hay una): un fallo no deja abortada la de quien llama.
            DB::transaction(fn () => DB::statement(
                'with seen as (
                   insert into daily_visitors (day, hash) values (?, ?) on conflict do nothing returning 1
                 )
                 insert into page_views (day, views, visitors) values (?, 1, (select count(*)::int from seen))
                 on conflict (day) do update
                   set views = page_views.views + 1, visitors = page_views.visitors + excluded.visitors',
                [$day, $hash, $day],
            ));

            if (Cache::has(self::TOTAL_KEY)) {
                Cache::increment(self::TOTAL_KEY);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** La sal del día se crea en la primera visita del día; al hacerlo se borra lo de hace más de 2 días. */
    private function saltFor(string $day): string
    {
        $salt = DB::table('daily_salts')->where('day', $day)->value('salt');
        if ($salt !== null) {
            return $salt;
        }

        DB::statement('insert into daily_salts (day, salt) values (?, ?) on conflict (day) do nothing', [$day, bin2hex(random_bytes(16))]);
        $salt = DB::table('daily_salts')->where('day', $day)->value('salt');
        DB::statement('delete from daily_visitors where day < ?::date - 1', [$day]);
        DB::statement('delete from daily_salts where day < ?::date - 1', [$day]);

        return $salt;
    }

    /**
     * Total de visualizaciones, con caché corta para no consultar la base en cada carga. Devuelve null si la base no lo
     * puede dar: el contador nunca debe romper la portada.
     */
    public function total(): ?int
    {
        try {
            return (int) Cache::remember(self::TOTAL_KEY, self::TOTAL_TTL, fn () => (int) DB::transaction(fn () => DB::table('page_views')->sum('views')));
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Estadísticas para el panel.
     *
     * @return array{totalViews: int, totalVisitorDays: int, today: array{day: string, views: int, visitors: int},
     *               last7: array{views: int, visitors: int}, last30: array{views: int, visitors: int},
     *               series: list<array{day: string, views: int, visitors: int}>, maxDay: int}
     */
    public function stats(): array
    {
        $today = $this->dayKey();
        $recent = DB::table('page_views')
            ->whereRaw('day >= ?::date - 29', [$today])
            ->orderByDesc('day')
            ->get(['day', 'views', 'visitors'])
            ->keyBy('day');
        $totals = DB::table('page_views')->selectRaw('coalesce(sum(views), 0)::int as v, coalesce(sum(visitors), 0)::int as u')->first();

        $row = fn (string $day): array => [
            'day' => $day, 'views' => (int) ($recent[$day]->views ?? 0), 'visitors' => (int) ($recent[$day]->visitors ?? 0),
        ];
        $sum = function (int $days) use ($today, $row): array {
            $views = 0;
            $visitors = 0;
            for ($i = 0; $i < $days; $i++) {
                $d = $row(self::shiftDay($today, -$i));
                $views += $d['views'];
                $visitors += $d['visitors'];
            }

            return ['views' => $views, 'visitors' => $visitors];
        };
        $series = array_map(fn (int $i) => $row(self::shiftDay($today, -$i)), range(0, 13));

        return [
            'totalViews' => (int) $totals->v,
            'totalVisitorDays' => (int) $totals->u,
            'today' => $series[0],
            'last7' => $sum(7),
            'last30' => $sum(30),
            'series' => $series,
            'maxDay' => max(1, ...array_column($series, 'views')),
        ];
    }
}
