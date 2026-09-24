<?php

namespace Tests\Support;

use App\Models\AdminUser;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;

/** Un administrador de prueba y el panel ya seleccionado, para las pruebas del panel. */
trait AsAdmin
{
    protected const ADMIN_PASSWORD = 'correct horse battery staple';

    protected AdminUser $admin;

    protected function loginAsAdmin(): AdminUser
    {
        $this->admin = AdminUser::create(['username' => 'admin', 'password_hash' => Hash::make(self::ADMIN_PASSWORD)]);
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $this->admin;
    }
}
