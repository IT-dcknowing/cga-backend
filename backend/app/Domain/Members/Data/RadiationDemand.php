<?php

namespace App\Domain\Members\Data;

use App\Domain\Members\Enums\RadiationInitiator;
use App\Domain\Members\Enums\RadiationReason;
use Carbon\CarbonImmutable;

/**
 * Demande de radiation saisie (F-ADH-06) : initiateur, motif et sa précision si « Autre », date
 * d'effet, observation, référence de la demande au SP-CGA (radiation initiée par le CGA).
 */
final readonly class RadiationDemand
{
    public function __construct(
        public RadiationInitiator $initiator,
        public RadiationReason $reason,
        public ?string $reasonOther,
        public CarbonImmutable $effectiveDate,
        public string $observation,
        public ?string $spRequestReference,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        $other = $validated['reason_other'] ?? null;
        $reference = $validated['sp_request_reference'] ?? null;

        return new self(
            RadiationInitiator::from((string) $validated['initiator']),
            RadiationReason::from((string) $validated['reason']),
            is_string($other) ? $other : null,
            CarbonImmutable::parse((string) $validated['effective_date']),
            (string) $validated['observation'],
            is_string($reference) ? $reference : null,
        );
    }
}
