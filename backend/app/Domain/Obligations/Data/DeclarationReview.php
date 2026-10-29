<?php

namespace App\Domain\Obligations\Data;

use Carbon\CarbonImmutable;

/**
 * Valeurs retenues par le CGA en confirmant une déclaration de l'adhérent (RG-OBL-09) : nulles, celles
 * de l'adhérent sont gardées. Déclaration faite : date, montant à payer (avant abattement pour la TEE) ;
 * paiement déclaré : montant, date, et montant dû s'il n'était pas encore saisi.
 */
final readonly class DeclarationReview
{
    public function __construct(
        public ?CarbonImmutable $doneOn = null,
        public ?int $amount = null,
        public ?CarbonImmutable $paidOn = null,
        public ?int $amountDue = null,
        public ?int $amountGross = null,
    ) {}
}
