<?php

namespace App\Domain\Members\Support;

use App\Domain\Members\Enums\RadiationInitiator;
use App\Domain\Members\Enums\RadiationReason;

/**
 * Table des radiations du PRD F-ADH-06 (config/radiations.php) : motifs permis selon l'initiateur,
 * pièce exigée, validation immédiate possible (RG-ADH-17), référence de la demande au SP-CGA.
 */
class RadiationRules
{
    public function allows(RadiationInitiator $initiator, RadiationReason $reason): bool
    {
        return $this->reason($initiator, $reason) !== null;
    }

    /**
     * Code de la pièce exigée (transfer_proof, commission_minutes, termination_letter, withdrawal_decision), ou null.
     */
    public function proof(RadiationInitiator $initiator, RadiationReason $reason): ?string
    {
        $proof = $this->reason($initiator, $reason)['proof'] ?? null;

        return is_string($proof) ? $proof : null;
    }

    public function allowsImmediateValidation(RadiationInitiator $initiator, RadiationReason $reason): bool
    {
        return (bool) ($this->reason($initiator, $reason)['immediate'] ?? false);
    }

    public function acceptsSpRequestReference(RadiationInitiator $initiator): bool
    {
        return (bool) config("radiations.initiators.{$initiator->value}.sp_request_reference", false);
    }

    /**
     * Motifs permis pour l'initiateur, dans l'ordre du PRD.
     *
     * @return list<RadiationReason>
     */
    public function reasons(RadiationInitiator $initiator): array
    {
        $reasons = config("radiations.initiators.{$initiator->value}.reasons", []);

        return array_values(array_filter(array_map(static fn (int|string $reason): ?RadiationReason => RadiationReason::tryFrom((string) $reason), array_keys(is_array($reasons) ? $reasons : []))));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function reason(RadiationInitiator $initiator, RadiationReason $reason): ?array
    {
        $definition = config("radiations.initiators.{$initiator->value}.reasons.{$reason->value}");

        return is_array($definition) ? $definition : null;
    }
}
