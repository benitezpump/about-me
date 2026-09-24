<?php

namespace Tests\Feature;

use App\Filament\Resources\Profiles\Pages\EditProfile;
use App\Models\AdminUser;
use App\Providers\AppServiceProvider;
use App\Services\ViewCounter;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\Support\AsAdmin;
use Tests\Support\Fixture;
use Tests\TestCase;

/** Portada de "contador de visualizaciones" (Node): cuenta personas, no máquinas, y no guarda nada identificable. */
class ViewCounterTest extends TestCase
{
    use AsAdmin, DatabaseTransactions;

    private const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36';

    private const OTHER = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Version/17.0 Safari/605.1.15';

    protected function setUp(): void
    {
        parent::setUp();
        Fixture::insert();
    }

    /** Una visita a la portada. @param array<string, string> $headers */
    private function visit(array $headers, string $ip = '203.0.113.7', string $method = 'GET'): string
    {
        $server = ['REMOTE_ADDR' => $ip];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call($method, '/', [], [], [], $server)->assertOk()->getContent();
    }

    /** @return array{v: int, u: int} */
    private function counts(): array
    {
        return ['v' => (int) DB::table('page_views')->sum('views'), 'u' => (int) DB::table('page_views')->sum('visitors')];
    }

    private function labelOf(string $html): int
    {
        preg_match('#class="views">.*?</svg>([^<]+)<#s', $html, $m);

        return (int) preg_replace('/\D/', '', $m[1] ?? '');
    }

    public function test_cuenta_una_visita_y_no_repite_al_mismo_visitante_como_visitante_unico(): void
    {
        $start = $this->counts();

        $first = $this->visit(['User-Agent' => self::BROWSER]);
        $second = $this->visit(['User-Agent' => self::BROWSER]);

        $end = $this->counts();
        $this->assertSame(2, $end['v'] - $start['v'], 'dos visualizaciones');
        $this->assertSame(1, $end['u'] - $start['u'], 'un solo visitante único');
        // La página que recibe la persona ya la incluye a ella.
        $this->assertSame($start['v'] + 1, $this->labelOf($first));
        $this->assertSame($start['v'] + 2, $this->labelOf($second));
    }

    public function test_otro_visitante_otra_ip_u_otro_navegador_si_es_un_visitante_unico_mas(): void
    {
        $start = $this->counts();

        $this->visit(['User-Agent' => self::BROWSER], '198.51.100.9');
        $this->visit(['User-Agent' => self::OTHER]);

        $end = $this->counts();
        $this->assertSame(2, $end['v'] - $start['v']);
        $this->assertSame(2, $end['u'] - $start['u']);
    }

    public function test_no_cuenta_robots_head_no_rastrear_global_privacy_control_ni_peticiones_sin_navegador(): void
    {
        $start = $this->counts();

        foreach (['Googlebot/2.1 (+http://www.google.com/bot.html)', 'LinkedInBot/1.0', 'curl/8.4.0', 'python-requests/2.31', 'Mozilla/5.0 (compatible; UptimeRobot/2.0)'] as $ua) {
            $this->visit(['User-Agent' => $ua]);
        }
        $this->visit(['User-Agent' => '']);
        $this->visit(['User-Agent' => self::BROWSER, 'DNT' => '1']);
        $this->visit(['User-Agent' => self::BROWSER, 'Sec-GPC' => '1']);
        $this->call('HEAD', '/', [], [], [], ['REMOTE_ADDR' => '203.0.113.50', 'HTTP_USER_AGENT' => self::BROWSER]);

        $this->assertSame($start, $this->counts());
    }

    public function test_dnt_con_valor_distinto_de_1_no_impide_contar(): void
    {
        $start = $this->counts();

        $this->visit(['User-Agent' => self::BROWSER, 'DNT' => '0']);

        $this->assertSame($start['v'] + 1, $this->counts()['v']);
    }

