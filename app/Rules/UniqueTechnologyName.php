<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/** El nombre del catálogo es único sin distinguir mayúsculas ni espacios en los extremos (índice único en la base). */
class UniqueTechnologyName implements ValidationRule
{
    public function __construct(private readonly int|string|null $ignoreId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $exists = DB::table('technologies')
            ->whereRaw('lower(btrim(name)) = lower(btrim(?))', [(string) $value])
            ->when($this->ignoreId !== null, fn ($q) => $q->where('id', '!=', $this->ignoreId))
            ->exists();

        if ($exists) {
            $fail('Ya existe una tecnología con ese nombre (sin distinguir mayúsculas).');
        }
    }
}
