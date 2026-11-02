<?php

namespace App\Domain\Reports\Data;

use App\Domain\Reports\Enums\CgaPersonKind;
use Carbon\CarbonImmutable;

/**
 * Saisie d'une personne de la fiche permanente (F-RPT-01), validée par la FormRequest.
 */
final readonly class CgaPersonEntry
{
    public function __construct(
        public CgaPersonKind $kind,
        public string $fullName,
        public ?string $nationality,
        public ?string $profession,
        public ?string $diplomas,
        public ?string $functionInCga,
        public ?CarbonImmutable $startDate,
        public ?string $practiceStructure,
        public ?string $practiceFunction,
        public ?int $practiceYears,
        public ?bool $cnpsDeclared,
        public bool $active,
        public ?CarbonImmutable $endDate,
    ) {}
}
