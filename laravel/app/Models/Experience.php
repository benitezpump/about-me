<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Empleo (kind = work) o cargo docente (kind = teaching). De ella cuelgan proyectos, materias y talleres.
 *
 * Las tablas no llevan marcas de tiempo de Laravel: `updated_at` (donde existe) lo mantiene un trigger de la base.
 */
class Experience extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $table = 'experiences';

    protected function casts(): array
    {
        return ['show_since' => 'boolean'];
    }

    /** Proyectos visibles, del más reciente al más antiguo. */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class)
            ->where('visible', true)
            ->orderByDesc('start_date')->orderBy('position')->orderBy('id');
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class)->orderByDesc('start_date')->orderBy('position')->orderBy('id');
    }

    public function workshops(): HasMany
    {
        return $this->hasMany(Workshop::class)->orderByDesc('sort_date')->orderBy('position')->orderBy('id');
    }
}
