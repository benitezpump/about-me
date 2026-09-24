<?php

namespace App\Console\Commands;

use App\Models\AdminUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

use function Laravel\Prompts\password;

/**
 * Crea al administrador, o con --reset cambia su contraseña (y cierra todas sus sesiones abiertas).
 * La contraseña puede venir de --password, de ADMIN_PASSWORD o pedirse de forma interactiva (sin mostrarla).
 */
class AdminCreate extends Command
{
    public const MIN_PASSWORD_LENGTH = 12;

    protected $signature = 'admin:create {username? : Usuario (por omisión ADMIN_USERNAME)}
                            {--password= : Contraseña (por omisión ADMIN_PASSWORD o se pide)}
                            {--reset : Si el usuario existe, cambia su contraseña y cierra sus sesiones}';

    protected $description = 'Crea al administrador del panel, o restablece su contraseña con --reset';

    public function handle(): int
    {
        $username = trim((string) ($this->argument('username') ?: config('security.admin.username')));
        if ($username === '') {
            $this->error('Indica el usuario: php artisan admin:create <usuario>.');

            return self::FAILURE;
        }

        $plain = (string) ($this->option('password') ?: config('security.admin.password'));
        if ($plain === '') {
            $plain = password('Contraseña (mínimo '.self::MIN_PASSWORD_LENGTH.' caracteres)', required: true);
        }
        if (mb_strlen($plain) < self::MIN_PASSWORD_LENGTH) {
            $this->error('La contraseña debe tener al menos '.self::MIN_PASSWORD_LENGTH.' caracteres.');

            return self::FAILURE;
        }

        $existing = AdminUser::where('username', $username)->first();
        if ($existing && ! $this->option('reset')) {
            $this->error("El usuario «{$username}» ya existe. Usa --reset para cambiar su contraseña.");

            return self::FAILURE;
        }

        if ($existing) {
            $existing->forceFill(['password_hash' => Hash::make($plain)])->save();
            DB::table(config('session.table'))->where('user_id', $existing->getKey())->delete(); // cierra sus sesiones
            $this->info("Contraseña de «{$username}» cambiada. Se cerraron sus sesiones.");

            return self::SUCCESS;
        }

        AdminUser::create(['username' => $username, 'password_hash' => Hash::make($plain)]);
        $this->info("Administrador «{$username}» creado.");

        return self::SUCCESS;
    }
}
