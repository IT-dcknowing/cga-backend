<?php

namespace App\Domain\Payments\Enums;

use App\Support\Enums\HasLabel;

/**
 * Nature d'une somme due par l'adhérent à son CGA (F-PAY-02) : droit d'adhésion, honoraires, formation.
 */
enum ChargeKind: string
{
    use HasLabel;

    case EntryFee = 'entry_fee';
    case Fees = 'fees';
    case Training = 'training';
}
