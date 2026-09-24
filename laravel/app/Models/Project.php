<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Proyecto de trabajo (cuelga de una experiencia) o propio (kind = personal).
 *
 * Las tablas no llevan marcas de tiempo de Laravel: `updated_at` (donde existe) lo mantiene un trigger de la base.
 */
class Project extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $table = 'projects';

    protected function casts(): array
    {
        return ['visible' => 'boolean'];
    }

    public function technologies(): BelongsToMany
    {
        return $this->belongsToMany(Technology::class, 'project_technologies', 'project_id', 'technology_id')
            ->withPivot('note')
            ->orderBy('project_technologies.position')
            ->orderBy('project_technologies.id');
    }

    public function highlights(): HasMany
    {
        return $this->hasMany(ProjectHighlight::class)->orderBy('position')->orderBy('id');
    }
}
