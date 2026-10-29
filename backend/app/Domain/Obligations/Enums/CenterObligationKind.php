<?php

namespace App\Domain\Obligations\Enums;

use App\Support\Enums\HasLabel;

/**
 * Nature d'une obligation propre du CGA (F-OBL-07).
 */
enum CenterObligationKind: string
{
    use HasLabel;

    case Tax = 'tax';
    case Cnps = 'cnps';
    case Cmu = 'cmu';
    case AgreementRenewal = 'agreement_renewal';
    case Smt = 'smt';
    case Other = 'other';
}
