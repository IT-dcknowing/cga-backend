<?php

namespace App\Domain\Imports\Enums;

use App\Support\Enums\HasLabel;

/**
 * Fichier importé (M17) : registre des CGA (F-IMP-01), portefeuille d'adhérents (F-IMP-02),
 * historique de chiffre d'affaires ou de paiements d'impôts (F-IMP-03).
 */
enum ImportType: string
{
    use HasLabel;

    case Cgas = 'cgas';
    case Members = 'members';
    case Revenues = 'revenues';
    case TaxPayments = 'tax_payments';
}
