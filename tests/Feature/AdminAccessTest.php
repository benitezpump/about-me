<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login;
use App\Models\AdminUser;
use Illuminate\Auth\Events\Login as LoginEvent;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\Support\AsAdmin;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use AsAdmin, DatabaseTransactions;

    /** @return list<string> */
    private function protectedPaths(): array
    {
        return ['/admin', '/admin/perfil', '/admin/actualmente', '/admin/tecnologias', '/admin/herramientas', '/admin/experiencias',
            '/admin/proyectos', '/admin/materias', '/admin/talleres', '/admin/estudios', '/admin/grupos-de-certificaciones',
            '/admin/certificaciones', '/admin/enlaces-de-contacto', '/admin/cuenta', '/admin/proyectos/create', '/admin/proyectos/1/edit'];
    }

    public function test_sin_sesion_el_panel_y_todas_sus_rutas_redirigen_al_login(): void
    {
        foreach ($this->protectedPaths() as $path) {
            $this->get($path)->assertRedirect('/admin/login');
        }
    }

    public function test_el_login_pide_usuario_no_correo_y_no_ofrece_recordarme_ni_registro(): void
    {
        $html = $this->get('/admin/login')->assertOk()->getContent();

        $this->assertStringContainsString('Usuario', $html);
        $this->assertStringNotContainsString('type="email"', $html);
        $this->assertStringNotContainsString('Recuérdame', $html);
        $this->assertStringNotContainsString('/admin/register', $html);
        $this->assertStringNotContainsString('password-reset', $html);
        $this->get('/admin/register')->assertNotFound();
    }

    public function test_con_credenciales_correctas_inicia_sesion(): void
    {
        AdminUser::create(['username' => 'ana', 'password_hash' => Hash::make(self::ADMIN_PASSWORD)]);

        Livewire::test(Login::class)
            ->fillForm(['username' => 'ana', 'password' => self::ADMIN_PASSWORD])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $this->assertAuthenticated();
    }

    public function test_con_contrasena_o_usuario_incorrectos_no_revela_cual_fallo(): void
    {
        AdminUser::create(['username' => 'ana', 'password_hash' => Hash::make(self::ADMIN_PASSWORD)]);

        foreach ([['ana', 'otra contraseña muy larga'], ['nadie', self::ADMIN_PASSWORD]] as [$user, $pass]) {
            Livewire::test(Login::class)
                ->fillForm(['username' => $user, 'password' => $pass])
                ->call('authenticate')
                ->assertHasFormErrors(['username']);
            $this->assertGuest();
        }
    }

    public function test_un_hash_heredado_de_la_app_anterior_falla_con_normalidad_sin_lanzar_excepcion(): void
    {
        AdminUser::create(['username' => 'viejo', 'password_hash' => 'scrypt$32768$8$3$c2FsdA==$aGFzaA==']);

        Livewire::test(Login::class)
            ->fillForm(['username' => 'viejo', 'password' => self::ADMIN_PASSWORD])
            ->call('authenticate')
            ->assertHasFormErrors(['username']);
        $this->assertGuest();
    }

    public function test_limita_los_intentos_de_login_por_ip(): void
    {
        config(['security.login_max_attempts' => 3]);
        AdminUser::create(['username' => 'ana', 'password_hash' => Hash::make(self::ADMIN_PASSWORD)]);
        RateLimiter::clear(md5('App\\Filament\\Pages\\Auth\\Login|authenticate|127.0.0.1'));

        $intento = fn (string $pass) => Livewire::test(Login::class)
            ->fillForm(['username' => 'ana', 'password' => $pass])->call('authenticate');

        for ($i = 0; $i < 3; $i++) {
            $intento('mala contraseña larga')->assertHasFormErrors(['username']);
        }
        // El cuarto intento ya no llega a validar: ni siquiera la contraseña correcta entra.
        $intento(self::ADMIN_PASSWORD)->assertNoRedirect();
        $this->assertGuest();
    }

    public function test_al_iniciar_sesion_queda_la_cookie_notrack_para_no_contar_las_visitas_del_administrador(): void
    {
        $this->assertFalse(Cookie::hasQueued('notrack'));

        Auth::login(AdminUser::create(['username' => 'ana', 'password_hash' => Hash::make(self::ADMIN_PASSWORD)]));

        $this->assertTrue(Cookie::hasQueued('notrack'));
        $cookie = Cookie::queued('notrack');
        $this->assertSame('1', $cookie->getValue());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('/', $cookie->getPath());
        $this->assertGreaterThan(time() + 300 * 86400, $cookie->getExpiresTime());
        $this->assertSame('lax', $cookie->getSameSite());
    }

    public function test_con_sesion_todas_las_pantallas_responden(): void
    {
        $this->loginAsAdmin();
        \Tests\Support\Fixture::insert();

        // Con perfil existente no se puede "crear" otro: esa ruta responde 403 (se prueba en AdminContentTest).
        foreach (array_diff($this->protectedPaths(), ['/admin/proyectos/1/edit']) as $path) {
            $this->get($path)->assertSuccessful();
        }
        $this->get('/admin/proyectos/999999/edit')->assertNotFound();
    }

    public function test_el_panel_esta_en_espanol_y_no_pide_avatares_a_terceros(): void
    {
        $this->loginAsAdmin();
        \Tests\Support\Fixture::insert();

        $html = $this->get('/admin/proyectos')->assertOk()->getContent();

        $this->assertStringNotContainsString('ui-avatars.com', $html, 'el proveedor por defecto enviaría la inicial del usuario a un tercero');
        $this->assertStringContainsString('data:image/svg+xml;base64,', $html);
        $this->assertStringContainsString('Salir', $html);
        $this->assertStringNotContainsString('Sign out', $html);
    }

    public function test_el_evento_de_login_de_laravel_se_dispara_una_vez_por_inicio_de_sesion(): void
    {
        $veces = 0;
        \Illuminate\Support\Facades\Event::listen(LoginEvent::class, function () use (&$veces): void {
            $veces++;
        });

        Auth::login(AdminUser::create(['username' => 'ana', 'password_hash' => Hash::make(self::ADMIN_PASSWORD)]));

        $this->assertSame(1, $veces);
    }
}
