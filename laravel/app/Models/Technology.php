<?php

namespace App\Models;

use App\Models\Concerns\ForgetsSiteContent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catálogo de tecnologías: un nombre único (sin distinguir mayúsculas). Se elige de aquí, no se escribe.
 *
 * Las tablas no llevan marcas de tiempo de Laravel: `updated_at` (donde existe) lo mantiene un trigger de la base.
 */
class Technology extends Model
{
    use ForgetsSiteContent;

    public $timestamps = false;

    protected $guarded = [];

    protected $table = 'technologies';

    public function projectLinks(): HasMany
    {
        return $this->hasMany(ProjectTechnology::class);
    }

    public function toolItems(): HasMany
    {
        return $this->hasMany(ToolGroupItem::class);
    }
}
