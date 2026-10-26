<?php

namespace App\Support\Validation\Rules;

use BackedEnum;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Filtre à plusieurs valeurs séparées par des virgules (?filter[status]=radiated,closed), chacune
 * prise dans une énumération : la liste blanche d'un filtre exact (02-TRD §6.7).
 */
class EnumList implements ValidationRule
{
    /**
     * @param  class-string<BackedEnum>  $enum
     */
    public function __construct(private readonly string $enum) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $values = is_string($value) ? explode(',', $value) : [];
        $invalid = array_filter($values, fn (string $item): bool => ($this->enum)::tryFrom($item) === null);

        if ($values === [] || $invalid !== []) {
            $fail('validation.enum')->translate();
        }
    }
}
