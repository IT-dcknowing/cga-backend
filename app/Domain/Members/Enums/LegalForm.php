<?php

namespace App\Domain\Members\Enums;

use App\Support\Enums\HasLabel;

/**
 * Forme juridique (F-ADH-02).
 */
enum LegalForm: string
{
    use HasLabel;

    case Sarl = 'SARL';
    case Sarlu = 'SARLU';
    case Sas = 'SAS';
    case Sasu = 'SASU';
    case Sa = 'SA';
    case Snc = 'SNC';
    case Gie = 'GIE';
    case Coop = 'COOP';
    case Ei = 'EI';
    case Other = 'OTHER';
}
