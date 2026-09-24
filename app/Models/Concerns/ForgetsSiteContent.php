<?php

namespace App\Models\Concerns;

use App\Services\SiteContent;

/**
 * Cualquier cambio a un modelo de contenido (guardar o borrar) invalida la caché del sitio. Las escrituras que se saltan los
 * modelos (SQL directo, `truncate`, actualizaciones masivas de consulta) deben llamar a `SiteContent::forget()` ellas mismas.
 */
trait ForgetsSiteContent
{
    protected static function bootForgetsSiteContent(): void
    {
        $forget = static fn () => SiteContent::forget();
        static::saved($forget);
        static::deleted($forget);
    }
}
