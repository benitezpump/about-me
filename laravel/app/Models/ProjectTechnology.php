<?php

namespace App\Models;

use App\Models\Concerns\ForgetsSiteContent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una tecnología del catálogo dentro de un proyecto, con una nota opcional que se muestra entre paréntesis.
 *
 * Las tablas no llevan marcas de tiempo de Laravel: `updated_at` (donde existe) lo mantiene un trigger de la base.
 */
class ProjectTechnology extends Model
{
    use ForgetsSiteContent;

    public $timestamps = false;

    protected $guarded = [];

    protected $table = 'project_technologies';

    public function technology(): BelongsTo
    {
        return $this->belongsTo(Technology::class);
    }
}
