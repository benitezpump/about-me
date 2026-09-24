<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Grupo de la sección Herramientas.
 *
 * Las tablas no llevan marcas de tiempo de Laravel: `updated_at` (donde existe) lo mantiene un trigger de la base.
 */
class ToolGroup extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $table = 'tool_groups';

    protected function casts(): array
    {
        return ['emphasis' => 'boolean'];
    }

    public function technologies(): BelongsToMany
    {
        return $this->belongsToMany(Technology::class, 'tool_group_items', 'group_id', 'technology_id')
            ->orderBy('tool_group_items.position')
            ->orderBy('tool_group_items.id');
    }
}
