<?php

namespace App\Domain\Members\Enums;

use App\Support\Enums\HasLabel;

/**
 * État des pièces du dossier, calculé (RG-PJ-02) : en attente ou complètes.
 */
enum PiecesStatus: string
{
    use HasLabel;

    case Pending = 'pending';
    case Complete = 'complete';
}
