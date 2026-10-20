<?php

namespace App\Support\Validation\Rules;

use App\Domain\Users\Models\User;
use App\Support\Phones\PhoneNormalizer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Le numéro n'identifie pas déjà un autre compte (RG-ACC-01 : un compte par numéro). La comparaison
 * se fait au format E.164, quelle que soit la saisie (07 00 00 00 01, +225…).
 */
class AvailablePhone implements ValidationRule
{
    public function __construct(
        private readonly PhoneNormalizer $phones,
        private readonly ?string $exceptUserId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $phone = is_string($value) ? $this->phones->e164($value) : null;
        if ($phone === null) {
            return;
        }

        $taken = User::query()
            ->where('phone', $phone)
            ->when($this->exceptUserId !== null, fn ($query) => $query->whereKeyNot($this->exceptUserId))
            ->exists();

        if ($taken) {
            $fail('validation.phone_taken')->translate();
        }
    }
}
