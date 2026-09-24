<?php

namespace App\Models;

use App\Models\Concerns\ForgetsSiteContent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Grupo de la sección Herramientas.
 *
 * Las tablas no llevan marcas de tiempo de Laravel: `updated_at` (donde existe) lo mantiene un trigger de la base.
 */
class ToolGroup extends Model
{
    use ForgetsSiteContent;

    public $timestamps = false;

    protected $guarded = [];

    protected $table = 'tool_groups';

    protected function casts(): array
    {
        return [
            'emphasis' => 'boolean',
        ];
    }

    /** Lo que muestra el sitio (con el orden de cada elemento). */
    public function technologies(): BelongsToMany
    {
        return $this->belongsToMany(Technology::class, 'tool_group_items', 'group_id', 'technology_id')
            ->orderBy('tool_group_items.position')
            ->orderBy('tool_group_items.id');
    }

    /** Lo que edita el panel: las filas de la tabla intermedia. */
    public function items(): HasMany
    {
        return $this->hasMany(ToolGroupItem::class, 'group_id')->orderBy('position')->orderBy('id');
    }
}
