<?php

namespace App\Domain\Obligations\Enums;

use App\Support\Enums\HasLabel;

/**
 * Nature d'impôt d'une obligation, colonne du rapport trimestriel (F-OBL-01, PRD M09).
 */
enum TaxNature: string
{
    use HasLabel;

    case Patente = 'patente';
    case Bic = 'bic';
    case Tva = 'tva';
    case Tse = 'tse';
    case Its = 'its';
    case Airsi = 'airsi';
    case Igr = 'igr';
    case Irvm = 'irvm';
    case Irc = 'irc';
    case Fdfp = 'fdfp';
    case Tee = 'tee';
    case Rme = 'rme';
    case Cnps = 'cnps';
    case Cmu = 'cmu';
    case Other = 'other';
}
