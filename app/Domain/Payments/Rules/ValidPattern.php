<?php

namespace App\Domain\Payments\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Expression régulière saisie à l'écran (contrôle du n° FNE en mode manuel, RG-FNE-03) : elle doit se
 * compiler ; elle sera appliquée entre délimiteurs # avec le drapeau u.
 */
class ValidPattern implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('validation.pattern')->translate();

            return;
        }

        try {
            $compiles = preg_match('#'.str_replace('#', '\#', $value).'#u', '') !== false;
        } catch (Throwable $exception) {
            Log::info('Expression de contrôle refusée à la saisie.', ['attribute' => $attribute, 'error' => $exception->getMessage()]);
            $compiles = false;
        }

        if (! $compiles) {
            $fail('validation.pattern')->translate();
        }
    }
}
