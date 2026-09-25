<?php

namespace App\Filament\Support;

use App\Support\Format;
use Filament\AvatarProviders\Contracts\AvatarProvider;
use Illuminate\Database\Eloquent\Model;

/**
 * Avatar del administrador generado aquí mismo (un SVG con sus iniciales, como URI `data:`). El proveedor por defecto de
 * Filament pide la imagen a ui-avatars.com: enviaría a un tercero la inicial del usuario en cada pantalla del panel y la CSP
 * (`img-src 'self' data: blob:`) la bloquearía.
 */
class InitialsAvatar implements AvatarProvider
{
    public function get(Model $record): string
    {
        $name = (string) ($record->username ?? $record->getKey());
        $initials = e(Format::initials($name) ?: '?');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" fill="#09090b"/>'
            .'<text x="32" y="43" font-family="sans-serif" font-size="30" font-weight="700" text-anchor="middle" fill="#fbbf24">'
            .$initials.'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
