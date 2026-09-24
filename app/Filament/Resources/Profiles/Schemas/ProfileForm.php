<?php

namespace App\Filament\Resources\Profiles\Schemas;

use App\Filament\Support\Fields;
use App\Support\Format;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ProfileForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('first_names')->label('Nombres')->required()->helperText('Primera línea del título grande.'),
                TextInput::make('last_names')->label('Apellidos')->required()->helperText('Segunda línea del título grande.'),
                TextInput::make('display_name')->label('Nombre corto')->required()->helperText('Aparece en la barra superior.'),
                TextInput::make('site_title')->label('Título de la página')->required()->helperText('Pestaña del navegador y vista previa al compartir.'),
                TextInput::make('headline')->label('Línea bajo el nombre')->required()->columnSpanFull(),
                Textarea::make('intro')
                    ->label('Presentación')
                    ->rows(9)->required()->maxLength(3000)
                    ->helperText('Separa los párrafos con una línea en blanco.')
                    ->formatStateUsing(fn ($state) => is_array($state) ? implode("\n\n", $state) : $state)
                    ->dehydrateStateUsing(fn ($state): array => Format::paragraphs((string) $state))
                    ->columnSpanFull(),
                TextInput::make('location')->label('Ubicación'),
                TextInput::make('cta_label')->label('Texto del botón principal'),
                Fields::url('cta_url', 'Enlace del botón principal')->columnSpanFull(),
                TextInput::make('contact_prompt')->label('Invitación a contactar')->columnSpanFull(),
                Textarea::make('meta_description')->label('Descripción para buscadores')->rows(3)->maxLength(300),
                Textarea::make('og_description')->label('Descripción al compartir el enlace')->rows(3)->maxLength(300),
                Toggle::make('show_view_count')
                    ->label('Mostrar el contador de visualizaciones en el sitio')
                    ->default(true)
                    ->helperText('Aparece en el pie de la página. Las estadísticas siguen disponibles en el Panel aunque lo ocultes.')
                    ->columnSpanFull(),
            ]);
    }
}
