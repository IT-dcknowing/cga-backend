<?php

namespace App\Domain\Members\Enums;

use App\Support\Enums\HasLabel;

/**
 * Élément du barème du CGA (F-PAY-01) : droit d'adhésion ou honoraires.
 */
enum FeeKind: string
{
    use HasLabel;

    case EntryFee = 'entry_fee';
    case Fees = 'fees';
}
