<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Login del panel: usuario y contraseña (no correo), sin "recordarme" y con un límite de intentos propio.
 * Un solo administrador (docs/decisions/0001), así que no hay registro ni recuperación por correo.
 */
class Login extends BaseLogin
{
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('username')
            ->label('Usuario')
            ->required()
            ->autocomplete('username')
            ->autofocus();
    }

    protected function getRememberFormComponent(): Component
    {
        return Checkbox::make('remember')->hidden();
    }

    /** @return array<string, mixed> */
    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        return ['username' => $data['username'], 'password' => $data['password']];
    }

    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages(['data.username' => 'Usuario o contraseña incorrectos.']);
    }

    /**
     * Filament limita a 5 intentos por minuto y por IP. Aquí: LOGIN_MAX_ATTEMPTS (10 por omisión) cada 15 minutos por IP,
     * como la versión anterior. Cuenta cada intento, acierte o no.
     */
    protected function rateLimit($maxAttempts, $decaySeconds = 60, $method = null, $component = null): void
    {
        parent::rateLimit((int) config('security.login_max_attempts'), 15 * 60, $method ?? 'authenticate', $component);
    }
}
