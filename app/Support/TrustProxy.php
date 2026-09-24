<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * TRUST_PROXY decide en quién confiar para saber la IP del visitante, de la que dependen el límite de intentos de login y
 * el contador de visitas únicas. Aceptar `true` a ciegas es peligroso: se toma la IP MÁS A LA IZQUIERDA de X-Forwarded-For y
 * un visitante puede enviar esa cabecera con la IP que quiera para evadir el límite. Con un número (p. ej. 1) solo se confía
 * en ese número de proxies y se ignora lo que el cliente escriba antes.
 *
 *   false | (vacío) | 0     no hay proxy delante
 *   true | yes | on         confía en todos (solo si tu proxy REEMPLAZA la cabecera)
 *   1, 2, 3…                número de proxies de confianza delante de la app
 *   10.0.0.0/8,172.16.0.1   lista de IP o CIDR de los proxies de confianza
 */
final class TrustProxy
{
    /** @return bool|int|list<string> */
    public static function parse(?string $raw): bool|int|array
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return false;
        }
        $lower = strtolower($value);
        if (in_array($lower, ['true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($lower, ['false', 'no', 'off', '0'], true)) {
            return false;
        }
        if (preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        $list = array_values(array_filter(array_map('trim', explode(',', $value)), fn (string $s) => $s !== ''));
        if ($list !== [] && array_reduce($list, fn (bool $ok, string $item) => $ok && self::isIpOrCidr($item), true)) {
            return $list;
        }

        throw new InvalidArgumentException(
            "TRUST_PROXY no es válido: \"{$raw}\". Usa true, false, un número de proxies (p. ej. 1) o una lista de IP/CIDR separadas por comas.",
        );
    }

    /**
     * La IP del visitante para un número de saltos (o `true`). La cadena completa es X-Forwarded-For + la conexión directa;
     * se confía en los últimos N eslabones desde la app hacia afuera y el visitante es el que sigue. Si hay menos eslabones
     * que saltos, se toma el primero. Con `true` se toma siempre el primero (el más a la izquierda: falsificable).
     *
     * @param  list<string>  $forwardedFor  valores de X-Forwarded-For, en el orden en que llegan
     */
    public static function clientIp(string $remoteAddr, array $forwardedFor, bool|int $trust): string
    {
        $chain = [...array_values(array_filter(array_map('trim', $forwardedFor), fn ($s) => $s !== '')), $remoteAddr];

        if ($trust === true) {
            return $chain[0];
        }
        if ($trust === false || $trust === 0) {
            return $remoteAddr;
        }

        return $chain[max(0, count($chain) - 1 - $trust)];
    }

    /** ¿Es una IP (v4 o v6) válida, con o sin prefijo CIDR dentro de rango? */
    public static function isIpOrCidr(string $item): bool
    {
        $parts = explode('/', $item);
        if (count($parts) > 2) {
            return false;
        }
        $address = $parts[0];
        $isV4 = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $isV6 = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        if (! $isV4 && ! $isV6) {
            return false;
        }
        if (count($parts) === 1) {
            return true;
        }

        return preg_match('/^\d{1,3}$/', $parts[1]) === 1 && (int) $parts[1] <= ($isV4 ? 32 : 128);
    }
}
