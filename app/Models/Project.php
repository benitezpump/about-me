<?php

namespace App\Models;

use App\Models\Concerns\CoalescesNulls;
use App\Models\Concerns\ForgetsSiteContent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Proyecto de trabajo (cuelga de una experiencia) o propio (kind = personal).
 *
 * Las tablas no llevan marcas de tiempo de Laravel: `updated_at` (donde existe) lo mantiene un trigger de la base.
 */
class Project extends Model
{
    use ForgetsSiteContent, CoalescesNulls;

    public $timestamps = false;

    protected $guarded = [];

    protected $table = 'projects';

    /** Columnas `not null default ''`: Filament guarda un texto vacío como NULL. */
    protected array $emptyStrings = ['description', 'stack'];

    protected function casts(): array
    {
        return [
            'visible' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Un proyecto propio no cuelga de ninguna experiencia (aunque el formulario haya enviado una antes de cambiar el tipo).
        static::saving(function (self $project): void {
            if ($project->kind === 'personal') {
                $project->experience_id = null;
            }
        });
    }

    public function experience(): BelongsTo
    {
        return $this->belongsTo(Experience::class);
    }

    /** Lo que muestra el sitio (con la nota de cada tecnología). */
    public function technologies(): BelongsToMany
    {
        return $this->belongsToMany(Technology::class, 'project_technologies', 'project_id', 'technology_id')
            ->withPivot('note')
            ->orderBy('project_technologies.position')
            ->orderBy('project_technologies.id');
    }

    /** Lo que edita el panel: las filas de la tabla intermedia. */
    public function technologyLinks(): HasMany
    {
        return $this->hasMany(ProjectTechnology::class)->orderBy('position')->orderBy('id');
    }

    public function highlights(): HasMany
    {
        return $this->hasMany(ProjectHighlight::class)->orderBy('position')->orderBy('id');
    }
}
