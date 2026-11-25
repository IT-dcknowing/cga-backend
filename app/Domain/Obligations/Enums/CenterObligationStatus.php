<?php

namespace App\Domain\Obligations\Enums;

use App\Support\Enums\HasLabel;

/**
 * Statut d'une obligation propre du CGA (F-OBL-07).
 */
enum CenterObligationStatus: string
{
    use HasLabel;

    case Todo = 'todo';
    case Done = 'done';
    case ToPay = 'to_pay';
    case Paid = 'paid';
}
