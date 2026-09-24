<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminCommandsTest extends TestCase
{
    use DatabaseTransactions;

    private const PASS = 'una contraseña de doce o más';

    public function test_crea_al_administrador_con_la_contrasena_cifrada(): void
    {
        $this->artisan('admin:create', ['username' => 'ana', '--password' => self::PASS])
            ->expectsOutputToContain('Administrador «ana» creado')->assertSuccessful();

        $ana = AdminUser::where('username', 'ana')->firstOrFail();
        $this->assertNotSame(self::PASS, $ana->password_hash);
        $this->assertTrue(Hash::check(self::PASS, $ana->password_hash));
        $this->assertTrue($ana->hasUsableHash());
    }

    public function test_rechaza_una_contrasena_corta_y_un_usuario_repetido(): void
    {
        $this->artisan('admin:create', ['username' => 'ana', '--password' => 'corta'])
            ->expectsOutputToContain('al menos 12 caracteres')->assertFailed();
        $this->assertSame(0, AdminUser::count());

        $this->artisan('admin:create', ['username' => 'ana', '--password' => self::PASS])->assertSuccessful();
        $this->artisan('admin:create', ['username' => 'ana', '--password' => self::PASS.'2'])
            ->expectsOutputToContain('ya existe')->assertFailed();
        $this->assertTrue(Hash::check(self::PASS, AdminUser::firstOrFail()->password_hash), 'no se pisó la contraseña');
    }

    public function test_con_reset_cambia_la_contrasena_y_cierra_las_sesiones(): void
    {
        $this->artisan('admin:create', ['username' => 'ana', '--password' => self::PASS])->assertSuccessful();
        $id = AdminUser::firstOrFail()->id;
        DB::table('web_sessions')->insert(['id' => 's1', 'user_id' => $id, 'payload' => 'x', 'last_activity' => time()]);
        DB::table('web_sessions')->insert(['id' => 's2', 'user_id' => $id + 999, 'payload' => 'x', 'last_activity' => time()]);

        $this->artisan('admin:create', ['username' => 'ana', '--password' => 'otra contraseña larga', '--reset' => true])
            ->expectsOutputToContain('Se cerraron sus sesiones')->assertSuccessful();

        $this->assertTrue(Hash::check('otra contraseña larga', AdminUser::firstOrFail()->password_hash));
        $this->assertSame(['s2'], DB::table('web_sessions')->pluck('id')->all(), 'solo se cierran las del administrador');
    }

    public function test_ensure_crea_al_administrador_inicial_desde_el_entorno_solo_si_no_existe(): void
    {
        config(['security.admin' => ['username' => 'ana', 'password' => self::PASS]]);

        $this->artisan('admin:ensure')->expectsOutputToContain('creado')->assertSuccessful();
        $this->assertSame(1, AdminUser::count());

        // Si el administrador cambió su contraseña desde el panel, arrancar de nuevo no la pisa.
        AdminUser::firstOrFail()->forceFill(['password_hash' => Hash::make('la que cambié desde el panel')])->save();
        $this->artisan('admin:ensure')->expectsOutputToContain('ya existe')->assertSuccessful();
        $this->assertTrue(Hash::check('la que cambié desde el panel', AdminUser::firstOrFail()->password_hash));
    }

    public function test_ensure_reemplaza_un_hash_heredado_de_la_app_anterior_una_sola_vez(): void
    {
        AdminUser::create(['username' => 'ana', 'password_hash' => 'scrypt$32768$8$3$c2FsdA==$aGFzaA==']);
        config(['security.admin' => ['username' => 'ana', 'password' => self::PASS]]);

        $this->artisan('admin:ensure')->expectsOutputToContain('de la app anterior')->assertSuccessful();

        $ana = AdminUser::firstOrFail();
        $this->assertTrue($ana->hasUsableHash());
        $this->assertTrue(Hash::check(self::PASS, $ana->password_hash));
    }

    public function test_ensure_sin_variables_no_hace_nada_y_con_contrasena_corta_falla(): void
    {
        config(['security.admin' => ['username' => null, 'password' => null]]);
        $this->artisan('admin:ensure')->expectsOutputToContain('no están definidos')->assertSuccessful();
        $this->assertSame(0, AdminUser::count());

        config(['security.admin' => ['username' => 'ana', 'password' => 'corta']]);
        $this->artisan('admin:ensure')->expectsOutputToContain('al menos 12')->assertFailed();
    }
}
