<?php

namespace App\Domain\Obligations\Data;

use App\Domain\Obligations\Enums\CenterObligationKind;
use App\Domain\Obligations\Enums\CenterObligationStatus;
use Carbon\CarbonImmutable;

/**
 * Saisie d'une obligation du centre (F-OBL-07), validée par CenterObligationRequest.
 */
final readonly class CenterObligationEntry
{
    public function __construct(
        public string $label,
        public CenterObligationKind $kind,
        public CarbonImmutable $dueDate,
        public ?int $amount,
        public CenterObligationStatus $status,
        public ?string $notes,
    ) {}
}
