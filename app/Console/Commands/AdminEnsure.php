<?php

namespace App\Console\Commands;

use App\Models\AdminUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Se ejecuta al arrancar el contenedor: crea al administrador desde ADMIN_USERNAME y ADMIN_PASSWORD si no existe. No pisa una
 * contraseña que el administrador ya cambió desde el panel. La única excepción es un hash heredado de la app anterior
 * (scrypt), que Laravel no puede verificar: se reemplaza una vez con la contraseña de ADMIN_PASSWORD.
 */
class AdminEnsure extends Command
{
    protected $signature = 'admin:ensure';

    protected $description = 'Crea al administrador inicial desde ADMIN_USERNAME y ADMIN_PASSWORD si aún no existe';

    public function handle(): int
    {
        $username = trim((string) config('security.admin.username'));
        $plain = (string) config('security.admin.password');
        if ($username === '' || $plain === '') {
            $this->line('ADMIN_USERNAME y ADMIN_PASSWORD no están definidos: no se crea ningún administrador.');

            return self::SUCCESS;
        }
        if (mb_strlen($plain) < AdminCreate::MIN_PASSWORD_LENGTH) {
            $this->error('ADMIN_PASSWORD debe tener al menos '.AdminCreate::MIN_PASSWORD_LENGTH.' caracteres.');

            return self::FAILURE;
        }

        $user = AdminUser::where('username', $username)->first();
        if (! $user) {
            AdminUser::create(['username' => $username, 'password_hash' => Hash::make($plain)]);
            $this->info("Administrador inicial «{$username}» creado.");
        } elseif (! $user->hasUsableHash()) {
            $user->forceFill(['password_hash' => Hash::make($plain)])->save();
            $this->info("«{$username}» tenía una contraseña de la app anterior (no verificable en Laravel): se reemplazó con ADMIN_PASSWORD.");
        } else {
            $this->line("El administrador «{$username}» ya existe: no se cambia nada.");
        }

        return self::SUCCESS;
    }
}
