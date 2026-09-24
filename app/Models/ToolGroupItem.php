<?php

namespace App\Models;

use App\Models\Concerns\ForgetsSiteContent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una tecnología del catálogo dentro de un grupo de Herramientas.
 *
 * Las tablas no llevan marcas de tiempo de Laravel: `updated_at` (donde existe) lo mantiene un trigger de la base.
 */
class ToolGroupItem extends Model
{
    use ForgetsSiteContent;

    public $timestamps = false;

    protected $guarded = [];

    protected $table = 'tool_group_items';

    public function technology(): BelongsTo
    {
        return $this->belongsTo(Technology::class);
    }
}
