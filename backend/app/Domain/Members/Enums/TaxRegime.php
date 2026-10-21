<?php

namespace App\Domain\Members\Enums;

use App\Support\Enums\HasLabel;

/**
 * Régime d'imposition : RME et TEE (portefeuille CGA), RSI et RNI (régime réel, portefeuille Cabinet ou reclassement, RG-ADH-08).
 */
enum TaxRegime: string
{
    use HasLabel;

    case Rme = 'RME';
    case Tee = 'TEE';
    case Rsi = 'RSI';
    case Rni = 'RNI';
}
