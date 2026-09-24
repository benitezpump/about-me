<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * El administrador del sitio. Un solo usuario, sin roles (alcance del panel: docs/decisions/0001). Usa la tabla existente
 * `admin_users` (username, password_hash, created_at), que la app anterior ya tenía.
 *
 * Contraseñas: Laravel guarda bcrypt. La app Node guardaba scrypt con su propio formato y PHP no puede verificarlo sin una
 * implementación de scrypt en PHP (muy lenta con esos parámetros), así que un hash heredado no sirve: `admin:ensure` lo
 * reemplaza una sola vez con la contraseña de ADMIN_PASSWORD. Con `hashing.verify = false` un hash heredado hace que el login
 * falle con normalidad en lugar de lanzar una excepción.
 */
class AdminUser extends Authenticatable implements FilamentUser, HasName
{
    public $timestamps = false;

    protected $table = 'admin_users';

    protected $guarded = [];

    protected $hidden = ['password_hash'];

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    /** Sin "recordarme": la sesión dura lo que dice SESSION_LIFETIME y no hay columna `remember_token`. */
    public function getRememberTokenName(): string
    {
        return '';
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    public function getFilamentName(): string
    {
        return $this->username;
    }

    /** ¿El hash guardado es de Laravel (bcrypt o argon2)? Los de la app anterior (`scrypt$…`) no lo son. */
    public function hasUsableHash(): bool
    {
        return preg_match('/^\$(2y|argon2id?)\$/', (string) $this->password_hash) === 1;
    }
}
