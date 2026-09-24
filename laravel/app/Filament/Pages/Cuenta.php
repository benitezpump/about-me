<?php

namespace App\Filament\Pages;

use App\Console\Commands\AdminCreate;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Cambiar la contraseña del administrador. Al cambiarla se cierran las demás sesiones abiertas (por si alguien más la tenía).
 */
class Cuenta extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?int $navigationSort = 99;

    protected static ?string $navigationLabel = 'Cuenta';

    protected static ?string $title = 'Cuenta';

    protected static ?string $slug = 'cuenta';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        $min = AdminCreate::MIN_PASSWORD_LENGTH;

        return $schema->components([
            TextInput::make('current')->label('Contraseña actual')->password()->revealable()->required()
                ->currentPassword()->autocomplete('current-password'),
            TextInput::make('next')->label('Contraseña nueva')->password()->revealable()->required()
                ->minLength($min)->different('current')->autocomplete('new-password')
                ->helperText("Mínimo {$min} caracteres. Al cambiarla se cierran tus otras sesiones."),
            TextInput::make('confirm')->label('Repite la contraseña nueva')->password()->revealable()->required()
                ->same('next')->autocomplete('new-password'),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([Actions::make([Action::make('save')->label('Cambiar contraseña')->submit('save')])]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $user = Auth::user();

        $user->forceFill(['password_hash' => Hash::make($data['next'])])->save();

        // Las demás sesiones dejan de valer: se borran de la base y la actual toma el nuevo hash.
        DB::table(config('session.table'))->where('user_id', $user->getKey())->where('id', '!=', session()->getId())->delete();
        Auth::guard()->logoutOtherDevices($data['next'], 'password_hash');

        $this->form->fill();
        Notification::make()->title('La contraseña se cambió. Se cerraron tus otras sesiones.')->success()->send();
    }
}
