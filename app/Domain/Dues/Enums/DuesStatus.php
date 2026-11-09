<?php

namespace App\Domain\Dues\Enums;

use App\Support\Enums\HasLabel;

/**
 * Statut d'une cotisation (RG-COT-04) : À jour (reste nul), Partielle (réglé entre zéro et le dû),
 * Impayée (rien de réglé après la date butoir), Due sinon.
 */
enum DuesStatus: string
{
    use HasLabel;

    case Due = 'due';
    case Partial = 'partial';
    case Paid = 'paid';
    case Unpaid = 'unpaid';
}
