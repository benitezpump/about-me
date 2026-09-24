<?php

namespace Tests\Feature;

use App\Filament\Pages\Cuenta;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\Support\AsAdmin;
use Tests\TestCase;

class AdminAccountTest extends TestCase
{
    use AsAdmin, DatabaseTransactions;

    private const NUEVA = 'otra contraseña bien larga';

    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAsAdmin();
    }

    public function test_cambia_la_contrasena_y_cierra_las_otras_sesiones(): void
    {
        $antes = $this->admin->password_hash;
        DB::table('web_sessions')->insert([
            ['id' => 'otra-sesion', 'user_id' => $this->admin->id, 'payload' => 'x', 'last_activity' => time()],
            ['id' => 'ajena', 'user_id' => $this->admin->id + 999, 'payload' => 'x', 'last_activity' => time()],
        ]);

        Livewire::test(Cuenta::class)
            ->fillForm(['current' => self::ADMIN_PASSWORD, 'next' => self::NUEVA, 'confirm' => self::NUEVA])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('La contraseña se cambió. Se cerraron tus otras sesiones.');

        $hash = $this->admin->fresh()->password_hash;
        $this->assertNotSame($antes, $hash);
        $this->assertTrue(Hash::check(self::NUEVA, $hash));
        $this->assertFalse(Hash::check(self::ADMIN_PASSWORD, $hash));
        $this->assertNull(DB::table('web_sessions')->where('id', 'otra-sesion')->first(), 'las otras sesiones del administrador se cierran');
        $this->assertNotNull(DB::table('web_sessions')->where('id', 'ajena')->first(), 'las de otro usuario no se tocan');
    }

    public function test_exige_la_contrasena_actual(): void
    {
        Livewire::test(Cuenta::class)
            ->fillForm(['current' => 'no es esta contraseña', 'next' => self::NUEVA, 'confirm' => self::NUEVA])
            ->call('save')
            ->assertHasFormErrors(['current']);

        $this->assertTrue(Hash::check(self::ADMIN_PASSWORD, $this->admin->fresh()->password_hash));
    }

    public function test_la_nueva_debe_tener_doce_caracteres_ser_distinta_y_coincidir_con_su_confirmacion(): void
    {
        $intento = fn (array $datos) => Livewire::test(Cuenta::class)->fillForm($datos)->call('save');

        $intento(['current' => self::ADMIN_PASSWORD, 'next' => 'corta', 'confirm' => 'corta'])->assertHasFormErrors(['next']);
        $intento(['current' => self::ADMIN_PASSWORD, 'next' => self::ADMIN_PASSWORD, 'confirm' => self::ADMIN_PASSWORD])->assertHasFormErrors(['next']);
        $intento(['current' => self::ADMIN_PASSWORD, 'next' => self::NUEVA, 'confirm' => self::NUEVA.'x'])->assertHasFormErrors(['confirm']);

        $this->assertTrue(Hash::check(self::ADMIN_PASSWORD, $this->admin->fresh()->password_hash), 'no cambió nada');
    }
}
