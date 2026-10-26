<?php

namespace App\Domain\Documents\Enums;

use App\Support\Enums\HasLabel;

/**
 * Origine d'un blocage manuel de l'attestation (RG-DOC-10) : alerte d'honoraires impayés (RG-PAY-05),
 * alerte d'un adhérent au régime réel non radié (RG-ADH-09), ou initiative du gérant.
 */
enum AttestationBlockTrigger: string
{
    use HasLabel;

    case FeesOverdue = 'fees_overdue';
    case RsiOverdue = 'rsi_overdue';
    case Manual = 'manual';
}
