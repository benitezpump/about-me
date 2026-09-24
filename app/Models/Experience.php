<?php

namespace App\Models;

use App\Models\Concerns\CoalescesNulls;
use App\Models\Concerns\ForgetsSiteContent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Empleo (kind = work) o cargo docente (kind = teaching). De ella cuelgan proyectos, materias y talleres.
 *
 * Las tablas no llevan marcas de tiempo de Laravel: `updated_at` (donde existe) lo mantiene un trigger de la base.
 */
class Experience extends Model
{
    use ForgetsSiteContent, CoalescesNulls;

    public $timestamps = false;

    protected $guarded = [];

    protected $table = 'experiences';

    /** Columnas `not null default ''`: Filament guarda un texto vacío como NULL. */
    protected array $emptyStrings = ['location'];

    protected function casts(): array
    {
        return [
            'show_since' => 'boolean',
        ];
    }

    /** Proyectos visibles, del más reciente al más antiguo (lo que muestra el sitio). */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class)
            ->where('visible', true)
            ->orderByDesc('start_date')->orderBy('position')->orderBy('id');
    }

    /** Todos los proyectos, visibles o no (para saber si la experiencia está en uso). */
    public function allProjects(): HasMany
    {
        return $this->hasMany(Project::class);
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
