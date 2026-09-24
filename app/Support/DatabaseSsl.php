<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Conexión cifrada y VERIFICADA a la base (Supabase). Con `DATABASE_SSL_CA` (el certificado PEM de la autoridad que firmó el
 * de la base; es público) la conexión se cifra y se verifica el servidor y su nombre (`sslmode=verify-full`). Sin él, el
 * cifrado depende de `DB_SSLMODE` y de lo que traiga la URL. Supabase usa una CA propia que no está en los almacenes del
 * sistema, por eso hace falta.
 *
 * Igual que en la versión Node: un `sslmode` dentro de la URL puede pisar la opción explícita, así que con CA se quita.
 */
final class DatabaseSsl
{
    /** El PEM completo. Acepta también una sola línea con `\n` literales (como se pega en muchos paneles). */
    public static function parseCa(?string $raw): ?string
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return null;
        }
        $pem = str_replace('\\n', "\n", $value);
        if (preg_match('/-----BEGIN CERTIFICATE-----[\s\S]+-----END CERTIFICATE-----/', $pem) !== 1) {
            throw new InvalidArgumentException(
                'DATABASE_SSL_CA debe contener el certificado completo en formato PEM (-----BEGIN CERTIFICATE----- … -----END CERTIFICATE-----).',
            );
        }

        return $pem;
    }

    /** Quita `sslmode` de la URL (con CA lo fija la configuración). El resto de la URL queda intacto. */
    public static function withoutSslMode(?string $url): ?string
    {
        if ($url === null || $url === '' || ! str_contains($url, '?')) {
            return $url;
        }
        [$base, $query] = explode('?', $url, 2);
        $kept = array_filter(explode('&', $query), fn (string $pair) => ! str_starts_with(strtolower($pair), 'sslmode='));

        return $kept === [] ? $base : $base.'?'.implode('&', $kept);
    }

    /** libpq lee la CA de un archivo: se escribe una sola vez (nombre según su contenido) con permisos de solo lectura. */
    public static function materialize(string $pem, ?string $directory = null): string
    {
        $directory ??= storage_path('framework');
        $path = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.'db-ca-'.sha1($pem).'.pem';
        if (! is_file($path)) {
            file_put_contents($path, $pem."\n", LOCK_EX);
            @chmod($path, 0600);
        }

        // libpq trata `\` como escape dentro de la cadena de conexión: en Windows la ruta debe llevar `/`.
        return str_replace('\\', '/', $path);
    }
}
