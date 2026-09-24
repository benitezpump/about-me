<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Solo enlaces http(s), mailto: y tel: — nunca `javascript:` ni `data:` (evita XSS por enlaces). Sin espacios. La base también
 * lo exige (restricciones *_url_check), pero aquí el mensaje llega al formulario en lugar de un error de base de datos.
 */
class SafeUrl implements ValidationRule
{
    public static function isSafe(string $url): bool
    {
        if (preg_match('/\s/', $url) === 1) {
            return false;
        }
        if (preg_match('/^(mailto|tel):\S+$/i', $url) === 1) {
            return true;
        }
        $parts = parse_url($url);

        return $parts !== false && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) && ($parts['host'] ?? '') !== '';
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && $value !== '' && ! self::isSafe($value)) {
            $fail('Escribe un enlace que empiece con https://, http://, mailto: o tel: (sin espacios).');
        }
    }
}
