<?php

namespace App\Domain\Referentials\Rules;

use App\Domain\Referentials\Models\TaxOffice;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Le CDI existe et appartient à la direction régionale saisie dans le même formulaire (F-REF-02).
 */
class TaxOfficeOfDirectorate implements DataAwareRule, ValidationRule
{
    /**
     * @var array<string, mixed>
     */
    private array $data = [];

    public function __construct(private readonly string $directorateField = 'regional_directorate_id') {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $directorate = $this->data[$this->directorateField] ?? null;

        $exists = is_string($value) && is_string($directorate) && TaxOffice::query()
            ->whereKey($value)
            ->where('regional_directorate_id', $directorate)
            ->exists();

        if (! $exists) {
            $fail('validation.tax_office_of_directorate')->translate();
        }
    }
}
