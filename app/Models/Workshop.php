<?php

namespace App\Models;

use App\Models\Concerns\ForgetsSiteContent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Taller o curso impartido.
 *
 * Las tablas no llevan marcas de tiempo de Laravel: `updated_at` (donde existe) lo mantiene un trigger de la base.
 */
class Workshop extends Model
{
    use ForgetsSiteContent;

    public $timestamps = false;

    protected $guarded = [];

    protected $table = 'workshops';

    public function experience(): BelongsTo
    {
        return $this->belongsTo(Experience::class);
    }
}
