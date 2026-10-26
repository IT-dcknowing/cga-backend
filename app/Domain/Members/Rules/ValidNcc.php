<?php

namespace App\Domain\Members\Rules;

use App\Domain\Members\Support\Ncc;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * NCC lisible une fois normalisé : 7 chiffres et une lettre (F-ADH-02).
 */
class ValidNcc implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! Ncc::isValid($value)) {
            $fail('validation.ncc')->translate();
        }
    }
}
