<?php

namespace App\Support;

/** Periodo de un proyecto o materia, ya listo para mostrar. */
final readonly class Period
{
    public function __construct(
        /** Texto libre que sustituye al periodo calculado (p. ej. '21 jul – 19 oct 2022'). */
        public ?string $label,
        public string $from,
        public ?string $to,
        /** Sin fecha de fin: se muestra como "actualidad". */
        public bool $current,
    ) {}
}