    public function test_iniciar_sesion_deja_una_marca_para_que_las_visitas_del_administrador_no_cuenten(): void
    {
        $this->assertTrue(app('events')->hasListeners(Login::class), 'el evento de login tiene quien lo escuche');
        Auth::login(AdminUser::create(['username' => 'ana', 'password_hash' => Hash::make('una contraseña larga y segura')]));
        $this->assertSame('1', \Illuminate\Support\Facades\Cookie::queued('notrack')->getValue());

        $start = $this->counts();
        $this->call('GET', '/', [], ['notrack' => '1'], [], ['REMOTE_ADDR' => '203.0.113.99', 'HTTP_USER_AGENT' => self::BROWSER])->assertOk();

        $this->assertSame($start, $this->counts());
    }

    public function test_guarda_solo_un_hash_nunca_la_ip_ni_el_navegador(): void
    {
        $this->visit(['User-Agent' => self::BROWSER], '192.0.2.123');

        $hashes = DB::table('daily_visitors')->pluck('hash')->all();
        $this->assertNotEmpty($hashes);
        foreach ($hashes as $h) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $h);
            $this->assertStringNotContainsString('192.0.2.123', $h);
        }
        $cols = DB::table('information_schema.columns')->whereIn('table_name', ['page_views', 'daily_visitors', 'daily_salts'])->pluck('column_name')->all();
        $this->assertSame([], array_values(array_filter($cols, fn ($c) => preg_match('/ip|agent|address/i', $c) === 1)), 'columnas: '.implode(', ', $cols));
    }

    public function test_el_pie_muestra_el_total_y_se_puede_ocultar(): void
    {
        $this->visit(['User-Agent' => self::BROWSER]);
        $total = $this->counts()['v'];
        Cache::forget('visits.total');

        $shown = $this->visit(['User-Agent' => '']); // no cuenta
        $this->assertMatchesRegularExpression('/visualizaci(ón|ones)$/', trim(strip_tags(preg_replace('#.*class="views">.*?</svg>([^<]+)<.*#s', '$1', $shown))));
        $this->assertSame($total, $this->labelOf($shown));

        DB::table('profile')->update(['show_view_count' => false]);
        \App\Services\SiteContent::forget();
        $this->assertStringNotContainsString('class="views"', $this->visit(['User-Agent' => '']));
    }

    public function test_el_interruptor_del_perfil_funciona_desde_el_panel(): void
    {
        $this->loginAsAdmin();

        Livewire::test(EditProfile::class)->fillForm(['show_view_count' => false])->call('save')->assertHasNoFormErrors();
        $this->assertStringNotContainsString('class="views"', $this->visit(['User-Agent' => '']));

        Livewire::test(EditProfile::class)->fillForm(['show_view_count' => true])->call('save')->assertHasNoFormErrors();
        $this->assertStringContainsString('class="views"', $this->visit(['User-Agent' => '']));
    }

    public function test_purga_los_datos_de_identificacion_de_hace_mas_de_2_dias(): void
    {
        $counter = app(ViewCounter::class);
        $hoy = $counter->dayKey();
        foreach ([-3, -2, -1] as $delta) {
            $dia = ViewCounter::shiftDay($hoy, $delta);
            DB::table('daily_salts')->insert(['day' => $dia, 'salt' => 'sal']);
            DB::table('daily_visitors')->insert(['day' => $dia, 'hash' => str_repeat('a', 64)]);
        }

        $this->visit(['User-Agent' => self::BROWSER]); // la primera visita del día crea la sal y purga

        $this->assertSame([ViewCounter::shiftDay($hoy, -1), $hoy], DB::table('daily_salts')->orderBy('day')->pluck('day')->all(), 'quedan ayer y hoy');
        $this->assertSame([ViewCounter::shiftDay($hoy, -1), $hoy], DB::table('daily_visitors')->orderBy('day')->pluck('day')->all());
    }

    public function test_un_fallo_de_la_base_al_contar_nunca_rompe_la_pagina(): void
    {
        DB::statement('alter table page_views rename to page_views_x');

        $this->call('GET', '/', [], [], [], ['REMOTE_ADDR' => '203.0.113.7', 'HTTP_USER_AGENT' => self::BROWSER])
            ->assertOk()->assertSee('Ana');
    }

    public function test_las_estadisticas_suman_los_dias_y_rellenan_con_ceros(): void
    {
        $counter = app(ViewCounter::class);
        $hoy = $counter->dayKey();
        DB::table('page_views')->insert([
            ['day' => $hoy, 'views' => 5, 'visitors' => 3],
            ['day' => ViewCounter::shiftDay($hoy, -2), 'views' => 10, 'visitors' => 6],
            ['day' => ViewCounter::shiftDay($hoy, -10), 'views' => 20, 'visitors' => 9],
            ['day' => ViewCounter::shiftDay($hoy, -40), 'views' => 100, 'visitors' => 50],
        ]);

        $s = $counter->stats();

        $this->assertSame(135, $s['totalViews']);
        $this->assertSame(['day' => $hoy, 'views' => 5, 'visitors' => 3], $s['today']);
        $this->assertSame(['views' => 15, 'visitors' => 9], $s['last7']);
        $this->assertSame(['views' => 35, 'visitors' => 18], $s['last30'], 'lo de hace 40 días no cuenta en 30');
        $this->assertCount(14, $s['series']);
        $this->assertSame(0, $s['series'][1]['views'], 'un día sin visitas sale en cero');
        $this->assertSame(20, $s['maxDay']);
    }

    public function test_una_base_sin_visitas_da_ceros_y_maximo_uno_para_escalar(): void
    {
        $s = app(ViewCounter::class)->stats();

        $this->assertSame([0, 0, 1], [$s['totalViews'], $s['today']['views'], $s['maxDay']]);
    }

    public function test_una_zona_horaria_invalida_falla_al_arrancar(): void
    {
        config(['security.stats_timezone' => 'Marte/Olimpo']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('STATS_TIMEZONE no es una zona horaria válida');
        (new AppServiceProvider($this->app))->boot();
    }

    public function test_el_panel_muestra_las_estadisticas_y_el_grafico_de_14_dias(): void
    {
        $this->loginAsAdmin();
        $hoy = app(ViewCounter::class)->dayKey();
        DB::table('page_views')->insert([
            ['day' => $hoy, 'views' => 5, 'visitors' => 3],
            ['day' => ViewCounter::shiftDay($hoy, -2), 'views' => 10, 'visitors' => 6],
        ]);

        // El panel carga los widgets de forma diferida: cada uno se monta por su cuenta.
        $this->get('/admin')->assertOk();

        Livewire::test(\App\Filament\Widgets\VisitStats::class)
            ->assertSee('Visualizaciones en total')->assertSee('15')
            ->assertSee('Hoy')->assertSee('3 visitantes')
            ->assertSee('Últimos 7 días')->assertSee('9 visitantes')
            ->assertSee('Últimos 30 días')
            ->assertSee('No cuenta robots');

        $chart = Livewire::test(\App\Filament\Widgets\VisitsChart::class)->assertSee('Últimos 14 días');
        $data = (fn () => $this->getData())->call($chart->instance());
        $this->assertCount(14, $data['labels']);
        $this->assertSame([0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 10, 0, 5], $data['datasets'][0]['data'], 'del más antiguo al más reciente');
        $this->assertSame([0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 6, 0, 3], $data['datasets'][1]['data']);
    }

    public function test_el_panel_no_muestra_los_widgets_de_bienvenida_por_defecto(): void
    {
        $this->loginAsAdmin();

        $html = $this->get('/admin')->assertOk()->getContent();

        $this->assertStringNotContainsString('filament-info-widget', $html);
        $this->assertStringNotContainsString('account-widget', $html);
    }
}
